<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\User\User;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Config;
use App\Support\Clock;

/**
 * "Сообщить о проблеме": queues a message to the support mailbox together with the context that makes it answerable
 * (who, which workspace and page, which browser). Never includes tokens, passwords, cookies or channel credentials.
 */
final class FeedbackMailer
{
    public const MAX_LENGTH = 3000;

    public function __construct(
        private readonly MailComposer $mail,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param string $page path the person was on (a relative URL, or empty)
     */
    public function send(User $user, ?WorkspaceContext $workspace, string $message, string $page, string $userAgent): void
    {
        $message = mb_substr(trim($message), 0, self::MAX_LENGTH);
        $subject = '[Обратная связь] ' . mb_substr((string) preg_replace('/\s+/', ' ', $message), 0, 60);
        $this->mail->send($this->config->string('mail.support'), $subject, 'feedback', [
            'message' => $message,
            'user' => $user->name . ' (id ' . $user->id . ')',
            'email' => (string) $user->email,
            'workspace' => $workspace === null ? 'не выбрано' : $workspace->workspaceName . ' (' . $workspace->workspacePublicId . '), роль: ' . $workspace->role->value,
            'page' => $page === '' ? 'не указана' : $page,
            'agent' => mb_substr($userAgent, 0, 200),
            'time' => $this->clock->now()->format('Y-m-d H:i:s') . ' UTC',
            'environment' => $this->config->string('app.env'),
        ]);
    }
}
