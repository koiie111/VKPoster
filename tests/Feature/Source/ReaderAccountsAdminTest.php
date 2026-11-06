<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Admin\StaffRole;
use App\Domain\Source\ReaderAccounts;
use App\Domain\Source\SourceService;
use App\Tests\Support\AdminTestCase;

/** Staff controls service identities with scoped permission, CSRF and step-up verification. */
final class ReaderAccountsAdminTest extends AdminTestCase
{
    public function testStaffCanMoveOnlyServiceManagedSources(): void
    {
        $staff = $this->staff();
        [$owner, $workspace] = $this->ownerWithWorkspace('reader-assign-owner@example.com');
        $accounts = $this->app->container()->get(ReaderAccounts::class);
        $first = $accounts->create(null, 'First', $staff->id);
        $second = $accounts->create(null, 'Second', $staff->id);
        $accounts->attachSession($first, 111, '1' . str_repeat('A', 200), $staff->id);
        $accounts->attachSession($second, 222, '1' . str_repeat('B', 200), $staff->id);
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'News', 'telegram', '@reader_assign', true);
        $target = (int) ($this->db->table('source_reader_accounts')->where('public_id', '=', $second)->first()['id'] ?? 0);
        self::assertSame(302, $this->post('/admin/source-reader-accounts/assign', $this->confirm(['source_id' => $source->publicId, 'account_id' => $second]))->status);
        self::assertSame($target, (int) ($this->db->table('sources')->where('id', '=', $source->id)->first()['reader_account_id'] ?? 0));
        $own = $accounts->create($workspace->id, 'Customer', $owner->id);
        $accounts->attachSession($own, 333, '1' . str_repeat('C', 200), $owner->id, $workspace->id);
        $ownTarget = (int) ($this->db->table('source_reader_accounts')->where('public_id', '=', $own)->first()['id'] ?? 0);
        self::assertSame(302, $this->post('/admin/source-reader-accounts/assign', $this->confirm(['source_id' => $source->publicId, 'account_id' => $second]))->status);
        self::assertSame($ownTarget, (int) ($this->db->table('sources')->where('id', '=', $source->id)->first()['reader_account_id'] ?? 0));
        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->post('/admin/source-reader-accounts/assign', ['source_id' => $source->publicId, 'account_id' => $first])->status);
    }

    public function testCreateAndDisableRequirePermissionAndFreshCode(): void
    {
        $this->staff(StaffRole::Superadmin);
        self::assertSame(200, $this->get('/admin/source-reader-accounts')->status);
        self::assertSame(302, $this->post('/admin/source-reader-accounts', ['label' => 'Service A'])->status);
        self::assertSame([], $this->app->container()->get(ReaderAccounts::class)->serviceList());
        self::assertSame(302, $this->post('/admin/source-reader-accounts', $this->confirm(['label' => 'Service A']))->status);
        $accounts = $this->app->container()->get(ReaderAccounts::class)->serviceList();
        self::assertCount(1, $accounts);
        self::assertSame('pending', $accounts[0]['status']);
        self::assertStringContainsString('Service A', $this->get('/admin/source-reader-accounts')->body);
        $id = (string) $accounts[0]['public_id'];
        $session = '1' . str_repeat('Q', 200);
        self::assertSame(302, $this->post('/admin/source-reader-accounts/' . $id . '/session', $this->confirm(['telegram_user_id' => '12345', 'session' => $session]))->status);
        $page = $this->get('/admin/source-reader-accounts');
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString($session, $page->body);
        self::assertSame('active', $this->app->container()->get(ReaderAccounts::class)->serviceList()[0]['status']);
        self::assertStringNotContainsString($session, (string) ($this->db->table('source_reader_accounts')->where('public_id', '=', $id)->first()['session_enc'] ?? ''));
        self::assertSame(302, $this->post('/admin/source-reader-accounts/' . $id . '/status', $this->confirm(['status' => 'disabled']))->status);
        self::assertSame('disabled', $this->app->container()->get(ReaderAccounts::class)->serviceList()[0]['status']);
        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->get('/admin/source-reader-accounts')->status);
    }
}
