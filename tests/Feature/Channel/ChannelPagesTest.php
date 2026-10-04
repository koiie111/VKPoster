<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Workspace\ChannelAccessRepository;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Channels\ChannelController;
use App\Tests\Support\ChannelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ChannelController::class)]
final class ChannelPagesTest extends ChannelTestCase
{
    public function testGuestsAreSentToTheLoginPage(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        $response = $this->get($this->channelsUrl($workspace));

        self::assertSame(302, $response->status);
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    public function testAnOutsiderGetsANotFoundNotAForbidden(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($this->createUser('stranger@example.com'));

        self::assertSame(404, $this->get($this->channelsUrl($workspace))->status);
    }

    public function testAnEmptyListInvitesTheOwnerToConnectTelegram(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->channelsUrl($workspace));

        self::assertSame(200, $page->status);
        $text = $this->text($page);
        self::assertStringContainsString('Пока нет подключённых каналов', $text);
        self::assertStringContainsString('Подключить Telegram', $text);
        self::assertStringContainsString('Подключить канал', $text, 'the menu with the platforms');
        self::assertStringContainsString('href="' . $this->channelsUrl($workspace, '/connect/telegram') . '"', $page->body);
    }

    public function testTheSidebarLinksToChannels(): void
    {
        [, $workspace] = $this->ownerSession();

        self::assertStringContainsString('href="' . $this->channelsUrl($workspace) . '"', $this->get($this->base($workspace))->body);
    }

    public function testTheListShowsChannelsWithStatusesAndEscapesNames(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $this->makeChannel($workspace, $owner, '-1001', '<script>alert(1)</script>');
        $broken = $this->makeChannel($workspace, $owner, '-1002', 'Сломанный');
        $this->db->execute('UPDATE channels SET status = ?, last_error = ? WHERE id = ?', ['revoked', 'Бота удалили из канала.', $broken->id]);
        $paused = $this->makeChannel($workspace, $owner, '-1003', 'Пауза');
        $this->db->execute('UPDATE channels SET status = ? WHERE id = ?', ['paused', $paused->id]);

        $page = $this->get($this->channelsUrl($workspace));

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
        self::assertStringNotContainsString('<script>alert(1)', $page->body);
        $text = $this->text($page);
        self::assertStringContainsString('Подключён', $text);
        self::assertStringContainsString('Нужно переподключить', $text);
        self::assertStringContainsString('На паузе', $text);
        self::assertStringContainsString('Бота удалили из канала.', $text);
        self::assertStringContainsString('Проверить сейчас', $text);
        self::assertStringContainsString('Переподключить', $text);
    }

    public function testRightsTheBotLacksAreMentioned(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $this->makeChannel($workspace, $owner, '-1001', 'Канал', settings: ['rights' => ['post' => true, 'delete' => false, 'pin' => false]]);

        self::assertStringContainsString('Боту не хватает права: удалять посты, закреплять', $this->text($this->get($this->channelsUrl($workspace))));
    }

    public function testAViewerSeesTheListWithoutAnyActions(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->makeChannel($workspace, $owner);
        $this->actAsMember($workspace, 'viewer@example.com', Role::Viewer);

        $page = $this->get($this->channelsUrl($workspace));

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Мой канал', $page->body);
        self::assertStringNotContainsString('Подключить канал', $page->body);
        self::assertStringNotContainsString('/pause', $page->body);
        self::assertStringNotContainsString('/delete', $page->body);
    }

    public function testClientsDoNotGetTheChannelSection(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->actAsMember($workspace, 'client@example.com', Role::Client);

        self::assertSame(403, $this->get($this->channelsUrl($workspace))->status);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonManagers(): array
    {
        return ['editor' => [Role::Editor], 'author' => [Role::Author], 'viewer' => [Role::Viewer]];
    }

    #[DataProvider('nonManagers')]
    public function testOnlyOwnersAndAdministratorsMayChangeChannels(Role $role): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $this->actAsMember($workspace, 'member@example.com', $role);
        $item = $this->channelsUrl($workspace, '/' . $channel->publicId);

        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/telegram'))->status);
        self::assertSame(403, $this->post($this->channelsUrl($workspace, '/connect/telegram/code'))->status);
        self::assertSame(403, $this->post($this->channelsUrl($workspace, '/connect/telegram/own'), ['token' => 'x', 'reference' => 'y'])->status);
        foreach (['/pause', '/resume', '/check', '/delete', '/rename'] as $action) {
            self::assertSame(403, $this->post($item . $action, ['name' => 'x'])->status, $action);
        }
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testAnAdministratorMayManage(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        $this->actAsMember($workspace, 'admin@example.com', Role::Admin);

        $response = $this->post($this->channelsUrl($workspace, '/' . $channel->publicId . '/pause'));

        self::assertSame(302, $response->status);
        self::assertSame('paused', $this->db->select('SELECT status FROM channels WHERE id = ?', [$channel->id])[0]['status']);
    }

    public function testRestrictedMembersSeeOnlyTheirChannels(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $mine = $this->makeChannel($workspace, $owner, '-1001', 'Назначенный');
        $other = $this->makeChannel($workspace, $owner, '-1002', 'Чужой');
        $author = $this->memberOf($workspace, 'author@example.com', Role::Author);
        $this->app->container()->get(ChannelAccessRepository::class)->set($this->contextFor($workspace, $owner), $author->id, [$mine->id]);
        $this->actAs($author);

        $page = $this->get($this->channelsUrl($workspace));

        self::assertStringContainsString('Назначенный', $page->body);
        self::assertStringNotContainsString('Чужой', $page->body);
        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/' . $other->publicId . '/avatar'))->status);
    }

    public function testAChannelOfAnotherWorkspaceIsNotFound(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->makeChannel($workspace, $owner);
        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace('other@example.com', 'Борис');
        $this->actAs($otherOwner);

        foreach (['/pause', '/resume', '/check', '/delete', '/rename'] as $action) {
            self::assertSame(404, $this->post($this->channelsUrl($otherWorkspace, '/' . $channel->publicId . $action), ['name' => 'x'])->status, $action);
        }
        self::assertSame(404, $this->get($this->channelsUrl($otherWorkspace, '/' . $channel->publicId . '/avatar'))->status);
        self::assertSame('active', $this->db->select('SELECT status FROM channels WHERE id = ?', [$channel->id])[0]['status']);
        self::assertSame(404, $this->get($this->channelsUrl($workspace))->status, 'and the first workspace itself is closed to this person');
    }

    public function testStateChangingRoutesNeedACsrfToken(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $channel = $this->makeChannel($workspace, $owner);

        $response = $this->request('POST', $this->channelsUrl($workspace, '/' . $channel->publicId . '/delete'));

        self::assertSame(419, $response->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels')[0]['c']);
    }

    public function testTheConnectPageExplainsTheSteps(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->channelsUrl($workspace, '/connect/telegram'));

        self::assertSame(200, $page->status);
        $text = $this->text($page);
        self::assertStringContainsString('Добавьте бота администратором канала', $text);
        self::assertStringContainsString('@ezposter_bot', $text);
        self::assertStringContainsString('Публикация сообщений', $text);
        self::assertStringContainsString('Получить код', $text);
        self::assertStringContainsString('Свой бот', $text);
        self::assertStringNotContainsString('data-testid="connect-code"', $page->body, 'no code before the owner asks for one');
    }

    public function testAskingForACodeShowsItAndReloadingKeepsIt(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->post($this->channelsUrl($workspace, '/connect/telegram/code'));
        $first = $this->get($this->channelsUrl($workspace, '/connect/telegram'))->body;
        $second = $this->get($this->channelsUrl($workspace, '/connect/telegram'))->body;

        $a = $this->shownCode($first);
        self::assertSame($a, $this->shownCode($second));
        self::assertStringContainsString('/connect ' . str_replace('-', '', $a), $first);
        self::assertStringContainsString('data-status-url=', $first);
        self::assertSame(64, strlen((string) $this->db->select('SELECT code_hash FROM channel_connect_codes')[0]['code_hash']), 'only the hash is stored');
        self::assertStringNotContainsString(str_replace('-', '', $a), (string) json_encode($this->db->select('SELECT * FROM channel_connect_codes')));
    }

    public function testAnExpiredCodeIsReplacedByTheButton(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->post($this->channelsUrl($workspace, '/connect/telegram/code'));
        $this->clock->advance(901);

        $page = $this->get($this->channelsUrl($workspace, '/connect/telegram'));

        self::assertStringNotContainsString('data-testid="connect-code"', $page->body);
        self::assertStringContainsString('Получить код', $this->text($page));
    }

    public function testTheStatusOfSomeoneElsesCodeIsNotFound(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->issueCode($workspace, $owner);
        $publicId = (string) $this->db->select('SELECT public_id FROM channel_connect_codes')[0]['public_id'];
        $this->actAsMember($workspace, 'admin@example.com', Role::Admin);

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/telegram/status/' . $publicId))->status);
        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/telegram/status/01ARZ3NDEKTSV4RRFFQ69G5FAV'))->status);
    }

    public function testPlatformsThatAreNotEnabledOrNotBuiltAreNotFound(): void
    {
        [, $workspace] = $this->ownerSession();

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/vk'))->status);
        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/bogus'))->status);
    }

    public function testTelegramPagesDisappearWhenTheFeatureFlagIsOff(): void
    {
        $this->app = \App\Tests\Support\TestEnv::app(['PLATFORMS_ENABLED' => 'fake']);
        $this->app->container()->instance(\App\Kernel\HttpClient\HttpClientInterface::class, $this->http);
        $this->app->container()->instance(\App\Support\Clock::class, $this->clock);
        [, $workspace] = $this->ownerSession();

        self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/telegram'))->status);
        self::assertSame(404, $this->post($this->channelsUrl($workspace, '/connect/telegram/code'))->status);
        self::assertStringNotContainsString('/connect/telegram', $this->get($this->channelsUrl($workspace))->body);
    }

    public function testTheTestNetworkChannelCanBeConnectedWhereItIsAllowed(): void
    {
        [, $workspace] = $this->ownerSession();
        self::assertSame(200, $this->get($this->channelsUrl($workspace, '/connect/fake'))->status);

        $this->post($this->channelsUrl($workspace, '/connect/fake'), ['name' => 'Песочница']);

        $page = $this->get($this->channelsUrl($workspace));
        self::assertStringContainsString('Песочница', $page->body);
        self::assertContains('channel.connected', $this->auditActions($workspace));
    }

    public function testTheTestNetworkDoesNotExistInProduction(): void
    {
        $this->app = \App\Tests\Support\TestEnv::app(['APP_ENV' => 'production', 'PLATFORMS_ENABLED' => 'telegram,fake', 'APP_URL' => 'https://example.com', 'SESSION_SECURE' => '1']);
        $registry = $this->app->container()->get(\App\Integrations\Social\PlatformRegistry::class);

        self::assertSame([\App\Integrations\Social\Contracts\Platform::Telegram], $registry->enabled());
    }

    /**
     * The code printed on the connect page, as the person sees it (`ABCDE-FGHJK`).
     */
    private function shownCode(string $html): string
    {
        if (preg_match('/data-testid="connect-code">([A-Z2-9-]{11})</', $html, $m) !== 1) {
            self::fail('the page shows no connect code');
        }

        return $m[1];
    }
}
