<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Auth\PasswordPolicy;
use App\Domain\User\UserRepository;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;
use App\Kernel\Security\PasswordHasher;

/**
 * `user:create-admin <email> [--name="Имя"] [--password-stdin]`: creates a confirmed super-admin, or
 * promotes an existing user. Without `--password-stdin` a random password is generated and printed once.
 * Passwords are never accepted as command-line arguments (they would end up in shell history).
 */
final class CreateAdminCommand implements Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly ?\Closure $passwordReader = null,
    ) {
    }

    public function name(): string
    {
        return 'user:create-admin';
    }

    public function description(): string
    {
        return 'Create a super-admin: user:create-admin <email> [--name=NAME] [--password-stdin]';
    }

    public function run(array $args, Output $out): int
    {
        $email = null;
        $name = 'Администратор';
        $stdin = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--name=')) {
                $name = trim(substr($arg, 7));
            } elseif ($arg === '--password-stdin') {
                $stdin = true;
            } elseif (!str_starts_with($arg, '--')) {
                $email = UserRepository::normalizeEmail($arg);
            }
        }
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $out->error('Usage: user:create-admin <email> [--name=NAME] [--password-stdin]');

            return 1;
        }
        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            $this->users->setSuperadmin($existing->id, true);
            $this->users->markVerified($existing->id);
            $out->line(sprintf('User #%d is now a super-admin.', $existing->id));

            return 0;
        }
        $generated = null;
        if ($stdin) {
            $password = $this->passwordReader !== null ? ($this->passwordReader)() : rtrim((string) fgets(STDIN), "\r\n");
            $problem = $this->policy->check($password, $email);
            if ($problem !== null) {
                $out->error($problem);

                return 1;
            }
        } else {
            $password = $generated = $this->randomPassword();
        }
        $user = $this->users->create([
            'email' => $email,
            'name' => $name === '' ? 'Администратор' : $name,
            'password_hash' => $this->hasher->hash($password),
            'email_verified_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'is_superadmin' => true,
        ]);
        if ($user === null) {
            $out->error('Could not create the user.');

            return 1;
        }
        $out->line(sprintf('Super-admin #%d created: %s', $user->id, $email));
        if ($generated !== null) {
            $out->line('Generated password (shown once, change it after signing in): ' . $generated);
        }

        return 0;
    }

    private function randomPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 24; ++$i) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
