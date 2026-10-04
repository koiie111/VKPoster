<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Channel\ChannelMode;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostService;
use App\Domain\Post\PublicationScheduler;
use App\Domain\Post\PublishJob;
use App\Domain\User\UserRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Domain\Workspace\WorkspaceService;
use App\Domain\Post\VariantInput;
use App\Integrations\Social\Contracts\Platform;
use App\Integrations\Social\Fake\FakeAdapter;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Config;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Container;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Worker;
use App\Kernel\Security\PasswordHasher;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * `bench:publish --fake [--count=200] [--spread=30]`: the publication delay benchmark of the product promise ("within 60 seconds,
 * p95"). It plans `count` posts to the test network with times spread over the next `spread` seconds in a throw-away workspace, runs
 * the scheduler and the queue in this process in real time, and prints the delay from the planned moment to "sent" (p50, p95, max).
 * Exit code 1 when anything was not published or p95 is 60 seconds or more. Development only; it removes what it created.
 */
final class BenchPublishCommand implements Command
{
    private const EMAIL = 'bench@ezposter.local';

    public function __construct(private readonly Container $container, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'bench:publish';
    }

    public function description(): string
    {
        return 'Benchmark publication delay on the test network (--fake [--count=200] [--spread=30])';
    }

    public function run(array $args, Output $out): int
    {
        if ($this->config->isProduction()) {
            $out->error('The benchmark is a development tool and refuses to run in production.');

            return 1;
        }
        if (!in_array('--fake', $args, true)) {
            $out->error('Pass --fake: the benchmark only talks to the test network, never to a real one.');

            return 1;
        }
        $count = 200;
        $spread = 30;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--count=')) {
                $count = max(1, min(2000, (int) substr($arg, 8)));
            } elseif (str_starts_with($arg, '--spread=')) {
                $spread = max(1, min(600, (int) substr($arg, 9)));
            }
        }

        // The test network must be switched on whatever PLATFORMS_ENABLED says; everything that needs the registry is built after this.
        $fake = new FakeAdapter();
        $this->container->instance(FakeAdapter::class, $fake);
        $this->container->instance(PlatformRegistry::class, new PlatformRegistry([$fake], ['fake'], true));

        $db = $this->container->get(Connection::class);
        $clock = $this->container->get(Clock::class);
        $this->cleanup($db);
        $context = $this->workspace();
        $channels = $this->container->get(ChannelRepository::class);
        $channel = $channels->connect($context, Platform::Fake, 'fake-bench', ChannelMode::SharedBot, 'Bench', null, 'channel', null, ['rights' => ['post' => true, 'edit' => true, 'delete' => true, 'pin' => true]], $context->userId)['channel'];
        $service = $this->container->get(PostService::class);

        $out->line(sprintf('Planning %d posts over %d seconds…', $count, $spread));
        $start = $clock->now()->modify('+3 seconds');
        for ($i = 0; $i < $count; ++$i) {
            $at = $start->modify(sprintf('+%d seconds', intdiv($i * $spread, $count)));
            $service->schedule($context, null, new PostDraft('Bench post ' . $i, [], new PostOptions(), false, [new VariantInput($channel->publicId)]), $at);
        }

        $scheduler = $this->container->get(PublicationScheduler::class);
        $worker = $this->container->get(Worker::class);
        $deadline = $clock->now()->modify(sprintf('+%d seconds', $spread + 120));
        $out->line('Running the scheduler and the worker…');
        $lastTick = 0;
        while ($clock->now() < $deadline) {
            if (time() - $lastTick >= 1) {
                $scheduler->enqueueDue();
                $lastTick = time();
            }
            if (!$worker->runNext(PublishJob::QUEUE, 'bench')) {
                usleep(50_000);
            }
            $open = (int) ($db->select('SELECT COUNT(*) AS n FROM publications WHERE workspace_id = ? AND status IN (\'queued\', \'sending\')', [$context->workspaceId])[0]['n'] ?? 0);
            if ($open === 0) {
                break;
            }
        }

        $rows = $db->select('SELECT status, due_at, sent_at FROM publications WHERE workspace_id = ?', [$context->workspaceId]);
        $delays = [];
        $notSent = 0;
        foreach ($rows as $row) {
            if ($row['status'] !== 'sent' || $row['sent_at'] === null) {
                ++$notSent;
                continue;
            }
            $delays[] = (float) DbTime::parse($row['sent_at'])?->format('U.u') - (float) DbTime::parse($row['due_at'])?->format('U.u');
        }
        sort($delays);
        $at = static fn (float $q): float => $delays === [] ? 0.0 : $delays[(int) min(count($delays) - 1, max(0, (int) ceil($q * count($delays)) - 1))];
        $p95 = $at(0.95);
        $out->line(sprintf('Published %d of %d (not published: %d).', count($delays), count($rows), $notSent));
        $out->line(sprintf('Delay from the planned time: p50 %.2f s, p95 %.2f s, max %.2f s.', $at(0.5), $p95, $delays === [] ? 0.0 : end($delays)));
        $this->cleanup($db);

        $ok = $notSent === 0 && $p95 < 60.0;
        $out->line($ok ? 'OK: p95 is under 60 seconds.' : 'FAILED: something was not published or p95 is 60 seconds or more.');

        return $ok ? 0 : 1;
    }

    private function workspace(): WorkspaceContext
    {
        $users = $this->container->get(UserRepository::class);
        $user = $users->create([
            'email' => self::EMAIL,
            'name' => 'Bench',
            'password_hash' => $this->container->get(PasswordHasher::class)->hash(bin2hex(random_bytes(16))),
            'email_verified_at' => $this->container->get(Clock::class)->now(),
            'consent_version' => 'bench',
        ]) ?? throw new \RuntimeException('Could not create the benchmark user.');
        $workspace = $this->container->get(WorkspaceService::class)->createPersonal($user);
        $repository = $this->container->get(WorkspaceRepository::class);
        $membership = $repository->membership($workspace->id, $user->id) ?? throw new \RuntimeException('No membership.');

        return WorkspaceContext::from($workspace, $membership);
    }

    /**
     * Remove the benchmark user (workspace, posts, publications and channels go with it by cascade) and leftover jobs.
     */
    private function cleanup(Connection $db): void
    {
        $user = $this->container->get(UserRepository::class)->findByEmail(self::EMAIL);
        if ($user === null) {
            return;
        }
        foreach ($db->select('SELECT id FROM workspaces WHERE owner_id = ?', [$user->id]) as $row) {
            $db->execute('DELETE FROM workspaces WHERE id = ?', [(int) $row['id']]);
        }
        $db->execute('DELETE FROM jobs WHERE queue = ?', [PublishJob::QUEUE]);
        $this->container->get(UserRepository::class)->delete($user->id);
    }
}
