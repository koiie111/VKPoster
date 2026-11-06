<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\ReaderAccounts;
use App\Domain\Source\SourceService;
use App\Domain\Source\SourceException;
use App\Kernel\Exception\HttpException;
use App\Kernel\Security\Crypto;
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

        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'News', 'telegram', '@reader_news', true);
        $row = $this->db->table('sources')->where('id', '=', $source->id)->first() ?? throw new \LogicException('Missing source');
        self::assertSame((int) $raw['id'], (int) $row['reader_account_id']);
        $oldVersion = (int) $row['connection_version'];
        $accounts->changeStatus($first, 'blocked', $owner->id);
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
