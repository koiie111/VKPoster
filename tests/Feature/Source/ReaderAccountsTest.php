<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\ReaderAccounts;
use App\Domain\Source\SourceService;
use App\Domain\Source\SourceException;
use App\Kernel\Exception\HttpException;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Security\Crypto;
use App\Tests\Support\TestEnv;
use App\Tests\Support\WorkspaceTestCase;

/** Registry and placement are tested without Telegram credentials or network calls. */
final class ReaderAccountsTest extends WorkspaceTestCase
{
    public function testServiceFailoverAndWorkspaceAccountIsolation(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $accounts = $this->app->container()->get(ReaderAccounts::class);
        $first = $accounts->create(null, 'Reader A', $owner->id);
        $second = $accounts->create(null, 'Reader B', $owner->id);
        $session = '1' . str_repeat('A', 200);
        $accounts->attachSession($first, 10001, $session, $owner->id);
        $accounts->attachSession($second, 10002, '1' . str_repeat('B', 200), $owner->id);
        $raw = $this->db->table('source_reader_accounts')->where('public_id', '=', $first)->first() ?? throw new \LogicException('Missing account');
        self::assertNotSame($session, $raw['session_enc']);
        self::assertSame($session, $this->app->container()->get(Crypto::class)->decrypt((string) $raw['session_enc'], 'telegram-reader-session'));

        $this->app->container()->instance(Config::class, TestEnv::config(['SOURCES_READER_SECRET' => 'source-reader-test-secret-not-real-123456789']));
        $request = static fn (string $secret): Request => Request::create('GET', '/internal/reader-accounts', headers: ['Authorization' => 'Bearer ' . $secret]);
        self::assertSame(403, $this->app->handle($request('invalid'))->status);
        $active = json_decode($this->app->handle($request('source-reader-test-secret-not-real-123456789'))->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($session, $active['accounts'][0]['session']);
        self::assertSame([], $active['accounts'][0]['sources']);

        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'News', 'telegram', '@reader_news', true);
        $row = $this->db->table('sources')->where('id', '=', $source->id)->first() ?? throw new \LogicException('Missing source');
        self::assertSame((int) $raw['id'], (int) $row['reader_account_id']);
        $oldVersion = (int) $row['connection_version'];
        $accounts->attachSession($first, 10001, '1' . str_repeat('Z', 200), $owner->id);
        $row = $this->db->table('sources')->where('id', '=', $source->id)->first() ?? throw new \LogicException('Missing source');
        self::assertGreaterThan($oldVersion, (int) $row['connection_version']);
        $oldVersion = (int) $row['connection_version'];
        $blocked = static fn (string $secret, string $id): Request => Request::create('POST', '/internal/reader-accounts/blocked', headers: ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json'], rawBody: json_encode(['account_id' => $id], JSON_THROW_ON_ERROR));
        self::assertSame(403, $this->app->handle($blocked('invalid', $first))->status);
        self::assertSame(400, $this->app->handle($blocked('source-reader-test-secret-not-real-123456789', 'invalid'))->status);
        self::assertSame(200, $this->app->handle($blocked('source-reader-test-secret-not-real-123456789', $first))->status);
        $row = $this->db->table('sources')->where('id', '=', $source->id)->first() ?? throw new \LogicException('Missing source');
        $other = $this->db->table('source_reader_accounts')->where('public_id', '=', $second)->first() ?? throw new \LogicException('Missing account');
        self::assertSame((int) $other['id'], (int) $row['reader_account_id']);
        self::assertGreaterThan($oldVersion, (int) $row['connection_version']);

        $own = $accounts->create($workspace->id, 'My Telegram', $owner->id);
        $accounts->attachSession($own, 10003, '1' . str_repeat('C', 200), $owner->id, $workspace->id);
        $ownRow = $this->db->table('source_reader_accounts')->where('public_id', '=', $own)->first() ?? throw new \LogicException('Missing account');
        self::assertSame((int) $ownRow['id'], (int) ($this->db->table('sources')->where('id', '=', $source->id)->first()['reader_account_id'] ?? 0));
        $accounts->changeStatus($own, 'blocked', $owner->id, $workspace->id);
        $row = $this->db->table('sources')->where('id', '=', $source->id)->first() ?? throw new \LogicException('Missing source');
        self::assertNull($row['reader_account_id']);
        self::assertSame('error', $row['status']);
        self::assertCount(1, $accounts->list($workspace->id));
        self::assertArrayNotHasKey('session_enc', $accounts->list($workspace->id)[0]);
        $this->actAs($owner);
        $page = $this->get('/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId);
        self::assertStringContainsString('Чтение источника остановлено', $page->body);
        try {
            $accounts->attachSession($own, 99999, '1' . str_repeat('D', 200), $owner->id, $workspace->id);
            self::fail('An account must not silently change its Telegram identity.');
        } catch (SourceException $e) {
            self::assertSame('session', $e->field);
        }

        [$outsider, $otherWorkspace] = $this->ownerWithWorkspace('reader-outsider@example.com');
        try {
            $accounts->changeStatus($own, 'active', $outsider->id, $otherWorkspace->id);
            self::fail('A foreign workspace must not control this account.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }
}
