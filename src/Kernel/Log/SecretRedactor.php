<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that masks secrets in `context` and `extra` before a record is written.
 *
 * A key is secret when it contains password, passwd, secret, token, authorization, cookie, api key,
 * or is a one-time code (`code`, `oauth_code`, `otp_code`, ...). Plain `error_code`/`status_code` stay readable.
 */
final class SecretRedactor implements ProcessorInterface
{
    public const MASK = '[redacted]';

    private const PATTERN = '/(proxy|egress_url|password|passwd|secret|token|authorization|cookie|api[_-]?key|private[_-]?key|app[_-]?keys?|dsn|email|phone|^body$|^payload$|^caption$|^text$|^code$|^pin$|(auth|oauth|otp|totp|sms|email|verification|confirm|recovery|login)[_-]?code)/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->text($record->message),
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        $result = [];
        foreach (array_slice($data, 0, 50, true) as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $result[$key] = self::MASK;
            } elseif (is_array($value)) {
                $result[$key] = $depth < 8 ? $this->redact($value, $depth + 1) : self::MASK;
            } elseif ($value instanceof \Throwable) {
                $result[$key] = ['class' => $value::class, 'file' => basename($value->getFile()), 'line' => $value->getLine()];
            } elseif (is_object($value)) {
                $result[$key] = self::MASK;
            } elseif (is_string($value)) {
                $result[$key] = $this->text($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
    private function text(string $value): string
    {
        $value = preg_replace('~/g/[a-f0-9]{64}\.[A-Za-z0-9_-]{43}~', '/g/' . self::MASK, $value) ?? self::MASK;
        $value = preg_replace('~(/(?:password/reset|email/verify|email/change|invitations)/)[A-Za-z0-9_-]{43}~', '$1' . self::MASK, $value) ?? self::MASK;
        $value = preg_replace('~([?&](?:code|token|access_token|api_key|signature)=)[^&\s]*~i', '$1' . self::MASK, $value) ?? self::MASK;
        $value = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1' . self::MASK, $value) ?? self::MASK;

        return mb_substr($value, 0, 4096);
    }

}
