<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;
use OTPHP\TOTP;

/**
 * TOTP two-factor authentication (RFC 6238: SHA-1, 6 digits, 30 s, compatible with Google Authenticator
 * and Yandex Key) plus one-time recovery codes.
 *
 * - The secret is stored encrypted (`Crypto`); it is "pending" until the user proves they can produce a code.
 * - A code is accepted within one step before or after the current one, and each step only once
 *   (`UserRepository::claimTotpStep`), so an intercepted code cannot be replayed.
 * - Recovery codes (10, 128-bit hex grouped for readability) are shown once and stored as SHA-256 hashes; each works once.
 */
final class TwoFactorService
{
    public const STEP = 30;
    public const RECOVERY_COUNT = 10;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Connection $db,
        private readonly Crypto $crypto,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Secret for the setup screen. Reuses the pending one so that reloading the page keeps the same QR code.
     */
    public function beginSetup(User $user): string
    {
        if ($user->totpSecretEnc !== null && !$user->hasTwoFactor()) {
            return $this->crypto->decrypt($user->totpSecretEnc);
        }
        $secret = TOTP::generate()->getSecret();
        $this->users->setPendingTotp($user->id, $this->crypto->encrypt($secret));

        return $secret;
    }

    /**
     * @param non-empty-string $secret
     * @param non-empty-string $account
     * @param non-empty-string $issuer
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($account);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    /**
     * Finish setup: check the first code and switch 2FA on.
     *
     * @return list<string>|null the recovery codes (shown once), or null when the code is wrong
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        if ($user->totpSecretEnc === null || $user->hasTwoFactor()) {
            return null;
        }
        $step = $this->matchStep($this->crypto->decrypt($user->totpSecretEnc), $code);
        if ($step === null) {
            return null;
        }
        $this->users->enableTotp($user->id, $step);

        return $this->generateRecoveryCodes($user->id);
    }

    /**
     * Check a sign-in code. True only the first time a valid code of a step is presented.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->hasTwoFactor() || $user->totpSecretEnc === null) {
            return false;
        }
        $step = $this->matchStep($this->crypto->decrypt($user->totpSecretEnc), $code);

        return $step !== null && $this->users->claimTotpStep($user->id, $step);
    }

    /**
     * Use a recovery code. True once per code.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecovery($code);
        if ($normalized === null) {
            return false;
        }

        $digest = hash('sha256', $normalized);
        $now = DbTime::format($this->clock->now());
        if ($this->db->execute('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$now, $user->id, $digest]) === 1) {
            return true;
        }
        // Migration 45 salted existing short-code digests. At most ten old codes per user.
        $old = $this->db->select("SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL AND code_hash LIKE '\$argon2id\$%' LIMIT 10", [$user->id]);
        foreach ($old as $row) {
            if (password_verify($digest, (string) $row['code_hash'])) {
                return $this->db->execute('UPDATE recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, (int) $row['id']]) === 1;
            }
        }

        return false;
    }

    /**
     * A TOTP code or a recovery code, whichever the user typed.
     */
    public function verifyAny(User $user, string $input): bool
    {
        $digits = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{6}$/', $digits) === 1) {
            return $this->verifyCode($user, $digits);
        }

        return $this->useRecoveryCode($user, $input);
    }

    /**
     * @return list<string> new codes (shown once); old ones stop working
     */
    public function generateRecoveryCodes(int $userId): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; ++$i) {
            $codes[] = $this->randomCode();
        }
        $now = DbTime::format($this->clock->now());
        $this->db->transaction(function (Connection $db) use ($userId, $codes, $now): void {
            $db->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
            foreach ($codes as $code) {
                $db->table('recovery_codes')->insert([
                    'user_id' => $userId,
                    'code_hash' => hash('sha256', str_replace('-', '', $code)),
                    'created_at' => $now,
                ]);
            }
        });

        return $codes;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return $this->db->table('recovery_codes')->where('user_id', '=', $userId)->whereNull('used_at')->count();
    }

    public function disable(User $user): void
    {
        $this->db->transaction(function (Connection $db) use ($user): void {
            $db->execute('DELETE FROM recovery_codes WHERE user_id = ?', [$user->id]);
            $this->users->clearTotp($user->id);
        });
    }

    /**
     * Time step whose code equals `$code` (current step or its neighbours), or null.
     */
    private function matchStep(string $secret, string $code): ?int
    {
        if ($secret === '') {
            return null;
        }
        $digits = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $digits) !== 1) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret);
        $current = intdiv($this->clock->now()->getTimestamp(), self::STEP);
        $matched = null;
        foreach ([$current - 1, $current, $current + 1] as $step) {
            // Compare every candidate (no early exit) so timing does not reveal which step matched.
            if (hash_equals($totp->at(max(0, $step * self::STEP)), $digits)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    private function randomCode(): string
    {
        return implode('-', str_split(bin2hex(random_bytes(16)), 8));
    }

    private function normalizeRecovery(string $code): ?string
    {
        $normalized = strtolower(str_replace(['-', ' '], '', trim($code)));
        // New 128-bit codes or legacy codes whose stored verifier migration 45 has strengthened.
        return preg_match('/^(?:[a-f0-9]{32}|[abcdefghjkmnpqrstuvwxyz23456789]{10})$/D', $normalized) === 1 ? $normalized : null;
    }
}
