<?php

declare(strict_types=1);

namespace App\Tests\Feature\Site;

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Site\HelpController;
use App\Http\Controllers\Site\LegalController;
use App\Http\Controllers\Site\SeoController;
use App\Tests\Support\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The public pages: landing (prices from the database), legal documents, help, status, sitemap, robots, cookie notice.
 */
#[CoversClass(HomeController::class)]
#[CoversClass(LegalController::class)]
#[CoversClass(HelpController::class)]
#[CoversClass(SeoController::class)]
final class PublicSiteTest extends AuthTestCase
{
    public function testLandingShowsPricesFromTheDatabaseAndSeoTags(): void
    {
        $this->db->execute("UPDATE plan_prices SET amount = 123400 WHERE plan_id = (SELECT id FROM plans WHERE code = 'pro') AND period = 'month'");
        $body = $this->get('/')->body;
        $this->db->execute("UPDATE plan_prices SET amount = 99000 WHERE plan_id = (SELECT id FROM plans WHERE code = 'pro') AND period = 'month'");

        self::assertStringContainsString('1' . "\u{00A0}" . '234' . "\u{00A0}" . '₽', $body);
        self::assertStringContainsString('Попробовать бесплатно', $body);
        self::assertStringContainsString('<meta name="robots" content="index,follow">', $body);
        self::assertStringContainsString('property="og:image"', $body);
        self::assertStringContainsString('rel="canonical"', $body);
        self::assertStringContainsString('id="faq"', $body);
    }

    public function testLandingOffersTheAppToSignedInVisitors(): void
    {
        $this->createUser();
        $this->signIn();

        self::assertStringContainsString('Открыть сервис', $this->get('/')->body);
    }

    public function testLegalPagesAreServedAndTemplatesAreMarked(): void
    {
        foreach (['offer', 'privacy', 'cookies', 'requisites'] as $slug) {
            $response = $this->get('/legal/' . $slug);
            self::assertSame(200, $response->status, $slug);
            self::assertStringContainsString('class="doc', $response->body);
        }
        $offer = $this->get('/legal/offer')->body;
        self::assertStringContainsString('Шаблон', $offer);
        self::assertStringContainsString('Публичная оферта', $offer);
        self::assertSame(404, $this->get('/legal/unknown')->status);
    }

    public function testHelpArticlesAreServed(): void
    {
        $index = $this->get('/help');
        self::assertSame(200, $index->status);
        self::assertStringContainsString('/help/connect-telegram', $index->body);
        $article = $this->get('/help/connect-telegram');
        self::assertSame(200, $article->status);
        self::assertStringContainsString('Как подключить Telegram-канал', $article->body);
        self::assertSame(404, $this->get('/help/does-not-exist')->status);
    }

    public function testSitemapListsPublicPagesOnly(): void
    {
        $xml = $this->get('/sitemap.xml');
        self::assertSame(200, $xml->status);
        self::assertStringContainsString('application/xml', (string) $xml->header('Content-Type'));
        self::assertStringContainsString('/legal/privacy</loc><lastmod>', $xml->body);
        self::assertStringContainsString('/help/connect-vk</loc>', $xml->body);
        self::assertStringNotContainsString('/app', $xml->body);
        self::assertStringNotContainsString('/admin', $xml->body);
    }

    public function testRobotsClosesEverythingOutsideProduction(): void
    {
        $robots = $this->get('/robots.txt');
        self::assertSame(200, $robots->status);
        self::assertStringContainsString("Disallow: /\n", $robots->body);
        self::assertStringContainsString('Sitemap: ', $robots->body);
    }

    public function testApplicationPagesAreNotIndexable(): void
    {
        self::assertStringContainsString('noindex', $this->get('/login')->body);
    }

    public function testCookieNoticeIsShownUntilAChoiceIsMade(): void
    {
        self::assertStringContainsString('data-cookie-banner', $this->get('/')->body);
        $this->cookies['cookie_consent'] = 'necessary';
        self::assertStringNotContainsString('data-cookie-banner', $this->get('/')->body);
        $this->cookies['cookie_consent'] = 'garbage';
        self::assertStringContainsString('data-cookie-banner', $this->get('/')->body);
    }

    public function testStatusPageIsPublic(): void
    {
        $response = $this->get('/status');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Состояние соцсетей', $response->body);
    }
}
