<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\TwoFactorService;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * TOTP setup, second-step sign-in, replay protection, recovery codes, disabling.
 */
#[CoversClass(TwoFactorService::class)]
final class TwoFactorTest extends AuthTestCase
{
    /**
     * @return list<string> recovery codes
     */
    private function enableThroughTheBrowser(int $userId): array
    {
        self::assertSame('/account/2fa/setup', $this->post('/account/2fa/start')->header('Location'));
        $setup = $this->get('/account/2fa/setup');
        self::assertSame(200, $setup->status);
        self::assertStringContainsString('/account/2fa/qr.svg', $setup->body);

        $confirm = $this->post('/account/2fa/confirm', ['code' => $this->totpCode($userId)]);
        self::assertSame(200, $confirm->status);
        self::assertStringContainsString('Покажем их только один раз', $confirm->body);
        preg_match_all('/<li class="rounded-ctl[^>]*>([a-z0-9]{5}-[a-z0-9]{5})<\/li>/', $confirm->body, $m);
        self::assertCount(10, $m[1]);

        return $m[1];
    }

    public function testEnableWithQrCodeThenSignInNeedsTheSecondStep(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $codes = $this->enableThroughTheBrowser($user->id);
        $this->clock->advance(31);
        $this->post('/logout');

        // Step 1 only brings the visitor to the code form, not into the app.
        $first = $this->signIn();
        self::assertSame('/login/2fa', $first->header('Location'));
        self::assertSame('/login', $this->get('/app')->header('Location'));

        $second = $this->post('/login/2fa', ['code' => $this->totpCode($user->id)]);
        self::assertSame('/app', $second->header('Location'));
        self::assertSame(200, $this->get('/app')->status);
        self::assertCount(10, $codes);
        $this->drainQueue();
        self::assertNotNull(array_values(array_filter($this->mailer->to('anna@example.com'), static fn ($m): bool => $m->subject === 'Двухфакторная защита включена'))[0] ?? null);
    }

    public function testQrCodeIsAnSvgForTheOwnerOnly(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->post('/account/2fa/start');

        $qr = $this->get('/account/2fa/qr.svg');

        self::assertSame(200, $qr->status);
        self::assertStringStartsWith('image/svg+xml', (string) $qr->header('Content-Type'));
        self::assertStringContainsString('<svg', $qr->body);
        self::assertSame('no-store', $qr->header('Cache-Control'));
        $guest = $this->useBrowser();
        self::assertSame('/login', $this->get('/account/2fa/qr.svg')->header('Location'));
        $this->useBrowser($guest);
        self::assertGreaterThan(0, $user->id);
    }

    public function testWrongCodeDuringSetupKeepsTwoFactorOff(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->post('/account/2fa/start');

        $response = $this->post('/account/2fa/confirm', ['code' => '000000']);

        self::assertSame('/account/2fa/setup', $response->header('Location'));
        self::assertStringContainsString('Код не подошёл', $this->follow($response)->body);
        self::assertNull($this->db->select('SELECT totp_enabled_at FROM users WHERE id = ?', [$user->id])[0]['totp_enabled_at']);
        self::assertSame([], $this->db->select('SELECT 1 FROM recovery_codes'));
    }

    public function testSecretIsStoredEncrypted(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->post('/account/2fa/start');

        $stored = (string) $this->db->select('SELECT totp_secret_enc FROM users WHERE id = ?', [$user->id])[0]['totp_secret_enc'];

        self::assertStringStartsWith('v1:', $stored);
        self::assertDoesNotMatchRegularExpression('/^[A-Z2-7]{16,}$/', $stored);
    }

    public function testReloadingSetupKeepsTheSameSecret(): void
    {
        $user = $this->createUser();
        $this->signIn();
        $this->post('/account/2fa/start');
        $first = $this->db->select('SELECT totp_secret_enc FROM users WHERE id = ?', [$user->id])[0]['totp_secret_enc'];

        $this->post('/account/2fa/start');

        self::assertSame($first, $this->db->select('SELECT totp_secret_enc FROM users WHERE id = ?', [$user->id])[0]['totp_secret_enc']);
    }

    public function testWrongSecondFactorKeepsTheVisitorOut(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn();

        $wrong = $this->post('/login/2fa', ['code' => '123456']);

        self::assertSame('/login/2fa', $wrong->header('Location'));
        self::assertStringContainsString('Код не подошёл', $this->follow($wrong)->body);
        self::assertSame('/login', $this->get('/app')->header('Location'));
        self::assertSame('bad_2fa', $this->db->select('SELECT outcome FROM login_attempts ORDER BY id DESC LIMIT 1')[0]['outcome']);
    }

    public function testACodeCannotBeUsedTwiceInTheSameWindow(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn();
        $code = $this->totpCode($user->id);
        self::assertSame('/app', $this->post('/login/2fa', ['code' => $code])->header('Location'));
        $this->post('/logout');

        $this->signIn();
        $replay = $this->post('/login/2fa', ['code' => $code]);

        self::assertSame('/login/2fa', $replay->header('Location'), 'a captured code must not work again');
        $this->clock->advance(30);
        self::assertSame('/app', $this->post('/login/2fa', ['code' => $this->totpCode($user->id)])->header('Location'));
    }

    public function testCodesFromThePreviousStepAreAcceptedButNotOlderOnes(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->clock->advance(30); // the setup used one step; the previous step must now be newer than that
        $this->signIn();

        $stale = $this->totpCode($user->id, -90);
        self::assertSame('/login/2fa', $this->post('/login/2fa', ['code' => $stale])->header('Location'));
        self::assertSame('/app', $this->post('/login/2fa', ['code' => $this->totpCode($user->id, -30)])->header('Location'));
    }

    public function testSecondFactorAttemptsAreLimited(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn();
        for ($i = 0; $i < 5; ++$i) {
            $this->post('/login/2fa', ['code' => '11111' . $i]);
        }

        $blocked = $this->post('/login/2fa', ['code' => $this->totpCode($user->id)]);

        self::assertSame('/login/2fa', $blocked->header('Location'));
        self::assertStringContainsString('Слишком много попыток', $this->follow($blocked)->body);
        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testRecoveryCodeWorksOnceAndIsCaseAndDashInsensitive(): void
    {
        $user = $this->createUser();
        $codes = $this->enableTwoFactor($user);
        $this->signIn();

        $used = strtoupper(str_replace('-', ' ', $codes[0]));
        self::assertSame('/app', $this->post('/login/2fa', ['code' => $used])->header('Location'));
        $this->post('/logout');

        $this->signIn();
        self::assertSame('/login/2fa', $this->post('/login/2fa', ['code' => $codes[0]])->header('Location'), 'a recovery code is single use');
        self::assertSame('/app', $this->post('/login/2fa', ['code' => $codes[1]])->header('Location'));
    }

    public function testRecoveryCodesAreStoredAsHashes(): void
    {
        $user = $this->createUser();
        $codes = $this->enableTwoFactor($user);

        $rows = $this->db->select('SELECT code_hash FROM recovery_codes WHERE user_id = ?', [$user->id]);

        $stored = array_map(static fn (array $row): string => (string) $row['code_hash'], $rows);
        $expected = array_map(static fn (string $code): string => hash('sha256', str_replace('-', '', $code)), $codes);
        sort($stored);
        sort($expected);

        self::assertCount(10, $rows);
        self::assertSame($expected, $stored);
    }

    public function testDisablingNeedsPasswordAndCode(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn();
        $this->post('/login/2fa', ['code' => $this->totpCode($user->id)]);
        $this->clock->advance(30);

        $noPassword = $this->post('/account/2fa/disable', ['disable_password' => 'wrong password', 'disable_code' => $this->totpCode($user->id)]);
        self::assertStringContainsString('Пароль указан неверно', $this->follow($noPassword)->body);
        $noCode = $this->post('/account/2fa/disable', ['disable_password' => self::PASSWORD, 'disable_code' => '000000']);
        self::assertStringContainsString('Код не подошёл', $this->follow($noCode)->body);
        self::assertNotNull($this->db->select('SELECT totp_enabled_at FROM users WHERE id = ?', [$user->id])[0]['totp_enabled_at']);

        $ok = $this->post('/account/2fa/disable', ['disable_password' => self::PASSWORD, 'disable_code' => $this->totpCode($user->id)]);

        self::assertSame('/account/security', $ok->header('Location'));
        $row = $this->db->select('SELECT totp_enabled_at, totp_secret_enc FROM users WHERE id = ?', [$user->id])[0];
        self::assertNull($row['totp_enabled_at']);
        self::assertNull($row['totp_secret_enc']);
        self::assertSame([], $this->db->select('SELECT 1 FROM recovery_codes'));
        $this->drainQueue();
        self::assertNotNull(array_values(array_filter($this->mailer->to('anna@example.com'), static fn ($m): bool => $m->subject === 'Двухфакторная защита отключена'))[0] ?? null);
    }

    public function testRegeneratingRecoveryCodesRetiresTheOldOnes(): void
    {
        $user = $this->createUser();
        $old = $this->enableTwoFactor($user);
        $this->signIn();
        $this->post('/login/2fa', ['code' => $old[0]]);

        $wrong = $this->post('/account/2fa/recovery-codes', ['codes_password' => 'wrong password']);
        self::assertStringContainsString('Пароль указан неверно', $this->follow($wrong)->body);
        $ok = $this->post('/account/2fa/recovery-codes', ['codes_password' => self::PASSWORD]);
        self::assertSame(200, $ok->status);
        preg_match_all('/([a-z0-9]{5}-[a-z0-9]{5})<\/li>/', $ok->body, $m);

        self::assertCount(10, $m[1]);
        self::assertSame([], array_intersect($old, $m[1]));
        $this->post('/logout');
        $this->signIn();
        self::assertSame('/login/2fa', $this->post('/login/2fa', ['code' => $old[1]])->header('Location'));
        self::assertSame('/app', $this->post('/login/2fa', ['code' => $m[1][0]])->header('Location'));
    }

    public function testTheSecondStepNeedsAPendingFirstStep(): void
    {
        $this->createUser();

        self::assertSame('/login', $this->get('/login/2fa')->header('Location'));
        self::assertSame('/login', $this->post('/login/2fa', ['code' => '123456'])->header('Location'));
    }

    public function testThePendingStateExpiresAfterTenMinutes(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn();
        $this->clock->advance(601);

        self::assertSame('/login', $this->get('/login/2fa')->header('Location'));
    }

    public function testSetupCannotBeRestartedWhileEnabled(): void
    {
        $user = $this->createUser();
        $this->enableTwoFactor($user);
        $this->signIn();
        $this->post('/login/2fa', ['code' => $this->totpCode($user->id)]);
        $secret = $this->db->select('SELECT totp_secret_enc FROM users WHERE id = ?', [$user->id])[0]['totp_secret_enc'];

        $this->post('/account/2fa/start');

        self::assertSame($secret, $this->db->select('SELECT totp_secret_enc FROM users WHERE id = ?', [$user->id])[0]['totp_secret_enc']);
        self::assertSame('/account/security#two-factor', $this->get('/account/2fa/setup')->header('Location'));
    }

    public function testGuestsCannotReachTwoFactorSettings(): void
    {
        foreach (['/account/2fa/setup', '/account/2fa/qr.svg'] as $path) {
            self::assertSame('/login', $this->get($path)->header('Location'), $path);
        }
        foreach (['/account/2fa/start', '/account/2fa/confirm', '/account/2fa/disable', '/account/2fa/recovery-codes'] as $path) {
            self::assertSame('/login', $this->post($path)->header('Location'), $path);
        }
    }
}
