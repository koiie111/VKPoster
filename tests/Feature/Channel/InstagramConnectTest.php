<?php

declare(strict_types=1);

namespace App\Tests\Feature\Channel;

use App\Domain\Channel\InstagramConnections;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Channels\InstagramConnectController;
use App\Integrations\Social\Instagram\InstagramOAuth;
use App\Tests\Support\ChannelTestCase;
use App\Tests\Support\InstagramFixtures;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InstagramConnectController::class)]
#[CoversClass(InstagramConnections::class)]
#[CoversClass(InstagramOAuth::class)]
final class InstagramConnectTest extends ChannelTestCase
{
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';
    private const EXCHANGE_URL = 'https://graph.instagram.com/access_token';

    /**
     * Press "Войти через Instagram" and return the state Instagram would send back.
     */
    private function start(\App\Domain\Workspace\Workspace $workspace): string
    {
        $response = $this->get($this->channelsUrl($workspace, '/connect/instagram/start'));
        self::assertSame(302, $response->status);
        $location = (string) $response->header('Location');
        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['state']);
        self::assertSame('ig-test-app', $query['client_id']);
        self::assertSame('code', $query['response_type']);
        self::assertIsString($query['scope']);
        self::assertStringContainsString('instagram_business_content_publish', $query['scope']);
        self::assertSame('http://localhost/channels/connect/instagram/callback', $query['redirect_uri']);

        return $query['state'];
    }

    private function comeBack(string $state, string $extra = '&code=CODE-1%23_'): \App\Kernel\Http\Response
    {
        return $this->get('/channels/connect/instagram/callback?state=' . rawurlencode($state) . $extra);
    }

    private function expectSignIn(string $profile = 'me'): void
    {
        $this->http->expect('POST', self::TOKEN_URL, 200, InstagramFixtures::raw('token_short'));
        $this->http->expect('GET', self::EXCHANGE_URL, 200, InstagramFixtures::raw('token_long'));
        $this->http->expect('GET', InstagramFixtures::GRAPH . 'me', 200, InstagramFixtures::raw($profile));
    }

    public function testTheWholeFlowConnectsTheAccount(): void
    {
        [, $workspace] = $this->ownerSession();
        self::assertStringContainsString('Войти через Instagram', $this->text($this->get($this->channelsUrl($workspace, '/connect/instagram'))));

        $state = $this->start($workspace);
        $this->expectSignIn();
        $back = $this->comeBack($state);

        self::assertSame($this->channelsUrl($workspace), $back->header('Location'));
        $exchange = $this->http->requests[0]['options']['form_params'];
        self::assertSame('authorization_code', $exchange['grant_type']);
        self::assertSame('CODE-1', $exchange['code'], 'the "#_" Instagram appends is cut off');
        self::assertSame('ig-test-secret', $exchange['client_secret']);
        self::assertSame('ig_exchange_token', $this->http->requests[1]['options']['query']['grant_type']);
        self::assertSame('SHORT-1', $this->http->requests[1]['options']['query']['access_token']);

        $rows = $this->db->select('SELECT * FROM channels WHERE workspace_id = ?', [$workspace->id]);
        self::assertCount(1, $rows);
        self::assertSame('instagram', $rows[0]['platform']);
        self::assertSame(InstagramFixtures::ACCOUNT, $rows[0]['external_id']);
        self::assertSame('account', $rows[0]['mode']);
        self::assertSame('coffee_zerno', $rows[0]['username']);
        $credential = $this->db->select('SELECT * FROM platform_credentials WHERE id = ?', [$rows[0]['credential_id']])[0];
        self::assertSame('oauth', $credential['kind']);
        self::assertNull($credential['refresh_enc']);
        self::assertStringNotContainsString('LONG-1', (string) $credential['secret_enc'], 'the token is stored encrypted');
        self::assertNotNull($credential['expires_at']);
        self::assertContains('channel.connected', $this->auditActions($workspace));

        $list = $this->text($this->follow($back));
        self::assertStringContainsString('Кофейня «Зерно»', $list);
        self::assertStringContainsString('@coffee_zerno', $list);
        self::assertStringNotContainsString('не хватает права', $list, 'what Instagram cannot do at all is not a missing right');
    }

    public function testConnectingTheSameAccountAgainKeepsTheChannelAndReplacesTheCredential(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->expectSignIn();
        $this->comeBack($this->start($workspace));
        $first = $this->db->select('SELECT id, credential_id FROM channels')[0];

        $this->expectSignIn();
        $this->comeBack($this->start($workspace));

        $rows = $this->db->select('SELECT id, credential_id FROM channels');
        self::assertCount(1, $rows);
        self::assertSame($first['id'], $rows[0]['id']);
        self::assertNotSame($first['credential_id'], $rows[0]['credential_id']);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM platform_credentials')[0]['n'], 'the old token is dropped');
    }

    public function testAPersonalAccountIsRefusedAndNothingIsKept(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->expectSignIn('me_personal');

        $back = $this->comeBack($this->start($workspace));

        self::assertSame($this->channelsUrl($workspace, '/connect/instagram'), $back->header('Location'));
        self::assertStringContainsString('личный', $this->follow($back)->body);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM channels')[0]['n']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM platform_credentials')[0]['n']);
    }

    public function testAForgedOrReusedStateIsRefusedAndNothingIsExchanged(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);

        self::assertSame('/app', $this->comeBack('forged-state')->header('Location'));
        self::assertSame('/app', $this->comeBack($state)->header('Location'), 'the flow was consumed by the failed attempt');
        self::assertSame([], $this->http->requests);
    }

    public function testDecliningAccessSendsThePersonBack(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);

        $back = $this->comeBack($state, '&error=access_denied');

        self::assertSame($this->channelsUrl($workspace, '/connect/instagram'), $back->header('Location'));
        self::assertSame([], $this->http->requests);
        self::assertStringContainsString('не разрешили', $this->follow($back)->body);
    }

    public function testAnExchangeThatInstagramRefusesKeepsNothingAndShowsNoInternals(): void
    {
        [, $workspace] = $this->ownerSession();
        $state = $this->start($workspace);
        $this->http->expect('POST', self::TOKEN_URL, 400, InstagramFixtures::raw('oauth_error'));

        $back = $this->comeBack($state);

        self::assertSame($this->channelsUrl($workspace, '/connect/instagram'), $back->header('Location'));
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM platform_credentials')[0]['n']);
        self::assertStringNotContainsString('Invalid authorization code', $this->follow($back)->body);
    }

    public function testRolesWithoutTheRightCannotStart(): void
    {
        [, $workspace] = $this->ownerSession();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $this->actAs($editor);

        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/instagram'))->status);
        self::assertSame(403, $this->get($this->channelsUrl($workspace, '/connect/instagram/start'))->status);
    }

    public function testAStateStartedForAWorkspaceDoesNotWorkForAMemberWhoLostTheRight(): void
    {
        [, $workspace] = $this->ownerSession();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->actAs($admin);
        $state = $this->start($workspace);
        $this->db->execute('UPDATE workspace_members SET role = ? WHERE workspace_id = ? AND user_id = ?', [Role::Editor->value, $workspace->id, $admin->id]);

        $response = $this->comeBack($state);

        self::assertSame(404, $response->status);
        self::assertSame([], $this->http->requests);
    }

    public function testTheMenuOffersInstagramAndGuestsAreSentToLogin(): void
    {
        [, $workspace] = $this->ownerSession();
        $page = $this->get($this->channelsUrl($workspace));
        self::assertStringContainsString('href="' . $this->channelsUrl($workspace, '/connect/instagram') . '"', $page->body);

        $this->useBrowser();
        $guest = $this->get('/channels/connect/instagram/callback?state=x&code=y');
        self::assertSame(302, $guest->status);
        self::assertStringContainsString('/login', (string) $guest->header('Location'));
    }

    public function testTheNetworkIsInvisibleWhenTheOwnerSwitchedItOff(): void
    {
        [, $workspace] = $this->ownerSession();
        $settings = $this->app->container()->get(\App\Domain\Settings\Settings::class);
        $settings->set('platforms.off', ['instagram'], null);

        try {
            self::assertSame(404, $this->get($this->channelsUrl($workspace, '/connect/instagram'))->status);
            self::assertStringNotContainsString('/connect/instagram', $this->get($this->channelsUrl($workspace))->body);
        } finally {
            $settings->forget('platforms.off');
        }
    }

    public function testTheEditorOffersThePostFormatOnlyForInstagram(): void
    {
        [$owner, $workspace] = $this->ownerSession();
        $this->makeChannel($workspace, $owner, InstagramFixtures::ACCOUNT, 'Зерно', platform: \App\Integrations\Social\Contracts\Platform::Instagram);
        $this->makeChannel($workspace, $owner, '-1001', 'Телеграм');

        $page = $this->get($this->base($workspace) . '/posts/new')->body;

        self::assertStringContainsString('Что опубликовать', $page);
        self::assertStringContainsString('Reels (одно видео)', $page);
        self::assertSame(1, substr_count($page, '&quot;post_format&quot;:true'), 'only the Instagram card claims the format choice');
    }
}
