<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Kernel\Queue\Queue;
use App\Kernel\Security\Crypto;

/**
 * Entry point for transactional email: puts a `SendMailJob` on the queue, so a slow or broken mail
 * server never delays or fails an HTTP request.
 */
final class MailComposer
{
    public function __construct(private readonly Queue $queue, private readonly Crypto $crypto)
    {
    }

    /**
     * @param string $template name under `templates/emails/` without the `.html.twig` / `.text.twig` suffix
     * @param array<string, scalar|null> $data values for the template (links, names)
     */
    public function send(string $to, string $subject, string $template, array $data = []): void
    {
        $this->queue->dispatch(new SendMailJob(
            $to,
            $subject,
            $template,
            $this->crypto->encrypt(json_encode($data, JSON_THROW_ON_ERROR)),
        ));
    }
}
