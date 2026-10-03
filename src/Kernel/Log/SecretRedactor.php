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

    private const PATTERN = '/(password|passwd|secret|token|authorization|cookie|api[_-]?key|private[_-]?key|^code$|(auth|oauth|otp|totp|sms|email|verification|confirm|recovery|login)[_-]?code)/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
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
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $result[$key] = self::MASK;
            } elseif (is_array($value) && $depth < 8) {
                $result[$key] = $this->redact($value, $depth + 1);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
