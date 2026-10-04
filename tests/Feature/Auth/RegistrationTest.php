<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\RegistrationService;
use App\Tests\Support\ArrayMailer;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sign-up, email confirmation and the "same answer for existing emails" rule.
 */
#[CoversClass(RegistrationService::class)]
final class RegistrationTest extends AuthTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function form(string $email = 'new@example.com'): array
    {
        return ['name' => 'Мария', 'email' => $email, 'password' => 'a-long-unusual-passphrase', 'consent' => '1'];
    }

    public function testFullFlowRegisterConfirmSignIn(): void
    {
        $response = $this->post('/register', $this->form());
        self::assertSame(302, $response->status);
        self::assertSame('/register/done', $response->header('Location'));
        self::assertStringContainsString('new@example.com', $this->follow($response)->body);

        $this->drainQueue();
        $mail = $this->mailer->lastTo('new@example.com');
        self::assertNotNull($mail);
        self::assertSame('Подтвердите адрес почты', $mail->subject);
        self::assertStringContainsString('Здравствуйте, Мария!', $mail->html);
        $path = ArrayMailer::path($mail);
        self::assertStringStartsWith('/email/verify/', $path);

        // Opening the link changes nothing; only the POST behind the button confirms.
        $page = $this->get($path);
        self::assertSame(200, $page->status);
        self::assertSame([], $this->db->select('SELECT 1 FROM users WHERE email_verified_at IS NOT NULL'));
        $confirmed = $this->post($path);
        self::assertSame('/login', $confirmed->header('Location'));
        self::assertNotSame([], $this->db->select('SELECT 1 FROM users WHERE email_verified_at IS NOT NULL'));

        $login = $this->signIn('new@example.com', 'a-long-unusual-passphrase');
        self::assertSame('/app', $login->header('Location'));
        self::assertStringContainsString('Здравствуйте, Мария!', $this->get('/app')->body);
    }

    public function testConsentVersionAndTimeAreStoredAndPasswordIsArgon2id(): void
    {
        $this->post('/register', $this->form());

        $row = $this->db->select('SELECT password_hash, consent_version, consent_at, email_verified_at FROM users')[0];
        self::assertSame('2026-10-01', $row['consent_version']);
        self::assertNotNull($row['consent_at']);
        self::assertNull($row['email_verified_at']);
        self::assertStringStartsWith('$argon2id$', (string) $row['password_hash']);
        self::assertStringNotContainsString('unusual', (string) $row['password_hash']);
    }

    public function testExistingEmailGetsTheSameAnswerAndAnAccountExistsMail(): void
    {
        $this->createUser('taken@example.com');
        $fresh = $this->post('/register', $this->form('brand-new@example.com'));
        $existing = $this->post('/register', $this->form('taken@example.com'));

        self::assertSame($fresh->status, $existing->status);
        self::assertSame($fresh->header('Location'), $existing->header('Location'));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM users WHERE email = ?', ['taken@example.com'])[0]['c']);

        $this->drainQueue();
        $notice = $this->mailer->lastTo('taken@example.com');
        self::assertNotNull($notice);
        self::assertSame('У вас уже есть аккаунт', $notice->subject);
        self::assertSame('Подтвердите адрес почты', $this->mailer->lastTo('brand-new@example.com')?->subject);
        // The "already registered" notice does not carry a token that could be abused.
        self::assertStringNotContainsString('/email/verify/', $notice->text);
    }

    public function testAccountExistsNoticesAreLimitedPerAddress(): void
    {
        $this->createUser('taken@example.com');
        for ($i = 0; $i < 6; ++$i) {
            $this->post('/register', $this->form('taken@example.com'));
        }
        $this->drainQueue();

        self::assertCount(3, $this->mailer->to('taken@example.com'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidForms(): array
    {
        $ok = ['name' => 'Мария', 'email' => 'new@example.com', 'password' => 'a-long-unusual-passphrase', 'consent' => '1'];

        return [
            'short password' => [['password' => 'short'] + $ok, 'Пароль слишком короткий'],
            'common password' => [['password' => 'password123'] + $ok, 'слишком распространён'],
            'long password' => [['password' => str_repeat('a1', 70)] + $ok, 'длиннее 128'],
            'password equals email' => [['email' => 'someone.long@example.com', 'password' => 'someone.long@example.com'] + $ok, 'не должен совпадать'],
            'no consent' => [['consent' => ''] + $ok, 'согласитесь на обработку персональных данных'],
            'bad email' => [['email' => 'not-an-email'] + $ok, 'Почта'],
            'empty name' => [['name' => ' '] + $ok, 'Как вас зовут'],
        ];
    }

    /**
     * @param array<string, mixed> $form
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidForms')]
    public function testInvalidInputIsRejectedAndNothingIsCreated(array $form, string $message): void
    {
        $response = $this->post('/register', $form);

        self::assertSame('/register', $response->header('Location'));
        $page = $this->follow($response);
        self::assertStringContainsString($message, $this->text($page));
        self::assertSame([], $this->db->select('SELECT 1 FROM users'));
        $this->drainQueue();
        self::assertSame([], $this->mailer->sent);
    }

    public function testFormKeepsTypedValuesButNeverThePassword(): void
    {
        $response = $this->post('/register', ['password' => 'short'] + $this->form());
        $page = $this->follow($response)->body;

        self::assertStringContainsString('value="new@example.com"', $page);
        self::assertStringContainsString('value="Мария"', $page);
        self::assertStringNotContainsString('short', preg_replace('/Пароль слишком короткий[^<]*/u', '', $page) ?? '');
    }

    public function testRegistrationRequiresCsrfToken(): void
    {
        $this->get('/register');

        self::assertSame(419, $this->request('POST', '/register', $this->form())->status);
    }

    public function testConfirmationLinkIsOneTimeAndExpires(): void
    {
        $this->post('/register', $this->form());
        $this->drainQueue();
        $path = ArrayMailer::path($this->mailer->sent[0]);

        self::assertSame('/login', $this->post($path)->header('Location'));
        $second = $this->post($path);
        self::assertSame(410, $second->status);
        self::assertStringContainsString('Ссылка больше не работает', $second->body);

        $this->post('/register', $this->form('late@example.com'));
        $this->drainQueue();
        $latePath = ArrayMailer::path($this->mailer->lastTo('late@example.com') ?? throw new \LogicException());
        $this->clock->advance(86400 + 5);
        self::assertSame(410, $this->get($latePath)->status);
        self::assertSame(410, $this->post($latePath)->status);
    }

    public function testNewConfirmationLinkInvalidatesTheOldOne(): void
    {
        $this->createUser('anna@example.com', verified: false);
        $this->signIn();
        $this->post('/email/verification/resend');
        $this->drainQueue();
        $first = ArrayMailer::path($this->mailer->sent[0]);
        $this->clock->advance(5);
        // A second link issued later replaces the first.
        $user = $this->db->select('SELECT id FROM users')[0];
        $tokens = $this->app->container()->get(\App\Domain\Auth\AuthTokens::class);
        $tokens->issue((int) $user['id'], \App\Domain\Auth\TokenType::EmailVerify, 3600);

        self::assertSame(410, $this->get($first)->status);
    }

    public function testRegistrationIsRateLimitedPerIp(): void
    {
        $last = null;
        for ($i = 0; $i < 11; ++$i) {
            $last = $this->post('/register', $this->form('user' . $i . '@example.com'));
        }

        self::assertNotNull($last);
        self::assertSame(429, $last->status);
        self::assertNotNull($last->header('Retry-After'));
    }

    public function testUnverifiedUsersAreHeldBackFromTheApp(): void
    {
        $this->createUser('anna@example.com', verified: false);
        $this->signIn();

        $app = $this->get('/app');
        self::assertSame('/email/verification', $app->header('Location'));
        self::assertStringContainsString('anna@example.com', $this->get('/email/verification')->body);
        self::assertSame(200, $this->get('/account/security')->status, 'the security page stays open so a wrong address can be fixed');
    }

    public function testResendIsLimitedToThreePerHour(): void
    {
        $this->createUser('anna@example.com', verified: false);
        $this->signIn();
        for ($i = 0; $i < 5; ++$i) {
            $this->post('/email/verification/resend');
        }
        $this->drainQueue();

        self::assertCount(3, $this->mailer->to('anna@example.com'));
    }

    public function testVerifiedUsersSkipTheNoticePage(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertSame('/app', $this->get('/email/verification')->header('Location'));
    }
}
