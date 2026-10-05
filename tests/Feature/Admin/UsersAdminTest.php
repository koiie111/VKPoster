<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\PersonalData;
use App\Domain\Admin\StaffRole;
use App\Domain\Admin\UserActions;
use App\Domain\Admin\UserDirectory;
use App\Domain\Billing\Ledger;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Admin\PrivacyController;
use App\Http\Controllers\Admin\UsersController;
use App\Support\DbTime;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The people part of the back office: search and filters, the card, the account actions with their re-confirmation and audit,
 * ledger-backed gifts, and the 152-FZ requests (a copy of the data that is only about that person, and deletion that keeps the money records).
 */
#[CoversClass(UsersController::class)]
#[CoversClass(UserDirectory::class)]
#[CoversClass(UserActions::class)]
#[CoversClass(PersonalData::class)]
#[CoversClass(PrivacyController::class)]
#[CoversClass(Ledger::class)]
final class UsersAdminTest extends AdminTestCase
{
    /**
     * @return list<int> ids of the people on the page of a list query
     */
    private function ids(string $query): array
    {
        $body = $this->get('/admin/users?' . $query)->body;
        preg_match_all('#href="/admin/users/(\d+)"#', $body, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    public function testSearchFiltersAndSorting(): void
    {
        $staff = $this->staff();
        [$anna] = $this->ownerWithWorkspace('anna@mail.ru', 'Анна Иванова');
        [$boris] = $this->ownerWithWorkspace('boris@corp.example.com', 'Борис Петров');
        [$vera] = $this->ownerWithWorkspace('vera@mail.ru', 'Вера');
        $this->db->execute("INSERT INTO user_identities (user_id, provider, provider_user_id, display_name, linked_at) VALUES (?, 'vkid', '777001', 'Борис ВК', ?)", [$boris->id, DbTime::format($this->clock->now())]);
        $this->app->container()->get(\App\Domain\User\UserRepository::class)->setStatus($vera->id, 'blocked', 'спам');
        $this->db->execute("INSERT INTO user_attribution (user_id, utm_source, first_seen_at) VALUES (?, 'vk-ads', ?)", [$anna->id, DbTime::format($this->clock->now())]);
        $this->db->execute('INSERT INTO user_activity_days (user_id, day) VALUES (?, ?)', [$anna->id, $this->clock->now()->format('Y-m-d')]);

        self::assertSame([$anna->id], $this->ids('q=' . rawurlencode('Иванова')));
        self::assertEqualsCanonicalizing([$anna->id, $vera->id], $this->ids('q=' . rawurlencode('@mail.ru')));
        self::assertSame([$boris->id], $this->ids('q=777001'), 'the id a person has in VK');
        self::assertContains($anna->id, $this->ids('q=' . $anna->id));
        self::assertSame([$vera->id], $this->ids('status=blocked'));
        self::assertSame([$anna->id], $this->ids('source=vk-ads'));
        self::assertSame([$anna->id], $this->ids('activity=active&q=' . rawurlencode('@mail.ru')));
        self::assertSame([$vera->id], $this->ids('activity=inactive&q=' . rawurlencode('@mail.ru') . '&status=blocked'));
        self::assertSame([$staff->id], $this->ids('status=staff'));
        // `%` and `_` in a search are plain characters, not wildcards.
        self::assertSame([], $this->ids('q=' . rawurlencode('%')));
        // Sorting is by a whitelist; a made-up key falls back to the default instead of reaching the query.
        self::assertSame(200, $this->get('/admin/users?sort=' . rawurlencode('id; DROP TABLE users') . '&dir=up')->status);
        $byName = $this->ids('sort=name&dir=asc&q=' . rawurlencode('@'));
        self::assertSame([$anna->id, $boris->id, $vera->id], array_values(array_intersect($byName, [$anna->id, $boris->id, $vera->id])));
        self::assertSame('Заблокирован', trim(strip_tags($this->get('/admin/users?status=blocked')->body)) !== '' ? 'Заблокирован' : '');
    }

    public function testCsvExportIsOwnerOnlyAndSafe(): void
    {
        $this->staff(StaffRole::Support);
        self::assertSame(403, $this->get('/admin/users/export')->status);
        $this->staff();
        $this->createUser('=cmd@example.com', true, null, '=1+1');
        $csv = $this->get('/admin/users/export?q=cmd');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('text/csv', (string) $csv->header('Content-Type'));
        self::assertStringContainsString("\"'=1+1\"", $csv->body);
        self::assertStringContainsString("\"'=cmd@example.com\"", $csv->body);
        self::assertContains('admin.users_exported', $this->adminActions());
    }

    public function testCardShowsEverythingToTheRightRoles(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('rich@example.com', 'Богатый');
        [$invoice, $payment] = $this->payWithFake($workspace, $owner);
        $channel = $this->fakeChannel($workspace, $owner, 'rich-1', 'Мой канал');
        $this->db->execute("INSERT INTO user_identities (user_id, provider, provider_user_id, display_name, linked_at) VALUES (?, 'google', 'g-1', 'Rich G', ?)", [$owner->id, DbTime::format($this->clock->now())]);
        $this->db->execute("INSERT INTO user_attribution (user_id, utm_source, utm_campaign, first_seen_at) VALUES (?, 'tg-ads', 'autumn', ?)", [$owner->id, DbTime::format($this->clock->now())]);
        $this->actAs($owner);
        $this->get('/app');

        $this->staff(StaffRole::Finance);
        $page = $this->plain($this->get('/admin/users/' . $owner->id));
        self::assertStringContainsString('rich@example.com', $page);
        self::assertStringContainsString('Rich G', $page);
        self::assertStringContainsString('tg-ads / autumn', $page);
        self::assertStringContainsString('Мой канал', $page);
        self::assertStringContainsString($invoice->number, $page, 'finance sees the payments');
        self::assertStringContainsString('Начислить дни, кредиты или деньги', $page);

        $this->staff(StaffRole::Support);
        $support = $this->plain($this->get('/admin/users/' . $owner->id));
        self::assertStringNotContainsString($invoice->number, $support, 'support does not see money');
        self::assertStringNotContainsString('Начислить дни', $support);
        self::assertStringContainsString('Заметки сотрудников', $support);
        self::assertContains('admin.user_viewed', $this->adminActions());
    }

    public function testSignOutVerifyEmailNotesAndTwoFactorReset(): void
    {
        $this->staff();
        $person = $this->createUser('lost@example.com', false, null, 'Потеряшка');
        $this->enableTwoFactor($person);
        $url = '/admin/users/' . $person->id;

        $this->post($url . '/verify-email', []);
        self::assertNotNull($this->db->select('SELECT email_verified_at FROM users WHERE id = ?', [$person->id])[0]['email_verified_at']);

        $this->post($url . '/note', ['body' => 'Просил сбросить код по телефону']);
        $this->post($url . '/note', ['body' => '   ']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM admin_notes WHERE user_id = ?', [$person->id])[0]['c']);
        self::assertStringContainsString('Просил сбросить код по телефону', $this->get($url)->body);

        $this->post($url . '/reset-2fa', []);
        self::assertNotNull($this->db->select('SELECT totp_enabled_at FROM users WHERE id = ?', [$person->id])[0]['totp_enabled_at'], 'no code, nothing happens');
        $this->post($url . '/reset-2fa', $this->confirm());
        $row = $this->db->select('SELECT totp_enabled_at, totp_secret_enc FROM users WHERE id = ?', [$person->id])[0];
        self::assertNull($row['totp_enabled_at']);
        self::assertNull($row['totp_secret_enc']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM recovery_codes WHERE user_id = ?', [$person->id])[0]['c']);

        $this->post($url . '/signout', []);
        self::assertContains('admin.user_signed_out', $this->adminActions());
        self::assertContains('admin.user_2fa_reset', $this->adminActions());
        self::assertContains('admin.user_email_verified', $this->adminActions());
    }

    public function testStaffAccountsAreOffLimitsForTheActions(): void
    {
        $boss = $this->staff();
        $helper = $this->createUser('helper@example.com');
        $this->app->container()->get(\App\Domain\Admin\StaffAccess::class)->assign($helper->id, StaffRole::Support, $boss->id);
        $this->enableTwoFactor($helper);
        $url = '/admin/users/' . $helper->id;
        $this->post($url . '/reset-2fa', $this->confirm());
        self::assertNotNull($this->db->select('SELECT totp_enabled_at FROM users WHERE id = ?', [$helper->id])[0]['totp_enabled_at']);
        $this->post($url . '/block', $this->confirm(['reason' => 'x']));
        self::assertSame('active', $this->db->select('SELECT status FROM users WHERE id = ?', [$helper->id])[0]['status']);
        $this->post($url . '/impersonate', $this->confirm());
        self::assertSame(200, $this->get('/admin')->status, 'still the staff member');
        $page = $this->get($url)->body;
        self::assertStringNotContainsString('data-dialog-open="block-user"', $page, 'no block button for staff');
    }

    public function testGrantsGoThroughTheLedger(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('gift@example.com', 'Подарок');
        $this->givePlan($workspace, 'pro');
        $this->staff();
        $url = '/admin/users/' . $owner->id . '/grant';
        $ledger = $this->app->container()->get(Ledger::class);
        $before = $this->periodEnd($workspace->id);

        $this->post($url, ['workspace' => $workspace->publicId, 'unit' => 'days', 'amount' => '10', 'reason' => 'Компенсация за сбой']);
        self::assertSame(0, $ledger->wallet($workspace->publicId)['days'], 'no code: nothing was written');
        self::assertSame($before, $this->periodEnd($workspace->id));

        $this->post($url, $this->confirm(['workspace' => $workspace->publicId, 'unit' => 'days', 'amount' => '10', 'reason' => 'Компенсация за сбой']));
        self::assertSame(10 * 86400, $this->periodEnd($workspace->id) - $before, 'the paid period moves by ten days');
        $this->post($url, $this->confirm(['workspace' => $workspace->publicId, 'unit' => 'credits', 'amount' => '250', 'reason' => 'Подарок к запуску']));
        $this->post($url, $this->confirm(['workspace' => $workspace->publicId, 'unit' => 'balance', 'amount' => '1 500,50', 'reason' => 'Возврат вне платёжной системы']));
        self::assertSame(['days' => 10, 'credits' => 250, 'balance' => 150050], $ledger->wallet($workspace->publicId));
        self::assertSame(0, (int) $this->db->select('SELECT SUM(amount) AS s FROM ledger_entries WHERE ref_type = ?', ['grant'])[0]['s'], 'every grant balances to zero');
        self::assertSame(3, (int) $this->db->select("SELECT COUNT(DISTINCT txn_id) AS c FROM ledger_entries WHERE ref_type = 'grant'")[0]['c']);
        self::assertStringContainsString('Компенсация за сбой', (string) $this->db->select("SELECT memo FROM ledger_entries WHERE ref_type = 'grant' LIMIT 1")[0]['memo']);
        $audit = json_decode((string) $this->db->select("SELECT meta_json FROM audit_log WHERE action = 'admin.grant' ORDER BY id LIMIT 1")[0]['meta_json'], true);
        self::assertSame('days', $audit['kind']);
        self::assertSame(10, $audit['amount']);
        self::assertSame('Компенсация за сбой', $audit['reason']);
        self::assertStringContainsString('1 500,50', $this->plain($this->get('/admin/users/' . $owner->id)));
    }

    public function testGrantRefusals(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('stingy@example.com', 'Жадный');
        [, $other] = $this->ownerWithWorkspace('other@example.com', 'Чужой');
        $this->staff();
        $url = '/admin/users/' . $owner->id . '/grant';
        $ledger = $this->app->container()->get(Ledger::class);
        $try = function (array $fields) use ($url): void {
            $this->post($url, $this->confirm($fields + ['workspace' => $this->workspacePublicId('stingy@example.com'), 'unit' => 'credits', 'amount' => '5', 'reason' => 'Тест']));
        };
        $try(['reason' => '']);
        $try(['amount' => '0']);
        $try(['amount' => '-5']);
        $try(['amount' => '99999999']);
        $try(['unit' => 'gold']);
        $try(['workspace' => $other->publicId]);
        $try(['unit' => 'days', 'amount' => '3']);
        self::assertSame(['days' => 0, 'credits' => 0, 'balance' => 0], $ledger->wallet($workspace->publicId));
        self::assertSame([], $this->db->select("SELECT 1 FROM ledger_entries WHERE ref_type = 'grant'"));
        self::assertSame(0, array_sum($ledger->wallet($other->publicId)), 'a workspace of somebody else gets nothing');
    }

    private function periodEnd(int $workspaceId): int
    {
        return (int) $this->db->select('SELECT UNIX_TIMESTAMP(current_period_end) AS t FROM subscriptions WHERE workspace_id = ?', [$workspaceId])[0]['t'];
    }

    private function workspacePublicId(string $ownerEmail): string
    {
        return (string) $this->db->select('SELECT w.public_id FROM workspaces w JOIN users u ON u.id = w.owner_id WHERE u.email = ?', [$ownerEmail])[0]['public_id'];
    }

    public function testExportContainsOnlyThePersonsOwnData(): void
    {
        [$anna, $annaSpace] = $this->ownerWithWorkspace('anna@example.com', 'Анна');
        [$boris] = $this->ownerWithWorkspace('boris@example.com', 'Борис');
        $this->fakeChannel($annaSpace, $anna, 'a-1', 'Канал Анны');
        $this->db->execute("INSERT INTO user_identities (user_id, provider, provider_user_id, display_name, linked_at) VALUES (?, 'vkid', 'vk-anna', 'Анна ВК', ?)", [$anna->id, DbTime::format($this->clock->now())]);
        $this->staff();

        $this->post('/admin/privacy', ['user' => 'anna@example.com', 'type' => 'export', 'note' => 'по письму']);
        $request = $this->db->select('SELECT public_id, user_id, type FROM data_requests')[0];
        self::assertSame($anna->id, (int) $request['user_id']);

        $json = $this->get('/admin/privacy/' . $request['public_id'] . '/download?format=json');
        self::assertSame(200, $json->status);
        self::assertStringContainsString('attachment', (string) $json->header('Content-Disposition'));
        $data = json_decode($json->body, true);
        self::assertSame('anna@example.com', $data['account']['email']);
        self::assertSame('Канал Анны', $data['channels'][0]['title']);
        self::assertSame('Анна ВК', $data['sign_in_methods'][0]['display_name']);
        self::assertStringNotContainsString('boris@example.com', $json->body);
        self::assertStringNotContainsString('Борис', $json->body);
        self::assertStringNotContainsString('totp_secret', $json->body);
        self::assertStringNotContainsString('password', $json->body);

        $zip = $this->get('/admin/privacy/' . $request['public_id'] . '/download');
        self::assertStringStartsWith('PK', $zip->body);
        $file = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents((string) $file, $zip->body);
        $archive = new \ZipArchive();
        self::assertTrue($archive->open((string) $file));
        self::assertNotFalse($archive->getFromName('data.json'));
        self::assertNotFalse($archive->getFromName('README.txt'));
        $archive->close();
        @unlink((string) $file);
        self::assertSame('done', $this->db->select('SELECT status FROM data_requests WHERE public_id = ?', [$request['public_id']])[0]['status']);
        self::assertContains('admin.data_exported', $this->adminActions());
        self::assertSame($boris->id, (int) $this->db->select('SELECT id FROM users WHERE email = ?', ['boris@example.com'])[0]['id']);
    }

    public function testAnonymisationKeepsMoneyRecordsAndRemovesThePerson(): void
    {
        [$anna, $workspace] = $this->ownerWithWorkspace('leaving@example.com', 'Уходящая');
        [$invoice, $payment] = $this->payWithFake($workspace, $anna);
        $channel = $this->fakeChannel($workspace, $anna, 'gone-1', 'Канал уходящей');
        $this->db->execute("INSERT INTO user_identities (user_id, provider, provider_user_id, linked_at) VALUES (?, 'vkid', 'vk-leaving', ?)", [$anna->id, DbTime::format($this->clock->now())]);
        $this->db->execute("INSERT INTO posts (public_id, workspace_id, author_id, status, base_text, timezone, created_at, updated_at) VALUES ('01JZZZZZZZZZZZZZZZZZZZZZP9', ?, ?, 'draft', 'личный текст', 'UTC', ?, ?)", [$workspace->id, $anna->id, DbTime::format($this->clock->now()), DbTime::format($this->clock->now())]);
        $this->actAs($anna);
        $this->staff();

        $this->post('/admin/privacy', ['user' => (string) $anna->id, 'type' => 'delete']);
        $request = (string) $this->db->select("SELECT public_id FROM data_requests WHERE type = 'delete'")[0]['public_id'];
        $this->post('/admin/privacy/' . $request . '/anonymize', []);
        self::assertSame('leaving@example.com', $this->db->select('SELECT email FROM users WHERE id = ?', [$anna->id])[0]['email'], 'no code, nothing happens');

        $this->post('/admin/privacy/' . $request . '/anonymize', $this->confirm());
        $user = $this->db->select('SELECT email, name, password_hash, status FROM users WHERE id = ?', [$anna->id])[0];
        self::assertNull($user['email']);
        self::assertNull($user['password_hash']);
        self::assertSame('Удалённый пользователь', $user['name']);
        self::assertSame('blocked', $user['status']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM user_identities WHERE user_id = ?', [$anna->id])[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM posts WHERE workspace_id = ?', [$workspace->id])[0]['c']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels WHERE workspace_id = ?', [$workspace->id])[0]['c']);
        // The money records stay.
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices WHERE id = ?', [$invoice->id])[0]['c']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM payments WHERE id = ?', [$payment->id])[0]['c']);
        self::assertGreaterThan(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM ledger_entries')[0]['c']);
        self::assertSame('Удалённое пространство', $this->db->select('SELECT name FROM workspaces WHERE id = ?', [$workspace->id])[0]['name']);
        self::assertSame('done', $this->db->select('SELECT status FROM data_requests WHERE public_id = ?', [$request])[0]['status']);
        self::assertContains('admin.account_anonymized', $this->adminActions());

        // The person cannot come back: no address, no password, and the old session is gone.
        $this->useBrowser();
        self::assertSame('/login', $this->get('/app')->header('Location'));
    }

    public function testAnonymisationRefusals(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('lead@example.com', 'Руководитель');
        $this->memberOf($workspace, 'member@example.com', Role::Editor);
        $boss = $this->staff();
        $actions = $this->app->container()->get(PersonalData::class);
        $error = $actions->anonymize($owner, $boss);
        self::assertStringContainsString('передать владение', (string) $error);
        self::assertSame('lead@example.com', $this->db->select('SELECT email FROM users WHERE id = ?', [$owner->id])[0]['email']);
        self::assertStringContainsString('Сотрудника', (string) $actions->anonymize($boss, $boss));

        $request = $actions->open('nobody@example.com', 'delete', '', $boss);
        $row = $actions->find($request);
        self::assertNotNull($row);
        self::assertNull($row['user_id'], 'a request about nobody known is still recorded');
        $this->post('/admin/privacy/' . $request . '/anonymize', $this->confirm());
        $this->post('/admin/privacy/' . $request . '/reject', []);
        self::assertSame('rejected', $this->db->select('SELECT status FROM data_requests WHERE public_id = ?', [$request])[0]['status']);
        self::assertSame(404, $this->get('/admin/privacy/01JZZZZZZZZZZZZZZZZZZZZZZZ/download')->status);
    }

    public function testOnlyTheOwnerOpensPrivacyRequests(): void
    {
        foreach ([StaffRole::Support, StaffRole::Finance, StaffRole::Content, StaffRole::Analyst] as $role) {
            $this->staff($role);
            self::assertSame(403, $this->get('/admin/privacy')->status, $role->value);
        }
        $this->staff();
        self::assertSame(200, $this->get('/admin/privacy')->status);
    }
}
