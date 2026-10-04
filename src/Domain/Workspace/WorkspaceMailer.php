<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Notification\MailComposer;
use App\Kernel\Config;
use App\Kernel\Http\Router;

/**
 * Workspace emails (invitations). Builds absolute links from `APP_URL` and queues the message; texts live in `templates/emails/`.
 */
final class WorkspaceMailer
{
    public function __construct(
        private readonly MailComposer $mail,
        private readonly Router $router,
        private readonly Config $config,
    ) {
    }

    public function invitation(string $email, string $inviterName, string $workspaceName, Role $role, string $token, int $ttlDays): void
    {
        $this->mail->send($email, 'Вас пригласили в «' . $workspaceName . '»', 'workspace_invitation', [
            'inviter' => $inviterName,
            'workspace' => $workspaceName,
            'role' => $role->label(),
            'days' => $ttlDays,
            'link' => rtrim($this->config->string('app.url'), '/') . $this->router->url('invitation.show', ['token' => $token]),
        ]);
    }
}
