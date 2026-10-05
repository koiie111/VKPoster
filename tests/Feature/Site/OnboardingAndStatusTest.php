<?php

declare(strict_types=1);

namespace App\Tests\Feature\Site;

use App\Domain\Settings\Settings;
use App\Domain\Status\PlatformHealth;
use App\Domain\Status\PlatformStatus;
use App\Domain\Workspace\OnboardingChecklist;
use App\Integrations\Social\Contracts\Platform;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OnboardingChecklist::class)]
#[CoversClass(PlatformStatus::class)]
#[CoversClass(Settings::class)]
final class OnboardingAndStatusTest extends PostTestCase
{
    public function testChecklistTicksStepsFromTheData(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $checklist = $this->app->container()->get(OnboardingChecklist::class);

        $state = $checklist->for($context);
        self::assertSame('channel', $state['next']);
        self::assertFalse($state['complete']);

        $channel = $this->fakeChannel($workspace, $owner);
        self::assertSame('post', $checklist->for($context)['next']);

        $this->service()->saveDraft($context, null, $this->draft('Черновик', [$channel]));
        self::assertSame('schedule', $checklist->for($context)['next']);

        $this->scheduled($context, [$channel]);
        self::assertTrue($checklist->for($context)['complete']);
    }

    public function testChecklistIsHiddenOnceFinished(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        self::assertStringContainsString('Три шага до первой публикации', $this->text($this->get($this->base($workspace))));

        $channel = $this->fakeChannel($workspace, $owner);
        $this->scheduled($this->contextFor($workspace, $owner), [$channel]);
        self::assertStringNotContainsString('Три шага до первой публикации', $this->text($this->get($this->base($workspace))));
    }

    public function testPlatformIsDegradedOnlyWhenSeveralChannelsFailOnThePlatformSide(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $status = $this->app->container()->get(PlatformStatus::class);
        $redis = $this->app->container()->get(\Redis::class);
        $channels = [$this->makeChannelRow($workspace->id, 'a'), $this->makeChannelRow($workspace->id, 'b')];

        // Three temporary failures on ONE channel: that is the channel's problem, not the network's.
        foreach ([1, 2, 3] as $n) {
            $this->attempt($channels[0], 'temporary');
        }
        $redis->del('status:platforms:v1');
        self::assertSame([], $this->problemPlatforms($status));

        $this->attempt($channels[1], 'temporary');
        $redis->del('status:platforms:v1');
        self::assertContains('telegram', $this->problemPlatforms($status));
        self::assertNotSame([], $status->problemsFor($workspace->id));

        // Successes outweigh the failures: back to normal.
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->attempt($channels[1], 'sent');
        }
        $redis->del('status:platforms:v1');
        self::assertSame([], $this->problemPlatforms($status));
    }

    public function testOwnerSwitchAndNoticeOverrideTheComputedState(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $settings = $this->app->container()->get(Settings::class);
        $status = $this->app->container()->get(PlatformStatus::class);
        $settings->set('status.notices', ['vk' => 'ВКонтакте: плановые работы до 18:00.'], null);
        $status->refresh();
        $vk = array_values(array_filter($status->all(), static fn (PlatformHealth $h): bool => $h->platform === Platform::Vk));
        self::assertSame(PlatformHealth::MAINTENANCE, $vk[0]->state);
        self::assertSame('ВКонтакте: плановые работы до 18:00.', $vk[0]->notice);

        $settings->set('platforms.off', ['max'], null);
        $status->refresh();
        $max = array_values(array_filter($status->all(), static fn (PlatformHealth $h): bool => $h->platform === Platform::Max));
        self::assertStringContainsString('временно отключён', (string) $max[0]->notice);
        self::assertFalse($this->app->container()->get(\App\Integrations\Social\PlatformRegistry::class)->isEnabled(Platform::Max));

        $settings->forget('platforms.off');
        $settings->forget('status.notices');
        $status->refresh();
        self::assertSame([], $status->problems());
        self::assertSame(1, $workspace->id > 0 ? 1 : 0);
    }

    public function testBannerAppearsInTheApplicationForAffectedWorkspacesOnly(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $settings = $this->app->container()->get(Settings::class);
        $settings->set('status.notices', ['telegram' => 'Telegram: сбой, чиним.'], null);
        $this->app->container()->get(PlatformStatus::class)->refresh();

        self::assertStringNotContainsString('Telegram: сбой, чиним.', $this->text($this->get($this->base($workspace))));
        $this->makeChannelRow($workspace->id, 'x');
        self::assertStringContainsString('Telegram: сбой, чиним.', $this->text($this->get($this->base($workspace))));

        $settings->forget('status.notices');
        $this->app->container()->get(PlatformStatus::class)->refresh();
        self::assertStringContainsString('Состояние соцсетей', $this->text($this->get('/status')));
    }

    /**
     * @return list<string>
     */
    private function problemPlatforms(PlatformStatus $status): array
    {
        return array_map(static fn (PlatformHealth $h): string => $h->platform->value, $status->problems());
    }

    private function makeChannelRow(int $workspaceId, string $ext): int
    {
        $now = gmdate('Y-m-d H:i:s.u');

        return (int) $this->db->table('channels')->insert([
            'public_id' => (string) new \Symfony\Component\Uid\Ulid(), 'workspace_id' => $workspaceId, 'platform' => 'telegram', 'external_id' => $ext,
            'title' => 'Канал ' . $ext, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function attempt(int $channelId, string $outcome): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $workspaceId = (int) $this->db->select('SELECT workspace_id FROM channels WHERE id = ?', [$channelId])[0]['workspace_id'];
        $postId = (int) $this->db->table('posts')->insert(['public_id' => (string) new \Symfony\Component\Uid\Ulid(), 'workspace_id' => $workspaceId, 'status' => 'published', 'base_text' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        $variantId = (int) $this->db->table('post_variants')->insert(['post_id' => $postId, 'workspace_id' => $workspaceId, 'channel_id' => $channelId, 'platform' => 'telegram', 'channel_name' => 'k']);
        $pubId = (int) $this->db->table('publications')->insert([
            'public_id' => (string) new \Symfony\Component\Uid\Ulid(), 'workspace_id' => $workspaceId, 'post_id' => $postId, 'variant_id' => $variantId, 'channel_id' => $channelId,
            'status' => 'queued', 'due_at' => $now, 'run_at' => $now, 'idempotency_key' => bin2hex(random_bytes(32)), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('publication_attempts')->insert(['publication_id' => $pubId, 'workspace_id' => $workspaceId, 'attempt' => 1, 'outcome' => $outcome, 'started_at' => $now, 'finished_at' => $now]);
    }
}
