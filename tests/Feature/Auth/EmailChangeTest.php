<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\EmailChangeService;
use App\Tests\Support\ArrayMailer;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Changing the sign-in email: confirmation on the new address, warning on the old one.
 */
#[CoversClass(EmailChangeService::class)]
final class EmailChangeTest extends AuthTestCase
{
    public function testFullFlow(): void
    {
        $this->createUser();
        $this->signIn();

        $response = $this->post('/account/email', ['new_email' => 'Anna.New@Example.com', 'email_password' => self::PASSWORD]);
        self::assertSame('/account/security', $response->header('Location'));
        $this->drainQueue();

        $confirm = $this->mailer->lastTo('anna.new@example.com');
        self::assertNotNull($confirm);
        self::assertSame('Подтвердите новый адрес почты', $confirm->subject);
        $notice = $this->mailer->lastTo('anna@example.com');
        self::assertNotNull($notice);
        self::assertSame('Запрошена смена адреса почты', $notice->subject);
        self::assertStringContainsString('anna.new@example.com', $notice->html);
        self::assertSame('anna@example.com', $this->db->select('SELECT email FROM users')[0]['email'], 'nothing changes before confirmation');

        $path = ArrayMailer::path($confirm);
        self::assertSame(200, $this->get($path)->status);
        self::assertSame('anna@example.com', $this->db->select('SELECT email FROM users')[0]['email'], 'opening the link does not change anything');
        self::assertSame('/account/security', $this->post($path)->header('Location'));

        self::assertSame('anna.new@example.com', $this->db->select('SELECT email FROM users')[0]['email']);
        $this->drainQueue();
        self::assertNotNull(array_values(array_filter($this->mailer->to('anna@example.com'), static fn ($m): bool => $m->subject === 'Адрес почты изменён'))[0] ?? null);

        $this->post('/logout');
        self::assertSame('/login', $this->signIn('anna@example.com')->header('Location'));
        self::assertSame('/app', $this->signIn('anna.new@example.com')->header('Location'));
    }

    public function testNeedsTheCurrentPassword(): void
    {
        $this->createUser();
        $this->signIn();

        $response = $this->post('/account/email', ['new_email' => 'other@example.com', 'email_password' => 'wrong password']);

        self::assertStringContainsString('Пароль указан неверно', $this->follow($response)->body);
        $this->drainQueue();
        self::assertSame([], $this->mailer->sent);
    }

    public function testAnAddressOfAnotherAccountLooksTheSameAndLeaksNothing(): void
    {
        $this->createUser('anna@example.com');
        $this->createUser('boris@example.com');
        $this->signIn();

        $taken = $this->post('/account/email', ['new_email' => 'boris@example.com', 'email_password' => self::PASSWORD]);
        $free = $this->post('/account/email', ['new_email' => 'free@example.com', 'email_password' => self::PASSWORD]);

        self::assertSame($taken->header('Location'), $free->header('Location'));
        $this->drainQueue();
        $toBoris = $this->mailer->to('boris@example.com');
        self::assertCount(1, $toBoris);
        self::assertSame('У вас уже есть аккаунт', $toBoris[0]->subject);
        self::assertStringNotContainsString('/email/change/', $toBoris[0]->text);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM users WHERE email = ?', ['anna@example.com'])[0]['c']);
    }

    public function testTakenInTheMeantimeFailsSafely(): void
    {
        $this->createUser('anna@example.com');
        $this->signIn();
        $this->post('/account/email', ['new_email' => 'late@example.com', 'email_password' => self::PASSWORD]);
        $this->drainQueue();
        $path = ArrayMailer::path($this->mailer->lastTo('late@example.com') ?? throw new \LogicException());
        $this->createUser('late@example.com');

        $result = $this->post($path);

        self::assertSame(410, $result->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM users WHERE email = ?', ['anna@example.com'])[0]['c']);
    }

    public function testLinkIsOneTimeExpiresAndWorksFromAnotherBrowser(): void
    {
        $this->createUser();
        $this->signIn();
        $this->post('/account/email', ['new_email' => 'moved@example.com', 'email_password' => self::PASSWORD]);
        $this->drainQueue();
        $path = ArrayMailer::path($this->mailer->lastTo('moved@example.com') ?? throw new \LogicException());

        $this->useBrowser();
        self::assertSame('/login', $this->post($path)->header('Location'));
        self::assertSame(410, $this->post($path)->status);

        $this->useBrowser();
        $this->signIn('moved@example.com');
        $this->post('/account/email', ['new_email' => 'again@example.com', 'email_password' => self::PASSWORD]);
        $this->drainQueue();
        $second = ArrayMailer::path($this->mailer->lastTo('again@example.com') ?? throw new \LogicException());
        $this->clock->advance(86400 + 5);
        self::assertSame(410, $this->get($second)->status);
    }

    public function testChangeRequestsAreLimited(): void
    {
        $this->createUser();
        $this->signIn();
        for ($i = 0; $i < 7; ++$i) {
            $this->post('/account/email', ['new_email' => 'n' . $i . '@example.com', 'email_password' => self::PASSWORD]);
        }
        $this->drainQueue();

        $confirmations = array_filter($this->mailer->sent, static fn ($m): bool => $m->subject === 'Подтвердите новый адрес почты');
        self::assertCount(5, $confirmations);
    }

    public function testRequestsNeedASignedInUserAndCsrf(): void
    {
        self::assertSame('/login', $this->post('/account/email', ['new_email' => 'x@example.com', 'email_password' => 'x'])->header('Location'));
        $this->createUser();
        $this->signIn();
        self::assertSame(419, $this->request('POST', '/account/email', ['new_email' => 'x@example.com', 'email_password' => self::PASSWORD])->status);
    }
}
