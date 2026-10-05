<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Help\HelpArticles;
use App\Domain\Legal\LegalDocuments;
use App\Kernel\Config;
use App\Kernel\Http\Response;

/**
 * `/robots.txt` and `/sitemap.xml`: only the public pages are offered to search engines; the application, the admin area and
 * every one-time link are closed.
 */
final class SeoController
{
    public function __construct(
        private readonly Config $config,
        private readonly LegalDocuments $legal,
        private readonly HelpArticles $help,
    ) {
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        // A staging copy (anything but production) must never be indexed.
        if (!$this->config->isProduction()) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (['/app', '/w/', '/account/', '/admin', '/auth/', '/media/', '/webhooks/', '/dev/', '/invitations/', '/email/', '/password/', '/login', '/consent', '/feedback'] as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            $lines[] = 'Allow: /';
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $this->base() . '/sitemap.xml';

        return Response::text(implode("\n", $lines) . "\n")->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function sitemap(): Response
    {
        $base = $this->base();
        $urls = [['/', null], ['/register', null], ['/help', null], ['/status', null]];
        foreach ($this->help->all() as $article) {
            $urls[] = ['/help/' . $article->slug, null];
        }
        foreach ($this->legal->all() as $document) {
            $urls[] = ['/legal/' . $document->slug, $document->version];
        }
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($urls as [$path, $modified]) {
            $xml[] = '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($modified === null ? '' : '<lastmod>' . $modified . '</lastmod>') . '</url>';
        }
        $xml[] = '</urlset>';

        return (new Response(200, implode("\n", $xml) . "\n", ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']));
    }

    private function base(): string
    {
        return rtrim($this->config->string('app.url'), '/');
    }
}
