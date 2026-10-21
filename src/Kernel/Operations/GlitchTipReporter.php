<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use App\Kernel\Config;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;

/** Sentry-compatible store transport, configured by trusted env; deliberately no PII, headers, bodies or stack arguments. */
final class GlitchTipReporter implements ErrorReporter
{
    public function __construct(private readonly HttpClientInterface $http, private readonly Config $config, private readonly Clock $clock)
    {
    }

    public function report(string $event, array $context): void
    {
        $dsn = parse_url($this->config->env()->string('SENTRY_DSN'));
        if (!is_array($dsn) || ($dsn['scheme'] ?? '') !== 'https' || !isset($dsn['host'], $dsn['user'], $dsn['path'])
            || isset($dsn['pass']) || preg_match('~^/(\d+)$~D', $dsn['path'], $match) !== 1) {
            return;
        }
        try {
            $exception = $context['exception'] ?? null;
            // Logger processors intentionally replace Throwable with safe metadata before handlers.
            $type = $exception instanceof \Throwable ? $exception::class : (is_array($exception) ? ($exception['class'] ?? null) : null);
            if (!is_string($type) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,199}$/D', $type) !== 1) {
                $type = 'none';
            }
            $this->http->request('POST', 'https://' . $dsn['host'] . (isset($dsn['port']) ? ':' . $dsn['port'] : '') . '/api/' . $match[1] . '/store/', [
                'timeout' => 2, 'connect_timeout' => 1, 'follow_redirects' => false,
                'headers' => ['X-Sentry-Auth' => 'Sentry sentry_version=7,sentry_key=' . $dsn['user']],
                'json' => ['event_id' => bin2hex(random_bytes(16)), 'timestamp' => $this->clock->now()->format(DATE_ATOM),
                    'platform' => 'php', 'level' => 'error', 'message' => preg_match('/^[a-z0-9_.-]{1,100}$/iD', $event) === 1 ? $event : 'application.error',
                    'tags' => ['exception_type' => $type]],
            ]);
        } catch (\Throwable) {
            // Reporting must not recursively log its own transport failure or break the original request.
        }
    }
}
