<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel\Mail\MailMessage;
use App\Kernel\Mail\Mailer;

/**
 * In-memory `Mailer` for tests: keeps every message so a test can read the link inside it.
 */
final class ArrayMailer implements Mailer
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public function send(MailMessage $message): void
    {
        $this->sent[] = $message;
    }

    /**
     * @return list<MailMessage>
     */
    public function to(string $address): array
    {
        return array_values(array_filter($this->sent, static fn (MailMessage $m): bool => $m->to === $address));
    }

    public function lastTo(string $address): ?MailMessage
    {
        $all = $this->to($address);

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * Path (with leading slash) of the first link to this app in the plain-text body.
     */
    public static function path(MailMessage $message): string
    {
        if (preg_match('#https?://localhost(/[^\s<>"]*)#', $message->text, $m) !== 1) {
            throw new \LogicException('No link in the message: ' . $message->subject);
        }

        return $m[1];
    }
}
