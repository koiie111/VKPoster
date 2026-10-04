<?php

declare(strict_types=1);

namespace App\Tests\Feature\Billing;

use App\Domain\Channel\ChannelException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Channel\ChannelService;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Post\PostException;
use App\Domain\Workspace\Role;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\PlanFeature;
use App\Http\Middleware\ResolveWorkspace;
use App\Kernel\Http\Router;
use App\Tests\Support\BillingTestCase;
use App\Tests\Support\TestController;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Limits hold at the service level (not only in the interface) and the interface explains them with a way out:
 * a banner with a link to the plans, never a bare error.
 */
#[CoversClass(ChannelService::class)]
#[CoversClass(PlanFeature::class)]
final class PlanLimitsTest extends BillingTestCase
{
    private function bannerLink(\App\Domain\Workspace\Workspace $workspace): string
    {
        return '/w/' . $workspace->publicId . '/billing/plans';
    }

    private function assertBanner(string $html, \App\Domain\Workspace\Workspace $workspace, string $contains): void
    {
        self::assertStringContainsString('Это ограничение тарифа', $html);
        self::assertStringContainsString($contains, str_replace("\u{00A0}", ' ', strip_tags($html)));
        self::assertStringContainsString('href="' . $this->bannerLink($workspace) . '"', $html);
        self::assertStringContainsString('Перейти на тариф', $html);
    }

    // ---- channels -------------------------------------------------------------------------------------------------

    public function testTheThirdChannelOnFreeIsRefusedByTheService(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->makeChannel($workspace, $owner, '-1001');
        $this->makeChannel($workspace, $owner, '-1002');

        try {
            $this->app->container()->get(ChannelService::class)->connectFake($this->contextFor($workspace, $owner), 'Третий');
            self::fail('the limit must hold below the controller');
        } catch (ChannelException $e) {
            self::assertTrue($e->planLimit);
            self::assertStringContainsString('до 2 каналов', $e->getMessage());
        }
        self::assertSame(2, $this->app->container()->get(ChannelRepository::class)->count($this->contextFor($workspace, $owner)));
    }

    public function testTheInterfaceExplainsAChannelLimitAndLinksToThePlans(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->makeChannel($workspace, $owner, '-1001');
        $this->makeChannel($workspace, $owner, '-1002');
        $this->actAs($owner);

        $response = $this->post($this->channelsUrl($workspace, '/connect/fake'), ['name' => 'Третий']);

        self::assertSame(302, $response->status);
        $this->assertBanner($this->get($this->channelsUrl($workspace))->body, $workspace, 'На тарифе «Free» можно подключить до 2 каналов');
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM channels WHERE workspace_id = ?', [$workspace->id])[0]['c']);
    }

    public function testTheChannelListSaysHowManyPlacesThePlanHasAndOnlyTheOwnerGetsTheLink(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->makeChannel($workspace, $owner, '-1001');
        $this->makeChannel($workspace, $owner, '-1002');
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $this->actAs($owner);
        $ownerPage = $this->get($this->channelsUrl($workspace));
        $this->actAs($admin);
        $adminPage = $this->get($this->channelsUrl($workspace));

        self::assertStringContainsString('По тарифу «Free» работают каналов: 2 из 2', $this->plain($ownerPage));
        self::assertStringContainsString('Мест больше нет', $this->plain($ownerPage));
        self::assertStringContainsString('href="' . $this->bannerLink($workspace) . '"', $ownerPage->body);
        self::assertStringContainsString('работают каналов: 2 из 2', $this->plain($adminPage));
        self::assertStringNotContainsString($this->bannerLink($workspace), $adminPage->body, 'an administrator cannot open the plans, so no link');
    }

    public function testTheBannerShowsOnceAndThenGoesAway(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['channels' => 0]);
        $this->actAs($owner);
        $this->post($this->channelsUrl($workspace, '/connect/fake'), ['name' => 'Любой']);

        self::assertStringContainsString('Это ограничение тарифа', $this->get($this->channelsUrl($workspace))->body);
        self::assertStringNotContainsString('Это ограничение тарифа', $this->get($this->channelsUrl($workspace))->body);
    }

    public function testPausingAChannelGivesItsPlaceBackAndResumingNeedsRoom(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $context = $this->contextFor($workspace, $owner);
        $first = $this->makeChannel($workspace, $owner, '-1001', 'Первый');
        $this->makeChannel($workspace, $owner, '-1002', 'Второй');
        $this->actAs($owner);

        $this->post($this->channelsUrl($workspace, '/' . $first->publicId . '/pause'));
        $this->post($this->channelsUrl($workspace, '/connect/fake'), ['name' => 'Новый']);
        $channels = $this->app->container()->get(ChannelRepository::class);
        self::assertSame(3, $channels->count($context), 'a paused channel makes room for a new one');

        $this->post($this->channelsUrl($workspace, '/' . $first->publicId . '/resume'));

        self::assertSame(ChannelStatus::Paused, $channels->findById($context, $first->id)?->status, 'there is no room to resume it');
        $this->assertBanner($this->get($this->channelsUrl($workspace))->body, $workspace, 'можно подключить до 2 каналов');
    }

    public function testResumingWorksWhenThereIsRoom(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $first = $this->makeChannel($workspace, $owner, '-1001', 'Первый');
        $this->actAs($owner);
        $this->post($this->channelsUrl($workspace, '/' . $first->publicId . '/pause'));
        $this->tg('getChat', 'get_chat_channel');
        $this->tg('getChatMember', 'member_bot_admin');

        $this->post($this->channelsUrl($workspace, '/' . $first->publicId . '/resume'));

        self::assertSame(ChannelStatus::Active, $this->app->container()->get(ChannelRepository::class)->findById($this->contextFor($workspace, $owner), $first->id)?->status);
    }

    public function testTheCodeFlowIsRefusedBeforeACodeIsIssued(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['channels' => 0]);
        $this->actAs($owner);

        $this->post($this->channelsUrl($workspace, '/connect/telegram/code'));

        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM channel_connect_codes')[0]['c']);
        $this->assertBanner($this->get($this->channelsUrl($workspace, '/connect/telegram'))->body, $workspace, 'можно подключить до 0 каналов');
    }

    // ---- posts ----------------------------------------------------------------------------------------------------

    public function testThePostAllowanceOfAMonthHoldsInTheService(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['posts_per_month' => 2]);
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $this->scheduled($context, [$channel], '+1 day');
        [$second] = $this->scheduled($context, [$channel], '+2 days');

        try {
            $this->scheduled($context, [$channel], '+3 days');
            self::fail('the third post of the month must be refused');
        } catch (PostException $e) {
            self::assertTrue($e->planLimit);
            self::assertStringContainsString('до 2 постов в месяц', $e->getMessage());
        }

        $this->service()->saveDraft($context, null, $this->draft('черновик', [$channel]));
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM posts WHERE status = 'draft'")[0]['c'], 'drafts are not limited');
        $this->service()->reschedule($context, $second, $this->in('+2 days +3 hours'));
        $this->service()->cancel($context, $second);
        $this->scheduled($context, [$channel], '+3 days');
        self::assertSame(2, (int) $this->db->select("SELECT COUNT(*) AS c FROM posts WHERE status = 'scheduled'")[0]['c'], 'a cancelled post gives its place back');
    }

    public function testMovingAPostIntoAFullMonthIsRefusedButIntoAFreeMonthIsFine(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['posts_per_month' => 1]);
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $this->db->execute('UPDATE workspaces SET timezone = ? WHERE id = ?', ['UTC', $workspace->id]);
        $context = $this->contextFor($workspace, $owner);
        $this->clock->set('2026-10-04 12:00:00');
        [$post] = $this->scheduled($context, [$channel], '+1 day');
        self::assertSame('2026-10-05', $post->scheduledAt?->format('Y-m-d'));

        $moved = $this->service()->reschedule($context, $post, $this->in('+20 days'));
        self::assertSame('2026-10-24', $moved->scheduledAt?->format('Y-m-d'), 'moving inside the month costs nothing');

        $this->service()->reschedule($context, $moved, $this->in('+40 days'));
        $other = $this->scheduled($context, [$channel], '+1 day');

        self::assertNotEmpty($other, 'the old month is free again, since the post moved away');
        $this->expectException(PostException::class);
        $this->service()->reschedule($context, $other[0], $this->in('+40 days'));
    }

    public function testTheEditorExplainsAPostLimitWithALink(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free', ['posts_per_month' => 1]);
        $channel = $this->fakeChannel($workspace, $owner);
        $context = $this->contextFor($workspace, $owner);
        $this->scheduled($context, [$channel], '+1 day');
        $this->actAs($owner);

        $response = $this->post($this->base($workspace) . '/posts', [
            'intent' => 'schedule',
            'text' => 'Ещё один пост',
            'channels' => [$channel->publicId],
            'publish_date' => $this->in('+2 days')->format('Y-m-d'),
            'publish_time' => '12:00',
        ]);

        self::assertSame(422, $response->status);
        $text = str_replace("\u{00A0}", ' ', strip_tags($response->body));
        self::assertStringContainsString('можно планировать до 1 поста в месяц', $text);
        self::assertStringContainsString('href="' . $this->bannerLink($workspace) . '"', $response->body);
        self::assertStringContainsString('Перейти на тариф', $text);
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM posts WHERE status = 'scheduled'")[0]['c']);
    }

    // ---- people and workspaces --------------------------------------------------------------------------------------

    public function testInvitationsStopAtTheTeamSizeOfThePlan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'start');
        $this->actAs($owner);

        $first = $this->post($this->base($workspace) . '/team/invitations', ['email' => 'one@example.com', 'role' => 'editor']);
        $second = $this->post($this->base($workspace) . '/team/invitations', ['email' => 'two@example.com', 'role' => 'editor']);

        self::assertSame(302, $first->status);
        self::assertSame(302, $second->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM invitations')[0]['c'], 'Start has two seats: the owner and one more');
        $this->assertBanner($this->get($this->base($workspace) . '/team')->body, $workspace, 'в команде может быть до 2 человек');
    }

    public function testTheServiceItselfRefusesAnInvitationBeyondThePlan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');

        $this->expectException(\App\Domain\Billing\PlanLimitException::class);
        $this->app->container()->get(\App\Domain\Workspace\TeamService::class)->invite($this->contextFor($workspace, $owner), $owner, 'new@example.com', Role::Editor);
    }

    public function testAFreeOwnerCannotCreateASecondWorkspaceButAProOwnerCan(): void
    {
        [$owner, $personal] = $this->ownerWithWorkspace();
        $this->givePlan($personal, 'free');
        $this->actAs($owner);

        $this->post('/workspaces', ['name' => 'Второе']);

        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces WHERE owner_id = ?', [$owner->id])[0]['c']);
        self::assertStringContainsString('до 1 пространства', str_replace("\u{00A0}", ' ', strip_tags($this->get('/workspaces/new')->body)));

        $this->givePlan($personal, 'pro');
        $this->post('/workspaces', ['name' => 'Второе']);

        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM workspaces WHERE owner_id = ?', [$owner->id])[0]['c']);
    }

    // ---- features -------------------------------------------------------------------------------------------------

    private function featureRoute(): string
    {
        $router = $this->app->container()->get(Router::class);
        $router->group('/w/{workspaceId:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}}', [Authenticate::class, ResolveWorkspace::class], static function (Router $r): void {
            $r->get('/_t/approvals', [TestController::class, 'data'])->middleware([PlanFeature::class, ['feature' => 'approvals', 'name' => 'Согласование постов']]);
        });

        return '/_t/approvals';
    }

    public function testAPageOfAFeatureThePlanLacksSendsThePersonToThePlansWithAnExplanation(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'start');
        $route = $this->featureRoute();
        $this->actAs($owner);

        $response = $this->get($this->base($workspace) . $route);

        self::assertSame(302, $response->status);
        self::assertSame($this->bannerLink($workspace), $response->header('Location'));
        $this->assertBanner($this->get($this->bannerLink($workspace))->body, $workspace, '«Согласование постов» недоступно на тарифе «Старт»');
    }

    public function testProgrammaticClientsGetA402WithTheSameText(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $route = $this->featureRoute();
        $this->actAs($owner);

        $response = $this->get($this->base($workspace) . $route, ['Accept' => 'application/json']);

        self::assertSame(402, $response->status);
        $data = json_decode($response->body, true);
        self::assertFalse($data['ok']);
        self::assertSame($this->bannerLink($workspace), $data['upgrade_url']);
        self::assertStringContainsString('недоступно на тарифе «Free»', $data['message']);
    }

    public function testAPlanWithTheFeatureOpensThePage(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro');
        $route = $this->featureRoute();
        $this->actAs($owner);

        self::assertSame(200, $this->get($this->base($workspace) . $route)->status);
    }

    public function testTheFeatureGateKeepsTheSignInAndMembershipChecksInFront(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $route = $this->featureRoute();

        self::assertSame(302, $this->get($this->base($workspace) . $route)->status);
        self::assertStringStartsWith('/login', (string) $this->get($this->base($workspace) . $route)->header('Location'));
        $this->actAs($this->createUser('stranger@example.com'));
        self::assertSame(404, $this->get($this->base($workspace) . $route)->status);
        unset($owner);
    }
}
