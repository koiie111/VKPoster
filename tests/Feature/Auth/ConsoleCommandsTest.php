<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\AuthMaintenance;
use App\Domain\Auth\PasswordPolicy;
use App\Domain\Auth\TokenType;
use App\Domain\User\UserRepository;
use App\Kernel\Console\Command\AuthPruneCommand;
use App\Kernel\Console\Command\CreateAdminCommand;
use App\Kernel\Console\Output;
use App\Kernel\Security\PasswordHasher;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `user:create-admin` and `auth:prune`.
 */
#[CoversClass(CreateAdminCommand::class)]
#[CoversClass(AuthPruneCommand::class)]
#[CoversClass(AuthMaintenance::class)]
final class ConsoleCommandsTest extends AuthTestCase
{
    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function createAdmin(array $args, ?string $stdinPassword = null): array
    {
        $container = $this->app->container();
        $command = new CreateAdminCommand(
            $container->get(UserRepository::class),
            $container->get(PasswordHasher::class),
            $container->get(PasswordPolicy::class),
            $stdinPassword === null ? null : static fn (): string => $stdinPassword,
        );
        $out = new Output();
        $code = $command->run($args, $out);

        return [$code, $out->contents()];
    }

    public function testCreatesAConfirmedSuperadminWithAGeneratedPasswordShownOnce(): void
    {
        [$code, $output] = $this->createAdmin(['Owner@Example.com', '--name=Владелец']);

        self::assertSame(0, $code);
        $row = $this->db->select('SELECT * FROM users WHERE email = ?', ['owner@example.com'])[0];
        self::assertSame(1, (int) $row['is_superadmin']);
        self::assertNotNull($row['email_verified_at']);
        self::assertSame('Владелец', $row['name']);
        self::assertStringContainsString('Generated password', $output);
        $generated = trim(substr($output, (int) strrpos($output, ': ') + 2));
        self::assertSame(24, strlen($generated));
        self::assertTrue(password_verify($generated, (string) $row['password_hash']));
    }

    public function testUsesAPasswordFromStdinAfterCheckingThePolicy(): void
    {
        [$weakCode, $weakOutput] = $this->createAdmin(['a@example.com', '--password-stdin'], 'password123');
        self::assertSame(1, $weakCode);
        self::assertStringContainsString('распространён', $weakOutput);
        self::assertSame([], $this->db->select('SELECT 1 FROM users'));

        [$code, $output] = $this->createAdmin(['a@example.com', '--password-stdin'], 'a-long-unusual-passphrase');
        self::assertSame(0, $code);
        self::assertStringNotContainsString('Generated password', $output);
        self::assertStringNotContainsString('unusual', $output);
    }

    public function testPromotesAnExistingUser(): void
    {
        $user = $this->createUser('boss@example.com', verified: false);

        [$code] = $this->createAdmin(['boss@example.com']);

        self::assertSame(0, $code);
        $row = $this->db->select('SELECT is_superadmin, email_verified_at FROM users WHERE id = ?', [$user->id])[0];
        self::assertSame(1, (int) $row['is_superadmin']);
        self::assertNotNull($row['email_verified_at']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM users')[0]['c']);
    }

    public function testRejectsABadEmail(): void
    {
        [$code, $output] = $this->createAdmin(['not-an-email']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Usage', $output);
    }

    public function testPruneDeletesOnlyOldFinishedRows(): void
    {
        $user = $this->createUser();
        $container = $this->app->container();
        $tokens = $container->get(\App\Domain\Auth\AuthTokens::class);
        $old = $tokens->issue($user->id, TokenType::PasswordReset, 60);
        $recent = $tokens->issue($user->id, TokenType::EmailVerify, 86400 * 60);
        $this->db->execute('INSERT INTO login_attempts (user_id, outcome, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?)', [$user->id, 'success', '203.0.113.1', '', gmdate('Y-m-d H:i:s', time() - 86400 * 100)]);
        $this->db->execute('INSERT INTO login_attempts (user_id, outcome, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?)', [$user->id, 'success', '203.0.113.1', '', gmdate('Y-m-d H:i:s')]);
        $this->clock->set(gmdate('Y-m-d H:i:s', time() + 86400 * 8));

        $out = new Output();
        $code = $container->get(AuthPruneCommand::class)->run([], $out);

        self::assertSame(0, $code);
        self::assertStringContainsString('1 tokens', $out->contents());
        self::assertNull($tokens->peek($old, TokenType::PasswordReset));
        self::assertNotNull($tokens->peek($recent, TokenType::EmailVerify));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM login_attempts')[0]['c']);
    }

    public function testPruneIsScheduledDaily(): void
    {
        $schedule = $this->app->container()->get(\App\Kernel\Queue\Schedule::class);
        $names = array_map(static fn (\App\Kernel\Queue\ScheduledTask $t): string => $t->name, $schedule->tasks());

        self::assertContains('auth-prune', $names);
    }
}
