<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use App\Kernel\Exception\SsrfException;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Downloads a feed by a URL the user typed: through the SSRF guard, with a time and size limit, and as a conditional request
 * (`If-None-Match`, `If-Modified-Since`) so an unchanged feed costs almost nothing.
 */
final class FeedFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * @return array{items: list<FeedItem>|null, etag: ?string, last_modified: ?string} `items` is null when the feed has not changed
     * @throws FeedException
     */
    public function fetch(string $url, ?string $etag, ?string $lastModified): array
    {
        $headers = ['User-Agent' => 'ezposter-rss', 'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8'];
        if ($etag !== null && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null && $lastModified !== '') {
            $headers['If-Modified-Since'] = $lastModified;
        }
        try {
            $response = $this->http->request('GET', $url, [
                'user_url' => true,
                'timeout' => 15,
                'headers' => $headers,
                'progress' => static function (int $total, int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES || $total > self::MAX_BYTES) {
                        throw new FeedException('Лента слишком большая.');
                    }
                },
            ]);
        } catch (SsrfException) {
            throw new FeedException('Этот адрес не подходит: нужна ссылка на ленту в интернете (http или https).');
        } catch (GuzzleException) {
            throw new FeedException('Не удалось скачать ленту. Проверьте, что ссылка открывается.');
        }
        $status = $response->getStatusCode();
        if ($status === 304) {
            return ['items' => null, 'etag' => $etag, 'last_modified' => $lastModified];
        }
        if ($status !== 200) {
            throw new FeedException(sprintf('Сайт ответил кодом %d.', $status));
        }
        $body = $response->getBody();
        $xml = '';
        while (!$body->eof()) {
            $xml .= $body->read(65536);
            if (strlen($xml) > self::MAX_BYTES) {
                throw new FeedException('Лента слишком большая.');
            }
        }
        $tag = $response->getHeaderLine('ETag');
        $modified = $response->getHeaderLine('Last-Modified');

        return [
            'items' => FeedParser::parse($xml),
            'etag' => $tag !== '' ? mb_substr($tag, 0, 255) : null,
            'last_modified' => $modified !== '' ? mb_substr($modified, 0, 64) : null,
        ];
    }
}
