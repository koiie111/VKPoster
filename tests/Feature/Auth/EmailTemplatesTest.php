<?php

declare(strict_types=1);

namespace App\Tests\Feature\Auth;

use App\Domain\Auth\AuthMailer;
use App\Domain\Notification\MailComposer;
use App\Domain\Notification\SendMailJob;
use App\Domain\User\User;
use App\Kernel\Security\Crypto;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Every transactional email renders in HTML and plain text, carries its link, and keeps secrets out of the queue table.
 */
#[CoversClass(AuthMailer::class)]
#[CoversClass(MailComposer::class)]
#[CoversClass(SendMailJob::class)]
final class EmailTemplatesTest extends AuthTestCase
{
    private function sampleUser(): User
    {
        return $this->createUser('sample@example.com', true, null, 'Ольга');
    }

    public function testEveryAuthMailRendersWithItsLinkAndName(): void
    {
        $user = $this->sampleUser();
        $mailer = $this->app->container()->get(AuthMailer::class);
        $token = str_repeat('t', 43);

        $mailer->verifyEmail('sample@example.com', 'Ольга', $token);
        $mailer->accountExists('sample@example.com');
        $mailer->passwordReset($user, $token);
        $mailer->passwordChanged($user);
        $mailer->emailChangeConfirm('new@example.com', $user, $token);
        $mailer->emailChangeRequested($user, 'new@example.com');
        $mailer->emailChanged('sample@example.com', 'new@example.com', $user);
        $mailer->lockoutWarning($user);
        $mailer->twoFactorChanged($user, true);
        $mailer->twoFactorChanged($user, false);
        $this->drainQueue();

        self::assertCount(10, $this->mailer->sent);
        foreach ($this->mailer->sent as $message) {
            self::assertNotSame('', trim($message->subject));
            self::assertStringContainsString('<!doctype html>', $message->html, $message->subject);
            self::assertStringContainsString('ezposter', $message->text, $message->subject);
            self::assertStringNotContainsString('{{', $message->html . $message->text, $message->subject);
            self::assertStringNotContainsString('&amp;', $message->text, 'plain text must not be HTML-escaped: ' . $message->subject);
        }
        self::assertStringContainsString('Ольга', $this->mailer->sent[0]->text);
        self::assertStringContainsString('http://localhost/email/verify/' . $token, $this->mailer->sent[0]->html);
        self::assertStringContainsString('http://localhost/password/reset/' . $token, $this->mailer->sent[2]->text);
    }

    public function testNamesAreEscapedInHtmlMail(): void
    {
        $this->createUser('evil@example.com', true, null, '<script>alert(1)</script>');
        $user = $this->app->container()->get(\App\Domain\User\UserRepository::class)->findByEmail('evil@example.com');
        self::assertNotNull($user);

        $this->app->container()->get(AuthMailer::class)->passwordChanged($user);
        $this->drainQueue();

        self::assertStringNotContainsString('<script>', $this->mailer->sent[0]->html);
        self::assertStringContainsString('&lt;script&gt;', $this->mailer->sent[0]->html);
    }

    public function testQueuedPayloadDoesNotContainTheLinkInPlainText(): void
    {
        $this->app->container()->get(AuthMailer::class)->passwordReset($this->sampleUser(), str_repeat('s', 43));

        $payload = (string) $this->db->select('SELECT payload_json FROM jobs')[0]['payload_json'];

        self::assertStringNotContainsString(str_repeat('s', 43), $payload);
        self::assertStringNotContainsString('/password/reset', $payload);
        self::assertStringContainsString('"to": "sample@example.com"', $payload);
    }

    public function testJobRejectsUnsafeTemplateNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SendMailJob('a@example.com', 'x', '../../etc/passwd', $this->app->container()->get(Crypto::class)->encrypt('{}'));
    }

    public function testFailedDeliveryIsRetriedByTheQueue(): void
    {
        $this->app->container()->get(AuthMailer::class)->passwordChanged($this->sampleUser());
        $this->app->container()->instance(\App\Kernel\Mail\Mailer::class, new class () implements \App\Kernel\Mail\Mailer {
            public function send(\App\Kernel\Mail\MailMessage $message): void
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->drainQueue();

        $job = $this->db->select('SELECT attempts, last_error FROM jobs')[0];
        self::assertSame(1, (int) $job['attempts']);
        self::assertStringContainsString('smtp down', (string) $job['last_error']);
    }
}
