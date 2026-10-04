<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Domain\Auth\AuthTokens;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\TokenType;
use App\Domain\User\UserRepository;
use App\Kernel\Database\Connection;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Token table and login throttle against real MySQL and Redis.
 */
#[CoversClass(AuthTokens::class)]
#[CoversClass(LoginThrottle::class)]
#[CoversClass(UserRepository::class)]
final class AuthStoresTest extends TestCase
{
    private Connection $db;
    private FakeClock $clock;
    private UserRepository $users;
    private AuthTokens $tokens;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = TestEnv::connection();
        $this->db->execute('DELETE FROM users');
        TestEnv::redis()->flushDB();
        $this->clock = new FakeClock(gmdate('Y-m-d H:i:s'));
        $this->users = new UserRepository($this->db, $this->clock);
        $this->tokens = new AuthTokens($this->db, $this->clock);
        $user = $this->users->create(['email' => 'a@example.com', 'name' => 'A', 'password_hash' => null]);
        self::assertNotNull($user);
        $this->userId = $user->id;
    }

    public function testTokenIsStoredHashedAndUsableOnce(): void
    {
        $raw = $this->tokens->issue($this->userId, TokenType::PasswordReset, 3600, ['k' => 'v']);

        $row = $this->db->select('SELECT token_hash FROM auth_tokens')[0];
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        self::assertMatchesRegularExpression(AuthTokens::TOKEN_PATTERN, $raw);

        $token = $this->tokens->consume($raw, TokenType::PasswordReset);
        self::assertNotNull($token);
        self::assertSame($this->userId, $token->userId);
        self::assertSame(['k' => 'v'], $token->payload);
        self::assertNull($this->tokens->consume($raw, TokenType::PasswordReset));
    }

    public function testTypeMismatchExpiryAndGarbageAreRejected(): void
    {
        $raw = $this->tokens->issue($this->userId, TokenType::EmailVerify, 60);

        self::assertNull($this->tokens->peek($raw, TokenType::PasswordReset));
        self::assertNull($this->tokens->peek('short', TokenType::EmailVerify));
        self::assertNull($this->tokens->peek(str_repeat('a', 43), TokenType::EmailVerify));
        $this->clock->advance(61);
        self::assertNull($this->tokens->peek($raw, TokenType::EmailVerify));
    }

    public function testIssuingAgainRetiresTheOlderTokenOfThatTypeOnly(): void
    {
        $first = $this->tokens->issue($this->userId, TokenType::PasswordReset, 3600);
        $other = $this->tokens->issue($this->userId, TokenType::EmailVerify, 3600);
        $second = $this->tokens->issue($this->userId, TokenType::PasswordReset, 3600);

        self::assertNull($this->tokens->peek($first, TokenType::PasswordReset));
        self::assertNotNull($this->tokens->peek($second, TokenType::PasswordReset));
        self::assertNotNull($this->tokens->peek($other, TokenType::EmailVerify));
    }

    public function testPruneDeletesOldFinishedTokens(): void
    {
        $used = $this->tokens->issue($this->userId, TokenType::PasswordReset, 3600);
        $this->tokens->consume($used, TokenType::PasswordReset);
        $live = $this->tokens->issue($this->userId, TokenType::EmailVerify, 86400 * 30);
        $this->clock->advance(86400 * 8);

        self::assertSame(1, $this->tokens->prune());
        self::assertNotNull($this->tokens->peek($live, TokenType::EmailVerify));
    }

    public function testTokensDieWithTheirUser(): void
    {
        $this->tokens->issue($this->userId, TokenType::PasswordReset, 3600);

        $this->db->execute('DELETE FROM users WHERE id = ?', [$this->userId]);

        self::assertSame([], $this->db->select('SELECT 1 FROM auth_tokens'));
    }

    public function testDuplicateEmailsAreRefusedCaseInsensitively(): void
    {
        self::assertNull($this->users->create(['email' => 'A@Example.com', 'name' => 'Dup', 'password_hash' => null]));
        self::assertNotNull($this->users->findByEmail('  A@EXAMPLE.COM '));
    }

    public function testTotpStepCanBeClaimedOnlyOnceAndOnlyForward(): void
    {
        self::assertTrue($this->users->claimTotpStep($this->userId, 100));
        self::assertFalse($this->users->claimTotpStep($this->userId, 100));
        self::assertFalse($this->users->claimTotpStep($this->userId, 99));
        self::assertTrue($this->users->claimTotpStep($this->userId, 101));
    }

    public function testThrottleLocksFromTheFifthFailureAndDoublesTheDelay(): void
    {
        $throttle = new LoginThrottle(TestEnv::redis(), $this->clock);

        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame($i, $throttle->fail('User@Example.com'));
            self::assertSame(0, $throttle->retryAfter('user@example.com'), 'four free attempts');
        }
        $throttle->fail('user@example.com');
        self::assertSame(30, $throttle->retryAfter('USER@example.com'), 'emails are normalised');
        $this->clock->advance(31);
        self::assertSame(0, $throttle->retryAfter('user@example.com'));
        $throttle->fail('user@example.com');
        self::assertSame(60, $throttle->retryAfter('user@example.com'));
        $throttle->fail('user@example.com');
        self::assertSame(120, $throttle->retryAfter('user@example.com'));
    }

    public function testThrottleDelayIsCappedAndClearedOnSuccess(): void
    {
        $throttle = new LoginThrottle(TestEnv::redis(), $this->clock);
        for ($i = 0; $i < 20; ++$i) {
            $throttle->fail('x@example.com');
        }

        self::assertSame(900, $throttle->retryAfter('x@example.com'));
        $throttle->clear('x@example.com');
        self::assertSame(0, $throttle->retryAfter('x@example.com'));
        self::assertSame(1, $throttle->fail('x@example.com'));
    }

    public function testThrottleIsPerAccount(): void
    {
        $throttle = new LoginThrottle(TestEnv::redis(), $this->clock);
        for ($i = 0; $i < 6; ++$i) {
            $throttle->fail('a@example.com');
        }

        self::assertGreaterThan(0, $throttle->retryAfter('a@example.com'));
        self::assertSame(0, $throttle->retryAfter('b@example.com'));
    }
}
