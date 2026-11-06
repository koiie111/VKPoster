<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\ReaderAccounts;
use App\Kernel\Config;
use App\Tests\Support\TestEnv;
use App\Domain\Workspace\Role;
use App\Kernel\Http\Request;
use App\Tests\Support\WorkspaceTestCase;

/** Own account controls remain in the workspace boundary and never reveal a saved session. */
final class ReaderAccountsWorkspaceTest extends WorkspaceTestCase
{
    public function testQrEnrollmentIsScopedAndPasswordIsSingleUse(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $id = $this->app->container()->get(ReaderAccounts::class)->create($workspace->id, 'Own', $owner->id);
        $base = $this->base($workspace) . '/sources/reader-account/' . $id . '/qr';
        $this->actAs($owner);
        self::assertSame(419, $this->app->handle(Request::create('POST', $base))->status);
        self::assertSame(302, $this->post($base)->status);
        self::assertStringContainsString('Готовим код', $this->get($base)->body);
        $this->app->container()->instance(Config::class, TestEnv::config(['SOURCES_READER_SECRET' => 'source-reader-test-secret-not-real-123456789']));
        $internal = fn (string $path, ?array $body = null, string $secret = 'source-reader-test-secret-not-real-123456789'): \App\Kernel\Http\Response => $this->app->handle(Request::create($body === null ? 'GET' : 'POST', $path, headers: ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json'], rawBody: $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)));
        self::assertSame(403, $internal('/internal/reader-qr', secret: 'wrong')->status);
        $pendingResponse = $internal('/internal/reader-qr');
        self::assertSame('no-store', $pendingResponse->headers['Cache-Control'] ?? null);
        $pending = json_decode($pendingResponse->body, true, 32, JSON_THROW_ON_ERROR)['accounts'];
        $challenge = (string) $pending[0]['challenge'];
        $payload = ['account_id' => $id, 'challenge' => $challenge];
        $png = "\x89PNG\r\n\x1a\n" . 'test';
        self::assertSame(200, $internal('/internal/reader-qr/publish', $payload + ['state' => 'qr', 'image' => base64_encode($png)])->status);
        self::assertSame($png, $this->get($base . '.png')->body);
        self::assertSame(200, $internal('/internal/reader-qr/publish', $payload + ['state' => 'password_needed'])->status);
        self::assertStringContainsString('Пароль Telegram', $this->get($base)->body);
        self::assertSame(302, $this->post($base . '/password', ['password' => ' private-test-password '])->status);
        $passwordResponse = $internal('/internal/reader-qr/password', $payload);
        self::assertSame('no-store', $passwordResponse->headers['Cache-Control'] ?? null);
        $answer = json_decode($passwordResponse->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(' private-test-password ', $answer['password']);
        self::assertNull(json_decode($internal('/internal/reader-qr/password', $payload)->body, true, 32, JSON_THROW_ON_ERROR)['password']);
        self::assertSame(200, $internal('/internal/reader-accounts/enroll', $payload + ['telegram_user_id' => 12345, 'session' => '1' . str_repeat('A', 200)])->status);
        self::assertSame([], json_decode($internal('/internal/reader-qr')->body, true, 32, JSON_THROW_ON_ERROR)['accounts']);
        self::assertStringContainsString('Аккаунт подключён', $this->get($base)->body);
        self::assertSame(409, $internal('/internal/reader-qr/publish', $payload + ['state' => 'qr', 'image' => base64_encode($png)])->status);
        [$other, $otherWorkspace] = $this->ownerWithWorkspace('reader-qr-other@example.com');
        $this->actAs($other);
        self::assertSame(404, $this->get($this->base($otherWorkspace) . '/sources/reader-account/' . $id . '/qr')->status);
    }

    public function testOwnerCreatesImportsAndDisablesOwnAccount(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $base = $this->base($workspace) . '/sources';
        $this->actAs($owner);
        self::assertSame(200, $this->get($base)->status);
        self::assertSame(419, $this->app->handle(Request::create('POST', $base . '/reader-account'))->status);
        self::assertSame(302, $this->post($base . '/reader-account')->status);
        $row = $this->app->container()->get(ReaderAccounts::class)->list($workspace->id)[0];
        $id = (string) $row['public_id'];
        $session = '1' . str_repeat('P', 200);
        self::assertSame(302, $this->post($base . '/reader-account/' . $id . '/session', ['telegram_user_id' => '54321', 'session' => $session])->status);
        self::assertStringNotContainsString($session, $this->get($base)->body);
        self::assertSame('active', $this->app->container()->get(ReaderAccounts::class)->list($workspace->id)[0]['status']);
        self::assertSame(302, $this->post($base . '/reader-account/' . $id . '/status', ['status' => 'disabled'])->status);
        self::assertSame('disabled', $this->app->container()->get(ReaderAccounts::class)->list($workspace->id)[0]['status']);

        $viewer = $this->memberOf($workspace, 'reader-viewer@example.com', Role::Viewer);
        $this->actAs($viewer);
        self::assertSame(403, $this->post($base . '/reader-account')->status);
    }

    public function testForeignAccountCannotBeChanged(): void
    {
        [$firstOwner, $firstWorkspace] = $this->ownerWithWorkspace();
        [$secondOwner, $secondWorkspace] = $this->ownerWithWorkspace('reader-other@example.com');
        $id = $this->app->container()->get(ReaderAccounts::class)->create($firstWorkspace->id, 'First', $firstOwner->id);
        $this->actAs($secondOwner);
        self::assertSame(404, $this->post($this->base($secondWorkspace) . '/sources/reader-account/' . $id . '/status', ['status' => 'disabled'])->status);
    }
}
