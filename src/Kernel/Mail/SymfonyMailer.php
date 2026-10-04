<?php

declare(strict_types=1);

namespace App\Kernel\Mail;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyTransportMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

/**
 * `Mailer` on top of symfony/mailer. The transport is created on first use from a DSN
 * (`smtp://mailpit:1025` in dev), so building the container never opens a connection.
 */
final class SymfonyMailer implements Mailer
{
    private ?MailerInterface $transport = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(MailMessage $message): void
    {
        try {
            $email = (new Email())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to(new Address($message->to))
                ->subject($message->subject)
                ->text($message->text)
                ->html($message->html);
            $this->transport()->send($email);
        } catch (TransportExceptionInterface | MimeException $e) {
            // Never include the message: it can carry one-time links.
            throw new RuntimeException('Mail delivery failed: ' . $e::class, 0, $e);
        }
    }

    private function transport(): MailerInterface
    {
        return $this->transport ??= new SymfonyTransportMailer(Transport::fromDsn($this->dsn));
    }
}
