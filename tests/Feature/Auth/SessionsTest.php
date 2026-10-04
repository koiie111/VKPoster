<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\SessionRegistry;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The device list on the security page: listing, remote sign-out, isolation between users.
 */
#[CoversClass(SessionRegistry::class)]
final class SessionsTest extends AuthTestCase
{
    /**
     * @return array<string, string> cookie jar of a freshly signed-in browser
     */
    private function browserFor(string $email, ?string $userAgent = null): array
    {
        $this->useBrowser();
        $headers = $userAgent === null ? [] : ['User-Agent' => $userAgent];
        $this->request('POST', '/login', ['email' => $email, 'password' => self::PASSWORD, '_token' => $this->csrfToken()], $headers);

        return $this->cookies;
    }

    private function publicIdOf(string $label): string
    {
        foreach ($this->db->select('SELECT public_id, user_agent FROM user_sessions WHERE revoked_at IS NULL') as $row) {
            if (str_contains((string) $row['user_agent'], $label)) {
                return (string) $row['public_id'];
            }
        }
        self::fail('no session for ' . $label);
    }

    public function testListShowsEveryDeviceAndMarksTheCurrentOne(): void
    {
        $this->createUser();
        $laptop = $this->browserFor('anna@example.com', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15 Laptop');
        $this->browserFor('anna@example.com', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36 Phone');
        $this->useBrowser($laptop);

        $page = $this->get('/account/security')->body;

        self::assertStringContainsString('Safari на macOS', $page);
        self::assertStringContainsString('Chrome на Android', $page);
        self::assertSame(1, substr_count($page, 'Это устройство'));
        self::assertSame(1, substr_count($page, '>Отключить</button>'), 'no revoke button for the current device');
    }

    public function testRevokingAnotherDeviceSignsItOutImmediately(): void
    {
        $this->createUser();
        $laptop = $this->browserFor('anna@example.com', 'LaptopAgent');
        $phone = $this->browserFor('anna@example.com', 'PhoneAgent');
        $this->useBrowser($laptop);
        self::assertSame(200, $this->get('/app')->status);

        $this->useBrowser($phone);
        $response = $this->post('/account/sessions/' . $this->publicIdOf('LaptopAgent') . '/revoke');

        self::assertSame('/account/security#devices', $response->header('Location'));
        $this->useBrowser($laptop);
        self::assertSame('/login', $this->get('/app')->header('Location'));
        $this->useBrowser($phone);
        self::assertSame(200, $this->get('/app')->status);
    }

    public function testNobodyCanRevokeSomeoneElsesDevice(): void
    {
        $this->createUser('anna@example.com');
        $this->createUser('boris@example.com');
        $anna = $this->browserFor('anna@example.com', 'AnnaAgent');
        $this->browserFor('boris@example.com', 'BorisAgent');

        $response = $this->post('/account/sessions/' . $this->publicIdOf('AnnaAgent') . '/revoke');

        self::assertStringContainsString('Не удалось отключить', $this->follow($response)->body);
        $this->useBrowser($anna);
        self::assertSame(200, $this->get('/app')->status);
    }

    public function testTheCurrentDeviceCannotBeRevokedThroughTheEndpoint(): void
    {
        $this->createUser();
        $this->browserFor('anna@example.com', 'OnlyAgent');

        $this->post('/account/sessions/' . $this->publicIdOf('OnlyAgent') . '/revoke');

        self::assertSame(200, $this->get('/app')->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
    }

    public function testMalformedIdsDoNotRoute(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertSame(404, $this->post('/account/sessions/not-a-ulid/revoke')->status);
    }

    public function testSignOutEverywhereEndsAllSessionsAndRememberTokens(): void
    {
        $this->createUser();
        $laptop = $this->browserFor('anna@example.com', 'LaptopAgent');
        $this->useBrowser();
        $this->request('POST', '/login', ['email' => 'anna@example.com', 'password' => self::PASSWORD, 'remember' => '1', '_token' => $this->csrfToken()], ['User-Agent' => 'PhoneAgent']);
        $phone = $this->cookies;

        $this->post('/logout/all');

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_sessions WHERE revoked_at IS NULL')[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM auth_tokens WHERE type = ?', ['remember'])[0]['c']);
        $this->useBrowser($laptop);
        self::assertSame('/login', $this->get('/app')->header('Location'));
        $this->useBrowser(['remember' => $phone['remember']]);
        $viaRememberCookie = $this->get('/app');
        self::assertSame('/login', $viaRememberCookie->header('Location'));
    }

    public function testIdleDevicesDisappearFromTheList(): void
    {
        $this->createUser();
        $this->browserFor('anna@example.com', 'OldAgent');
        $this->clock->advance(7200 + 600);
        $current = $this->browserFor('anna@example.com', 'NewAgent');
        $this->useBrowser($current);

        $page = $this->get('/account/security')->body;

        self::assertStringNotContainsString('OldAgent', $page);
        self::assertSame(1, substr_count($page, 'Это устройство'));
    }

    public function testJournalListsSuccessfulAndFailedAttempts(): void
    {
        $this->createUser();
        $this->signIn('anna@example.com', 'wrong password');
        $this->signIn();

        $page = $this->get('/account/security')->body;

        self::assertStringContainsString('Неверный пароль', $page);
        self::assertStringContainsString('Вход выполнен', $page);
    }

    public function testASessionOfAnotherUserIsNeverShown(): void
    {
        $this->createUser('anna@example.com');
        $this->createUser('boris@example.com');
        $this->browserFor('boris@example.com', 'BorisSecretAgent');
        $this->browserFor('anna@example.com', 'AnnaAgent');

        self::assertStringNotContainsString('BorisSecretAgent', $this->get('/account/security')->body);
    }
}
