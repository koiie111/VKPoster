<?php

declare(strict_types=1);

namespace App\Tests\Support;

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
        $this->db->execute('DELETE FROM users');
        $this->db->execute('DELETE FROM jobs');
        $this->db->execute('DELETE FROM failed_jobs');
        $this->db->execute('DELETE FROM audit_log');
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
     * Follow one redirect.
     */
    protected function follow(Response $response): Response
    {
        self::assertContains($response->status, [301, 302, 303]);

        return $this->get(explode('#', (string) $response->header('Location'))[0]);
    }
}
