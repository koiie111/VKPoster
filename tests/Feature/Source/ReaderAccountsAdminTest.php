<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Admin\StaffRole;
use App\Domain\Source\ReaderAccounts;
use App\Tests\Support\AdminTestCase;

/** Staff controls service identities with scoped permission, CSRF and step-up verification. */
final class ReaderAccountsAdminTest extends AdminTestCase
{
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
