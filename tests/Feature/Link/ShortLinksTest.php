<?php

declare(strict_types=1);

namespace App\Tests\Feature\Link;

use App\Domain\Admin\StaffRole;
use App\Domain\Link\LinkSettings;
use App\Domain\Link\ShortLinks;
use App\Domain\Link\UrlGuard;
use App\Domain\Settings\Settings;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Admin\LinksAdminController;
use App\Http\Controllers\Link\LinkSettingsController;
use App\Http\Controllers\Link\ShortLinkController;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** The redirect, the workspace page and the admin area of short links. */
#[CoversClass(ShortLinkController::class)]
#[CoversClass(LinkSettingsController::class)]
#[CoversClass(LinksAdminController::class)]
final class ShortLinksTest extends AdminTestCase
{
    protected function tearDown(): void
    {
        $this->app->container()->get(Settings::class)->forget(UrlGuard::SETTING);
        parent::tearDown();
    }

    private function links(): ShortLinks
    {
        return $this->app->container()->get(ShortLinks::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function inWorkspace(int $workspaceId, string $id): array
    {
        $row = $this->links()->findInWorkspace($workspaceId, $id);
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $code): array
    {
        $row = $this->links()->find($code);
        self::assertNotNull($row);

        return $row;
    }

    public function testAShortLinkRedirectsAndCountsPeopleApartFromRobots(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->links()->createManual($workspace->id, $owner->id, 'https://shop.example/page?a=1#x', 100);

        $person = $this->get('/s/' . $code, ['User-Agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1']);
        $robot = $this->get('/s/' . $code, ['User-Agent' => 'TelegramBot (like TwitterBot)']);

        self::assertSame(302, $person->status);
        self::assertSame('https://shop.example/page?a=1#x', $person->header('Location'));
        self::assertSame('no-store', $person->header('Cache-Control'), 'a later switch-off must take effect at once');
        self::assertSame(302, $robot->status);
        $row = $this->db->select('SELECT clicks, human_clicks FROM short_links WHERE code = ?', [$code])[0];
        self::assertSame([2, 1], [(int) $row['clicks'], (int) $row['human_clicks']]);
        $clicks = $this->db->select('SELECT visitor_hash, is_bot FROM short_link_clicks ORDER BY id');
        self::assertCount(2, $clicks);
        self::assertSame(16, strlen((string) $clicks[0]['visitor_hash']));
        self::assertStringNotContainsString($this->remoteAddr, json_encode($clicks, JSON_THROW_ON_ERROR), 'the address of the visitor is never stored');
    }

    public function testUnknownMalformedAndSwitchedOffCodesNeverRedirect(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->links()->createManual($workspace->id, $owner->id, 'https://shop.example/', 100);

        self::assertSame(404, $this->get('/s/AAAAAAA')->status);
        self::assertSame(404, $this->get('/s/abc')->status);
        self::assertSame(404, $this->get('/s/' . $code . 'x')->status);
        self::assertSame(404, $this->get('/' . $code)->status, 'the bare form is answered on the short domain only');

        $this->links()->setDisabled((int) $this->row($code)['id'], true);
        $gone = $this->get('/s/' . $code);
        self::assertSame(410, $gone->status);
        self::assertNull($gone->header('Location'));
        self::assertStringContainsString('отключили', $gone->body);
    }

    public function testASiteBlockedLaterStopsWorkingAtOnce(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->links()->createManual($workspace->id, $owner->id, 'https://later-bad.example/x', 100);
        self::assertSame(302, $this->get('/s/' . $code)->status);

        $this->app->container()->get(Settings::class)->set(UrlGuard::SETTING, ['later-bad.example'], null);

        self::assertSame(410, $this->get('/s/' . $code)->status);
    }

    public function testTheWorkspacePageIsForOwnersAndAdminsAndKeepsRulesPerWorkspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $channel = $this->fakeChannel($workspace, $owner, 'c1', 'Кофейня');
        $page = $this->base($workspace) . '/settings/links';

        $this->useBrowser();
        self::assertSame(302, $this->get($page)->status, 'guest');
        $this->actAs($editor);
        self::assertSame(403, $this->get($page)->status);
        self::assertSame(403, $this->post($page, ['enabled' => '1', 'source' => 'x'])->status);
        self::assertSame(403, $this->post($page . '/short', ['url' => 'https://a.example/'])->status);

        $this->actAs($owner);
        self::assertSame(200, $this->get($page)->status);
        self::assertStringContainsString('Метки по каналам', $this->get($page)->body);
        $this->post($page, ['enabled' => '1', 'source' => '{platform}', 'medium' => 'social', 'campaign' => '{month}', 'shorten' => '1']);
        $settings = $this->app->container()->get(LinkSettings::class);
        self::assertTrue($settings->workspaceRule($workspace->id)->enabled);
        self::assertTrue($settings->shortens($workspace->id));
        self::assertContains('links.settings_updated', $this->auditActions($workspace));

        $this->post($page . '/channels/' . $channel->publicId, ['mode' => 'off']);
        self::assertFalse($settings->effectiveRule($workspace->id, $channel->id)->enabled);
        $this->post($page . '/channels/' . $channel->publicId, ['mode' => 'custom', 'source' => 'club', 'medium' => 'tg']);
        self::assertSame('club', $settings->effectiveRule($workspace->id, $channel->id)->source);
        $this->post($page . '/channels/' . $channel->publicId, ['mode' => 'inherit']);
        self::assertTrue($settings->effectiveRule($workspace->id, $channel->id)->enabled);
        self::assertSame('{platform}', $settings->effectiveRule($workspace->id, $channel->id)->source);

        // A rule with an unknown placeholder is not saved.
        $this->post($page, ['enabled' => '1', 'source' => '{nope}']);
        self::assertSame('{platform}', $settings->workspaceRule($workspace->id)->source);
    }

    public function testAnotherWorkspacesChannelAndLinkAreNotReachable(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [$otherOwner, $other] = $this->ownerWithWorkspace('other@example.com', 'Иван');
        $foreignChannel = $this->fakeChannel($other, $otherOwner, 'c9', 'Чужой');
        $foreignCode = $this->links()->createManual($other->id, $otherOwner->id, 'https://foreign.example/', 100);
        $foreignId = (string) $this->row($foreignCode)['public_id'];

        $this->actAs($owner);
        $base = $this->base($workspace) . '/settings/links';
        self::assertSame(404, $this->post($base . '/channels/' . $foreignChannel->publicId, ['mode' => 'off'])->status);
        self::assertSame(404, $this->post($base . '/short/' . $foreignId . '/toggle')->status);
        self::assertNull($this->row($foreignCode)['disabled_at']);
    }

    public function testOwnersCreateAndSwitchOffTheirLinksButNotOnesStaffClosed(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $base = $this->base($workspace) . '/settings/links';

        $this->post($base . '/short', ['url' => 'javascript:alert(1)']);
        self::assertCount(0, $this->links()->forWorkspace($workspace->id));
        $this->post($base . '/short', ['url' => 'https://shop.example/hello']);
        $made = $this->links()->forWorkspace($workspace->id);
        self::assertCount(1, $made);
        self::assertArrayHasKey(0, $made);
        self::assertStringContainsString((string) $made[0]['code'], $this->get($base)->body);

        $id = (string) $made[0]['public_id'];
        $this->post($base . '/short/' . $id . '/toggle');
        self::assertNotNull($this->inWorkspace($workspace->id, $id)['disabled_at']);
        $this->post($base . '/short/' . $id . '/toggle');
        self::assertNull($this->inWorkspace($workspace->id, $id)['disabled_at']);

        $this->links()->setDisabled((int) $made[0]['id'], true, 'Жалоба');
        $this->post($base . '/short/' . $id . '/toggle');
        self::assertNotNull($this->inWorkspace($workspace->id, $id)['disabled_at'], 'only staff can undo a closure for abuse');
    }

    public function testStaffSeeAllLinksSwitchOneOffAndKeepTheBlocklist(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $good = $this->links()->createManual($workspace->id, $owner->id, 'https://good.example/a', 100);
        $bad = $this->links()->createManual($workspace->id, $owner->id, 'https://sub.phish.example/login', 100);

        $this->actAs($owner);
        self::assertSame(404, $this->get('/admin/links')->status, 'customers have no back office');

        $this->staff(StaffRole::Analyst);
        self::assertSame(403, $this->get('/admin/links')->status, 'analysts do not manage links');
        $this->staff(StaffRole::Support);
        $list = $this->get('/admin/links');
        self::assertSame(200, $list->status);
        self::assertStringContainsString($good, $list->body);
        self::assertStringContainsString($good, $this->get('/admin/links?q=' . $good)->body);
        self::assertStringNotContainsString($good, $this->get('/admin/links?q=phish')->body);

        $id = (string) $this->row($good)['public_id'];
        $this->post('/admin/links/' . $id . '/toggle', ['reason' => 'Жалоба клиента']);
        self::assertSame('Жалоба клиента', $this->row($good)['disabled_reason']);
        self::assertSame(410, $this->get('/s/' . $good)->status);

        $this->post('/admin/links/blocklist', ['domains' => "phish.example\nhttps://www.Other-Bad.example/x"]);
        self::assertSame(['phish.example', 'other-bad.example'], $this->app->container()->get(UrlGuard::class)->blocked());
        self::assertNotNull($this->row($bad)['disabled_at'], 'existing links to a blocked domain are switched off');
        self::assertContains('admin.link_blocklist_updated', $this->adminActions());
        self::assertContains('admin.link_disabled', $this->adminActions());
    }

    public function testTheRedirectRouteOnTheShortDomainAnswersOnlyThatHost(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $code = $this->links()->createManual($workspace->id, $owner->id, 'https://shop.example/', 100);
        $controller = new ShortLinkController(
            $this->links(),
            $this->app->container()->get(UrlGuard::class),
            $this->app->container()->get(\App\Kernel\View\View::class),
            $this->app->container()->get(\Psr\Log\LoggerInterface::class),
            'go.example.com',
        );

        $onShort = $controller->root(\App\Kernel\Http\Request::create('GET', '/' . $code, headers: ['Host' => 'go.example.com']), $code);
        self::assertSame(302, $onShort->status);
        $this->expectException(\App\Kernel\Exception\HttpException::class);
        $controller->root(\App\Kernel\Http\Request::create('GET', '/' . $code, headers: ['Host' => 'localhost']), $code);
    }
}
