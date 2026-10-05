<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Kernel\Mail\MailMessage;
use App\Kernel\Mail\Mailer;
use App\Kernel\Queue\AbstractJob;
use App\Kernel\Security\Crypto;
use App\Kernel\View\View;
use InvalidArgumentException;

/**
 * Renders `emails/<template>.html.twig` and `.text.twig` and sends the result. The template data may
 * contain one-time links, so the queue row only holds it encrypted (`Crypto`).
 */
final class SendMailJob extends AbstractJob
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $template,
        public readonly string $dataEncrypted,
    ) {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $template) !== 1) {
            throw new InvalidArgumentException('Invalid mail template name.');
        }
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            is_string($payload['to'] ?? null) ? $payload['to'] : '',
            is_string($payload['subject'] ?? null) ? $payload['subject'] : '',
            is_string($payload['template'] ?? null) ? $payload['template'] : '',
            is_string($payload['data'] ?? null) ? $payload['data'] : '',
        );
    }

    public function toPayload(): array
    {
        return ['to' => $this->to, 'subject' => $this->subject, 'template' => $this->template, 'data' => $this->dataEncrypted];
    }

    public function handle(Mailer $mailer, View $view, Crypto $crypto, MailTemplates $templates): void
    {
        $decoded = json_decode($crypto->decrypt($this->dataEncrypted), true, 16, JSON_THROW_ON_ERROR);
        $data = is_array($decoded) ? $decoded : [];
        // The owner may have rewritten the subject and the text in the admin area; otherwise the written template is used.
        $subject = $templates->subject($this->template, $this->subject, $data);
        $data['subject'] = $subject;
        $edited = $templates->render($this->template, $data, $subject);
        $mailer->send(new MailMessage(
            $this->to,
            $subject,
            $edited['html'] ?? $view->render('emails/' . $this->template . '.html.twig', $data),
            $edited['text'] ?? $view->render('emails/' . $this->template . '.text.twig', $data),
        ));
    }
}
