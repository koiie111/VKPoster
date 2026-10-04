<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Channel\VkConnections;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Channels\VkConnectController;
use App\Integrations\Social\Vk\VkOAuth;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\VkFixtures;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(VkConnectController::class)]
#[CoversClass(VkConnections::class)]
#[CoversClass(VkOAuth::class)]
final class VkConnectTest extends ChannelTestCase
{
    private const TOKEN_URL = 'https://id.vk.com/oauth2/auth';

    /**
     * Press "Войти через ВКонтакте" and return the state VK would send back.
     */
    private function start(\App\Domain\Workspace\Workspace $workspace): string
    {
        $response = $this->get($this->channelsUrl($workspace, '/connect/vk/start'));
        self::assertSame(302, $response->status);
        $location = (string) $response->header('Location');
        self::assertStringStartsWith('https://id.vk.com/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['state']);
        self::assertSame('vk-test-app', $query['client_id']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('wall photos video docs groups', $query['scope']);
        self::assertSame('http://localhost/channels/connect/vk/callback', $query['redirect_uri']);
        self::assertNotEmpty($query['code_challenge']);

        return $query['state'];
    }

    private function comeBack(string $state, string $extra = '&code=CODE-1&device_id=DEVICE-1'): \App\Kernel\Http\Response
    {
        return $this->get('/channels/connect/vk/callback?state=' . rawurlencode($state) . $extra);
    }

    private function expectSignIn(): void
    {
        $this->http->expect('POST', self::TOKEN_URL, 200, VkFixtures::raw('oauth_tokens'));
        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::raw('groups_get'));
    }

    public function testTheWholeFlowConnectsTheChosenCommunities(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        self::assertStringContainsString('Войти через ВКонтакте', $this->text($this->get($this->channelsUrl($workspace, '/connect/vk'))));

        $state = $this->start($workspace);
        $this->expectSignIn();
        $back = $this->comeBack($state);
        self::assertSame($this->channelsUrl($workspace, '/connect/vk/choose'), $back->header('Location'));

        // The code was exchanged with the PKCE verifier and the device id VK sent.
        $exchange = $this->http->requests[0]['options']['form_params'];
        self::assertSame('authorization_code', $exchange['grant_type']);
        self::assertSame('CODE-1', $exchange['code']);
        self::assertSame('DEVICE-1', $exchange['device_id']);
        self::assertGreaterThanOrEqual(43, strlen($exchange['code_verifier']));

        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::raw('groups_get'));
        $page = $this->text($this->follow($back));
        self::assertStringContainsString('Моё сообщество', $page);
        self::assertStringContainsString('Редакторская группа', $page);
        self::assertStringNotContainsString('Удалённое', $page, 'a deleted community is not offered');

        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::raw('groups_get'));
        $done = $this->post($this->channelsUrl($workspace, '/connect/vk/choose'), ['communities' => ['777', '888', '123456']]);
        self::assertSame($this->channelsUrl($workspace), $done->header('Location'));

        $rows = $this->db->select('SELECT * FROM channels WHERE workspace_id = ? ORDER BY external_id', [$workspace->id]);
        self::assertCount(2, $rows, 'the forged id 123456 is not a community of this account');
        self::assertSame(['777', '888'], array_column($rows, 'external_id'));
        self::assertSame('account', $rows[0]['mode']);
        self::assertSame('vk', $rows[0]['platform']);
        self::assertSame($rows[0]['credential_id'], $rows[1]['credential_id'], 'both communities share one sign-in');
        self::assertSame('mygroup', $rows[0]['username']);

        $credential = $this->db->select('SELECT * FROM platform_credentials WHERE id = ?', [$rows[0]['credential_id']])[0];
        self::assertSame('oauth', $credential['kind']);
        self::assertSame('DEVICE-1', $credential['device_id']);
        self::assertSame('12345', $credential['account_id']);
        self::assertStringNotContainsString('ACCESS-1', (string) $credential['secret_enc'], 'tokens are stored encrypted');
        self::assertStringNotContainsString('REFRESH-1', (string) $credential['refresh_enc']);
        self::assertNotNull($credential['expires_at']);
        self::assertCount(2, array_filter($this->auditActions($workspace), static fn (string $a): bool => $a === 'channel.connected'));

        $list = $this->text($this->get($this->channelsUrl($workspace)));
        self::assertStringContainsString('Моё сообщество', $list);
        self::assertStringContainsString('vk.com/mygroup', $list);
        self::assertStringContainsString('через ваш аккаунт', $list);
    }

    public function testAForgedOrReusedStateIsRefusedAndNothingIsExchanged(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);

        $wrong = $this->comeBack('forged-state');
        self::assertSame('/app', $wrong->header('Location'));
        self::assertSame([], $this->http->requests, 'no call to VK for a bad state');

        // The flow was consumed by the failed attempt: even the right state no longer works.
        $again = $this->comeBack($state);
        self::assertSame('/app', $again->header('Location'));
        self::assertSame([], $this->http->requests);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM platform_credentials')[0]['n']);
    }

    public function testDecliningAccessOnVkSendsThePersonBack(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);

        $back = $this->comeBack($state, '&error=access_denied');

        self::assertSame($this->channelsUrl($workspace, '/connect/vk'), $back->header('Location'));
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('не разрешили', $this->follow($back)->body, 'the reason is in the toast');
    }

    public function testAnExchangeThatVkRefusesKeepsNothing(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);
        $this->http->expect('POST', self::TOKEN_URL, 400, VkFixtures::raw('oauth_error'));

        $back = $this->comeBack($state);

        self::assertSame($this->channelsUrl($workspace, '/connect/vk'), $back->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM platform_credentials')[0]['n']);
        self::assertStringNotContainsString('invalid_grant', $this->follow($back)->body, 'VK internals are not shown');
    }

    public function testChoosingOnlyCommunitiesOfSomebodyElseConnectsNothingAndDropsTheToken(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->expectSignIn();
        $this->comeBack($this->start($workspace));

        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::raw('groups_get'));
        $response = $this->post($this->channelsUrl($workspace, '/connect/vk/choose'), ['communities' => ['424242']]);

        self::assertSame($this->channelsUrl($workspace, '/connect/vk/choose'), $response->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM channels')[0]['n']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM platform_credentials')[0]['n'], 'an unused token is not kept');
    }

    public function testSelectingNothingAsksToChoose(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->expectSignIn();
        $this->comeBack($this->start($workspace));

        $response = $this->post($this->channelsUrl($workspace, '/connect/vk/choose'), []);
        $this->http->expect('POST', VkFixtures::API . 'groups.get', 200, VkFixtures::raw('groups_get'));

        self::assertStringContainsString('хотя бы одно', $this->follow($response)->body);
    }

    public function testChoosingWithoutSigningInFirstStartsOver(): void
    {
        [, $workspace] = $this->ownerSession();

        $response = $this->get($this->channelsUrl($workspace, '/connect/vk/choose'));

        self::assertSame($this->channelsUrl($workspace, '/connect/vk'), $response->header('Location'));
    }

    public function testAnotherMemberCannotUseTheFlowOfSomebodyElse(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);
        $intruder = $this->createUser('intruder@example.com');
        $this->actAs($intruder);

        // The state is in the first person's session; the intruder has none, so the callback does nothing.
        $response = $this->comeBack($state);

        self::assertSame('/app', $response->header('Location'));
        self::assertSame([], $this->http->requests);
    }

    public function testRolesWithoutTheRightCannotStartOrChoose(): void
    {
        [, $workspace] = $this->ownerSession();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $this->actAs($editor);

        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/vk'))->status);
        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/vk/start'))->status);
        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/vk/choose'))->status);
        self::assertSame(403, $this->post($this->channelsUrl($workspace, '/connect/vk/choose'), ['communities' => ['777']])->status);
    }

    public function testAStateStartedForAWorkspaceDoesNotWorkForAMemberWhoLostTheRight(): void
    {
        [, $workspace] = $this->ownerSession();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->actAs($admin);
        $state = $this->start($workspace);
        // Demoted between pressing the button and coming back.
        $this->db->execute('UPDATE workspace_members SET role = ? WHERE workspace_id = ? AND user_id = ?', [Role::Editor->value, $workspace->id, $admin->id]);

        $response = $this->comeBack($state);

        self::assertSame(404, $response->status);
        self::assertSame([], $this->http->requests);
    }

    public function testTheMenuOffersVkAndGuestsAreSentToLogin(): void
    {
        [, $workspace] = $this->ownerSession();
        $page = $this->get($this->channelsUrl($workspace));
        self::assertStringContainsString('href="' . $this->channelsUrl($workspace, '/connect/vk') . '"', $page->body);

        $this->useBrowser();
        $guest = $this->get('/channels/connect/vk/callback?state=x&code=y&device_id=z');
        self::assertSame(302, $guest->status);
        self::assertStringContainsString('/login', (string) $guest->header('Location'));
    }
}
