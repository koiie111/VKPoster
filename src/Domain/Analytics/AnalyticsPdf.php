<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Config;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Exception\HttpException;

/** Trusted HTML only; authenticated renderer has no public port and denies all browser network requests. */
final class AnalyticsPdf
{
    public function __construct(private readonly HttpClientInterface $http, private readonly Config $config, private readonly ?\App\Kernel\Security\Crypto $crypto = null)
    {
    }
    public function render(string $html): string
    {
        try {
            $r = $this->http->request(
                'POST',
                rtrim(
                    $this->config->string('analytics.pdf_url'),
                    '/'
                ).'/pdf',
                ['body' => $html,
                'headers' => ['Content-Type' => 'text/html; charset=utf-8',
                'X-Report-Signature' => $this->config->env()->string('CRYPTO_BACKEND') === 'vault'
                    ? ($this->crypto ?? throw new \RuntimeException('Isolated PDF signer unavailable.'))->signature('pdf', $html)
                    : hash_hmac(
                        'sha256',
                        $html,
                        AnalyticsPdfKey::derive($this->config->string('security.key'))
                    )],
                'timeout' => 40,
                'connect_timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false]
            );
            $bytes = (string)$r->getBody();
            if ($r->getStatusCode() !== 200 || strlen($bytes) > 10 * 1024 * 1024 || !str_starts_with($bytes, '%PDF-')) {
                throw new \RuntimeException();
            }
            return $bytes;
        } catch (\Throwable) {
            throw new HttpException(503, 'PDF временно недоступен. Попробуйте позже или выгрузите CSV.');
        }
    }
}
