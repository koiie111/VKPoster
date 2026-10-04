<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Kernel\Exception\SsrfException;
use App\Kernel\HttpClient\HttpClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Downloads a file by a URL the user typed, for "upload by link". The request goes through the SSRF guard
 * (only public http(s) hosts, connection pinned to the checked address), has a time limit, and stops reading
 * after the size limit, so a link to a huge or endless file cannot fill the disk.
 */
final class MediaFetcher
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly MediaService $media,
        private readonly MediaLimits $limits,
    ) {
    }

    /**
     * Download into a temporary file. The caller removes it.
     *
     * @return array{path: string, name: string}
     * @throws MediaException
     */
    public function fetch(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000) {
            throw new MediaException('Вставьте ссылку на файл.');
        }
        $max = $this->limits->maxFileBytes;
        try {
            $response = $this->http->request('GET', $url, [
                'user_url' => true,
                'timeout' => $this->limits->urlTimeout,
                'headers' => ['User-Agent' => 'ezposter-media-fetch', 'Accept' => 'image/*,video/*,application/pdf'],
                // Abort the transfer as soon as it is clearly too big (curl progress callback: downloaded bytes are the second argument).
                'progress' => static function (int $total, int $downloaded) use ($max): void {
                    if ($downloaded > $max || $total > $max) {
                        throw new MediaException('Файл по ссылке слишком большой.');
                    }
                },
            ]);
        } catch (SsrfException) {
            throw new MediaException('Эта ссылка не подходит. Нужна прямая ссылка на файл в интернете (http или https).');
        } catch (GuzzleException) {
            throw new MediaException('Не удалось скачать файл по ссылке. Проверьте, что она открывается.');
        }
        if ($response->getStatusCode() !== 200) {
            throw new MediaException(sprintf('Не удалось скачать файл: сайт ответил кодом %d.', $response->getStatusCode()));
        }
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $max) {
            throw new MediaException('Файл по ссылке слишком большой.');
        }

        $path = $this->media->newTempFile();
        $out = fopen($path, 'wb');
        if ($out === false) {
            @unlink($path);
            throw new MediaException('Не удалось сохранить файл. Попробуйте ещё раз.');
        }
        $written = 0;
        $body = $response->getBody();
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            $written += strlen($chunk);
            if ($written > $max) {
                fclose($out);
                @unlink($path);
                throw new MediaException('Файл по ссылке слишком большой.');
            }
            fwrite($out, $chunk);
        }
        fclose($out);

        $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));

        return ['path' => $path, 'name' => $name];
    }
}
