<?php

declare(strict_types=1);

namespace App\Tests\Integration\Link;

use App\Domain\Link\LinkRewriter;
use App\Domain\Link\LinkSettings;
use App\Domain\Link\ShortLinks;
use App\Domain\Link\UrlGuard;
use App\Domain\Link\UtmRule;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PublishJob;
use App\Domain\Post\PostStatus;
use App\Domain\Settings\Settings;
use App\Kernel\Queue\Worker;
use App\Tests\Support\PostTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** Links on their way out: tags and short addresses reach the network, checks in the editor store nothing, blocked sites never go out. */
#[CoversClass(LinkRewriter::class)]
#[CoversClass(LinkSettings::class)]
#[CoversClass(ShortLinks::class)]
#[CoversClass(UrlGuard::class)]
final class LinkPublishingTest extends PostTestCase
{
    protected function tearDown(): void
    {
        $this->app->container()->get(Settings::class)->forget(UrlGuard::SETTING);
        parent::tearDown();
    }

    private function settings(): LinkSettings
    {
        return $this->app->container()->get(LinkSettings::class);
    }

    private function publishNow(string $text, ?PostOptions $options = null): void
    {
        [$owner, $workspace] = [$this->owner, $this->workspace];
        $context = $this->contextFor($workspace, $owner);
        $this->service()->schedule($context, null, $this->draft($text, [$this->channel], [], $options), $this->clock->now(), true);
        self::assertTrue($this->app->container()->get(Worker::class)->runNext(PublishJob::QUEUE, 'w'));
    }

    private \App\Domain\User\User $owner;
    private \App\Domain\Workspace\Workspace $workspace;
    private \App\Domain\Channel\Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->workspace] = $this->ownerWithWorkspace();
        $this->channel = $this->fakeChannel($this->workspace, $this->owner, 'fake-1', 'Кофемания');
    }

    public function testTagsGoIntoTheTextAndTheButtonsOfAPublishedPost(): void
    {
        $this->settings()->saveWorkspace($this->workspace->id, new UtmRule(true, '{platform}', 'social', 'launch'), false);

        $this->publishNow('Читайте https://shop.example/new?ref=1#top и [каталог](https://shop.example/c)', new PostOptions([['text' => 'Купить', 'url' => 'https://shop.example/buy']]));

        $request = $this->fake->requests[0];
        self::assertStringContainsString('https://shop.example/new?ref=1&amp;utm_source=fake&amp;utm_medium=social&amp;utm_campaign=launch#top', $request->text);
        self::assertStringContainsString('href="https://shop.example/c?utm_source=fake&amp;utm_medium=social&amp;utm_campaign=launch"', $request->text);
        self::assertSame('https://shop.example/buy?utm_source=fake&utm_medium=social&utm_campaign=launch', $request->buttons[0]['url']);
    }

    public function testAChannelCanSwitchTagsOffOrUseItsOwnRule(): void
    {
        $this->settings()->saveWorkspace($this->workspace->id, new UtmRule(true, '{platform}', 'social', 'launch'), false);
        $this->settings()->saveChannelRule($this->workspace->id, $this->channel->id, new UtmRule(false));

        $this->publishNow('https://shop.example/a');
        self::assertSame('https://shop.example/a', $this->fake->requests[0]->text);

        $this->settings()->saveChannelRule($this->workspace->id, $this->channel->id, new UtmRule(true, 'club', '', '{channel}'));
        $this->publishNow('https://shop.example/b');
        self::assertStringContainsString('https://shop.example/b?utm_source=club&amp;utm_campaign=kofemaniya', $this->fake->requests[1]->text);

        $this->settings()->saveChannelRule($this->workspace->id, $this->channel->id, null);
        self::assertNull($this->settings()->channelRule($this->workspace->id, $this->channel->id), 'the channel follows the workspace again');
    }

    public function testShortLinksReplaceAddressesAndASecondRunReusesTheCode(): void
    {
        $this->settings()->saveWorkspace($this->workspace->id, new UtmRule(true, '{platform}', 'social', 'launch'), true);

        $this->publishNow('Смотрите https://shop.example/new');

        self::assertSame(1, preg_match('~http://localhost/s/([0-9A-Za-z]{7})~', $this->fake->requests[0]->text, $m));
        $code = $m[1] ?? '';
        self::assertNotSame('', $code, 'the text carries a short address: ' . $this->fake->requests[0]->text);
        $rows = $this->db->select('SELECT * FROM short_links WHERE workspace_id = ?', [$this->workspace->id]);
        self::assertCount(1, $rows);
        self::assertSame($code, $rows[0]['code']);
        self::assertSame('fake', $rows[0]['platform']);
        self::assertSame('https://shop.example/new?utm_source=fake&utm_medium=social&utm_campaign=launch', $rows[0]['target_url'], 'the short link leads to the tagged address');
        self::assertNotNull($rows[0]['variant_id']);

        $variantId = (int) $rows[0]['variant_id'];
        $again = $this->app->container()->get(ShortLinks::class)->forVariant($this->workspace->id, $variantId, 'fake', $rows[0]['target_url']);
        self::assertSame($code, $again, 'a retried publication gets the same code');
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM short_links')[0]['c']);
    }

    public function testCheckingAPostInTheEditorStoresNothingButCountsTheShortLength(): void
    {
        $this->settings()->saveWorkspace($this->workspace->id, new UtmRule(false), true);
        $context = $this->contextFor($this->workspace, $this->owner);
        $long = 'https://shop.example/' . str_repeat('a', 300);

        [$post] = $this->scheduled($context, [$this->channel], '+10 minutes', 'Ссылка ' . $long);

        self::assertSame(PostStatus::Scheduled, $post->status);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM short_links')[0]['c']);
    }

    public function testAPostWithABlockedSiteIsRefusedAtCheckTimeAndNeverGoesOut(): void
    {
        $this->app->container()->get(Settings::class)->set(UrlGuard::SETTING, ['evil.example'], null);
        $context = $this->contextFor($this->workspace, $this->owner);

        try {
            $this->scheduled($context, [$this->channel], '+10 minutes', 'Выгодно https://promo.evil.example/win');
            self::fail('a blocked address must stop the post');
        } catch (\App\Domain\Post\PostException $e) {
            self::assertStringContainsString('evil.example', (string) json_encode([$e->getMessage(), $e->channelProblems], JSON_UNESCAPED_UNICODE));
        }
        self::assertSame([], $this->fake->requests);
    }

    public function testTheGuardOnlyLetsSafeAddressesBecomeShortLinks(): void
    {
        $guard = $this->app->container()->get(UrlGuard::class);
        $this->app->container()->get(Settings::class)->set(UrlGuard::SETTING, UrlGuard::parseList("Bad.Example\nhttps://www.worse.example/path, junk"), null);

        self::assertNull($guard->problem('https://shop.example/a?b=1#c'));
        foreach (['javascript:alert(1)', 'data:text/html,<b>', 'ftp://shop.example/', 'file:///etc/passwd', '//shop.example', 'https://user:pass@shop.example/', 'https://localhost/', 'http://127.0.0.1/', 'http://10.0.0.5/x', 'http://[::1]/', 'https://intranet/', 'https://shop.example/a b', '', 'https://bad.example/', 'https://x.bad.example/', 'https://worse.example/'] as $url) {
            self::assertNotNull($guard->problem($url), $url);
        }
        self::assertNull($guard->problem('https://notbad.example/'), 'a domain that only ends alike is not blocked');
        self::assertSame(['bad.example', 'worse.example'], $guard->blocked());
        self::assertNotNull($guard->problem('https://shop.example/' . str_repeat('a', 2000)));
    }

    public function testCodesAreBase62OfTheRightLengthAndManualLinksHaveADailyLimit(): void
    {
        $links = $this->app->container()->get(ShortLinks::class);
        $codes = [];
        for ($i = 0; $i < 5; ++$i) {
            $codes[] = $links->createManual($this->workspace->id, $this->owner->id, 'https://shop.example/' . $i, 5);
        }
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-Za-z]{7}$/', $code);
        }
        self::assertCount(5, array_unique($codes));

        $this->expectException(\App\Domain\Link\LinkException::class);
        $links->createManual($this->workspace->id, $this->owner->id, 'https://shop.example/6', 5);
    }

    public function testManualLinksRefuseDangerousSchemes(): void
    {
        $links = $this->app->container()->get(ShortLinks::class);
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', 'vbscript:x', 'http://localhost/admin'] as $url) {
            try {
                $links->createManual($this->workspace->id, $this->owner->id, $url, 100);
                self::fail($url . ' must be refused');
            } catch (\App\Domain\Link\LinkException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM short_links')[0]['c']);
    }
}
