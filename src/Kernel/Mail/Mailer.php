<?php

declare(strict_types=1);

namespace App\Kernel\Mail;

/**
 * Sends email. The production implementation is `SymfonyMailer`; tests use an in-memory fake, so no
 * test ever sends a real message.
 */
interface Mailer
{
    /**
     * @throws \RuntimeException when the message could not be handed to the mail server
     */
    public function send(MailMessage $message): void;
}
