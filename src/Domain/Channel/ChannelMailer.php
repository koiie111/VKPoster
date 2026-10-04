<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Notification\MailComposer;
use App\Kernel\Config;

/**
 * Emails about channels. Texts live in `templates/emails/`.
 */
final class ChannelMailer
{
    public function __construct(private readonly MailComposer $mail, private readonly Config $config)
    {
    }

    public function broken(string $email, string $name, string $channelName, string $platformLabel, string $workspaceName, string $workspacePublicId, string $reason): void
    {
        // The title comes from the platform: keep line breaks and other control characters out of the subject.
        $channelName = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $channelName));
        $this->mail->send($email, 'Канал «' . $channelName . '» перестал работать', 'channel_broken', [
            'name' => $name,
            'channel' => $channelName,
            'platform' => $platformLabel,
            'workspace' => $workspaceName,
            'reason' => $reason,
            'link' => rtrim($this->config->string('app.url'), '/') . '/w/' . $workspacePublicId . '/channels',
        ]);
    }
}
