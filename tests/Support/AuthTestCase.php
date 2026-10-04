<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Billing\Plan;
use App\Domain\Billing\PlanRepository;
use App\Domain\Billing\SubscriptionService;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Http\Response;
use App\Kernel\Mail\Mailer;
use App\Kernel\Queue\Worker;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\PasswordHasher;
use App\Support\Clock;
use OTPHP\TOTP;

/**
 * Base class for sign-in related feature tests: clean database and Redis, a controllable clock,
 * an in-memory mailer, and helpers to register users, post forms with a CSRF token and drain the mail queue.
 */
abstract class AuthTestCase extends HttpTestCase
{
    protected const PASSWORD = 'correct horse battery staple';

    protected FakeClock $clock;
    protected ArrayMailer $mailer;
    protected Connection $db;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->db->execute('DELETE FROM workspaces');
        $this->db->execute('DELETE FROM users');
        $this->db->execute('DELETE FROM jobs');
        $this->db->execute('DELETE FROM failed_jobs');
        $this->db->execute('DELETE FROM audit_log');
        // Billing leftovers: plans made by tests, notifications, the money journal.
        $this->db->execute('DELETE FROM subscriptions');
        $this->db->execute('DELETE FROM plans WHERE code LIKE \'test\\_%\'');
        $this->db->execute('DELETE FROM webhook_events');
        $this->db->execute('DELETE FROM ledger_entries');
        $this->db->execute('DELETE FROM ledger_accounts');
        TestEnv::redis()->flushDB();
        parent::setUp();
        $this->clock = new FakeClock(gmdate('Y-m-d H:i:s'));
        $this->mailer = new ArrayMailer();
        $container = $this->app->container();
        $container->instance(Clock::class, $this->clock);
        $container->instance(Mailer::class, $this->mailer);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function post(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('POST', $path, $body + ['_token' => $this->csrfToken()], $headers);
    }

    /**
     * Put a workspace on a plan for a test. A named existing plan (`free`, `start`, `pro`, `agency`) is used as it is; extra limits or features
     * make a throw-away plan (`test_*`, removed before the next test) that starts from the Pro plan.
     *
     * @param array<string, int|null> $limits limits that replace the plan's own
     * @param list<string>|null $features replaces the plan's features when given
     */
    protected function givePlan(\App\Domain\Workspace\Workspace $workspace, string $code = 'pro', array $limits = [], ?array $features = null, BillingPeriod $period = BillingPeriod::Month): Plan
    {
        $plans = $this->app->container()->get(PlanRepository::class);
        $plan = $plans->findByCode($code) ?? throw new \LogicException('Unknown plan ' . $code);
        if ($limits !== [] || $features !== null) {
            $name = 'test_' . bin2hex(random_bytes(3));
            $now = gmdate('Y-m-d H:i:s.u');
            $this->db->table('plans')->insert([
                'code' => $name,
                'name' => 'Тест',
                'sort' => 99,
                'limits_json' => json_encode($limits + $plan->limits, JSON_THROW_ON_ERROR),
                'features_json' => json_encode($features ?? $plan->features, JSON_THROW_ON_ERROR),
                'is_public' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $plan = $plans->findByCode($name) ?? throw new \LogicException('The test plan was not saved.');
        }
        $this->app->container()->get(SubscriptionService::class)->grant($workspace->id, $plan, $period);

        return $plan;
    }

    protected function createUser(string $email = 'anna@example.com', bool $verified = true, ?string $password = null, string $name = 'Анна'): User
    {
        $users = $this->app->container()->get(UserRepository::class);
        $hash = $this->app->container()->get(PasswordHasher::class)->hash($password ?? self::PASSWORD);
        $user = $users->create([
            'email' => $email,
            'name' => $name,
            'password_hash' => $hash,
            'email_verified_at' => $verified ? $this->clock->now() : null,
            'consent_version' => 'test',
        ]);
        self::assertNotNull($user);

        return $user;
    }

    protected function signIn(string $email = 'anna@example.com', ?string $password = null, bool $remember = false): Response
    {
        $body = ['email' => $email, 'password' => $password ?? self::PASSWORD];
        if ($remember) {
            $body['remember'] = '1';
        }

        return $this->post('/login', $body);
    }

    /**
     * Run the queue until it is empty, so queued emails land in the `ArrayMailer`.
     */
    protected function drainQueue(): void
    {
        $worker = $this->app->container()->get(Worker::class);
        $guard = 0;
        while ($worker->runNext() && ++$guard < 50) {
        }
    }

    /**
     * A currently valid TOTP code for the user's secret.
     */
    protected function totpCode(int $userId, int $offsetSeconds = 0): string
    {
        $row = $this->db->select('SELECT totp_secret_enc FROM users WHERE id = ?', [$userId])[0];
        $secret = $this->app->container()->get(Crypto::class)->decrypt((string) $row['totp_secret_enc']);

        if ($secret === '') {
            self::fail('the user has no TOTP secret');
        }

        return TOTP::createFromSecret($secret)->at(max(0, $this->clock->now()->getTimestamp() + $offsetSeconds));
    }

    /**
     * Enable 2FA for a user directly (without the browser flow). Returns the recovery codes.
     *
     * @return list<string>
     */
    protected function enableTwoFactor(User $user): array
    {
        $service = $this->app->container()->get(\App\Domain\Auth\TwoFactorService::class);
        $users = $this->app->container()->get(UserRepository::class);
        $service->beginSetup($user);
        $fresh = $users->find($user->id);
        self::assertNotNull($fresh);
        $codes = $service->confirmSetup($fresh, $this->totpCode($user->id));
        self::assertNotNull($codes);
        // The confirm step consumed the current time step; move on so the next code is fresh.
        $this->clock->advance(31);

        return $codes;
    }

    /**
     * @return string the body of the response with whitespace collapsed (for readable assertions)
     */
    protected function text(Response $response): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags($response->body)));
    }

    /**
     * Open the application the way a browser does after sign-in: `/app` redirects to the user's workspace.
     */
    protected function getApp(): Response
    {
        $response = $this->get('/app');
        $location = (string) $response->header('Location');

        return $response->status === 302 && str_starts_with($location, '/w/') ? $this->get($location) : $response;
    }

    /**
     * Follow one redirect.
     */
    protected function follow(Response $response): Response
    {
        self::assertContains($response->status, [301, 302, 303]);

        return $this->get(explode('#', (string) $response->header('Location'))[0]);
    }
}
