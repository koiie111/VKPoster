<?php

declare(strict_types=1);

namespace App\Tests\Feature\Site;

use App\Http\Controllers\Account\FeedbackController;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FeedbackController::class)]
final class FeedbackTest extends WorkspaceTestCase
{
    public function testGuestsCannotSendFeedback(): void
    {
        $this->useBrowser();
        self::assertSame('/login', $this->get('/feedback')->header('Location'));
        self::assertSame('/login', $this->post('/feedback', ['message' => 'Не работает кнопка'])->header('Location'));
    }

    public function testMessageReachesSupportWithContextAndNoSecrets(): void
    {
        [$user, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($user);
        $this->get('/w/' . $workspace->publicId);

        self::assertStringContainsString('Сообщить о проблеме', $this->get('/feedback?from=/w/' . $workspace->publicId . '/calendar')->body);
        $response = $this->post('/feedback', ['message' => 'Пост не вышел вчера вечером, помогите.', 'from' => '/w/' . $workspace->publicId . '/calendar?token=secret']);
        self::assertSame('/app', $response->header('Location'));

        $this->drainQueue();
        $mail = $this->mailer->lastTo($this->app->config()->string('mail.support'));
        self::assertNotNull($mail);
        self::assertStringContainsString('[Обратная связь] Пост не вышел', $mail->subject);
        self::assertStringContainsString('Пост не вышел вчера вечером', $mail->html);
        self::assertStringContainsString($user->email ?? '', $mail->html);
        self::assertStringContainsString($workspace->publicId, $mail->html);
        self::assertStringContainsString('/calendar', $mail->html);
        self::assertStringNotContainsString('token=secret', $mail->html);
    }

    public function testShortMessageIsRejectedAndForeignUrlsAreDropped(): void
    {
        [$user] = $this->ownerWithWorkspace();
        $this->actAs($user);

        $response = $this->post('/feedback', ['message' => 'мало', 'from' => 'https://evil.example/x']);
        self::assertStringStartsWith('/feedback', (string) $response->header('Location'));
        self::assertStringNotContainsString('evil', (string) $response->header('Location'));
        self::assertStringContainsString('Сообщение', $this->follow($response)->body);
        $this->drainQueue();
        self::assertNull($this->mailer->lastTo($this->app->config()->string('mail.support')));
    }
}
