<?php

declare(strict_types=1);

namespace App\Kernel\Mail;

/**
 * One outgoing email: recipient, subject and both bodies (every message carries HTML and plain text), and optional extra headers
 * (`List-Unsubscribe` of marketing mail).
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        /** @var array<string, string> */
        public readonly array $headers = [],
    ) {
    }
}
