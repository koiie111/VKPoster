<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelMailer;
use App\Domain\Post\Post;
use App\Domain\Post\PostVariant;
use App\Domain\User\UserRepository;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Tells people what happened to their posts and channels: an email and a Telegram
 * message, each only if the person has it switched on (`NotificationSettings`). Failures of publishing go to the author and to the
 * owner and administrators; "published" goes to the author only, so a busy workspace is not flooded. Delivery is queued: neither a
 * slow mail server nor Telegram can delay or break the publishing pipeline.
 */
final class Notifier
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly NotificationSettings $settings,
        private readonly TelegramLinks $links,
        private readonly MailComposer $mail,
        private readonly ChannelMailer $channelMailer,
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    /**
     * A post could not be published to a channel (or may or may not have gone out, `$uncertain`).
     */
    public function publicationFailed(Post $post, PostVariant $variant, string $workspacePublicId, string $reason, bool $uncertain): void
    {
        $channel = $variant->channelName;
        $title = $uncertain ? 'Нужно проверить публикацию в «' . $channel . '»' : 'Не удалось опубликовать пост в «' . $channel . '»';
        $body = '«' . $post->title(60) . '». ' . $reason;
        $url = $this->link('/w/' . $workspacePublicId . '/posts/' . $post->publicId);
        foreach ($this->recipients($post->workspaceId, $post->authorId) as $userId) {
            $this->deliver(NotificationType::PublishFailed, $userId, $post->workspaceId, $title, $body, $url);
        }
    }

    public function published(Post $post, PostVariant $variant, string $workspacePublicId, ?string $externalUrl): void
    {
        if ($post->authorId === null) {
            return;
        }
        $url = $externalUrl ?? $this->link('/w/' . $workspacePublicId . '/posts/' . $post->publicId);
        $this->deliver(NotificationType::PublishOk, $post->authorId, $post->workspaceId, 'Пост опубликован в «' . $variant->channelName . '»', '«' . $post->title(60) . '»', $url);
    }

    /**
     * A channel stopped working: the owner and administrators are told (the email is the one from stage 06).
     */
    public function channelProblem(Channel $channel, string $workspaceName, string $workspacePublicId, string $reason): void
    {
        $url = $this->link('/w/' . $workspacePublicId . '/channels');
        foreach ($this->recipients($channel->workspaceId, null) as $userId) {
            $user = $this->users->find($userId);
            if ($user === null) {
                continue;
            }
            $this->deliver(
                NotificationType::ChannelProblem,
                $userId,
                $channel->workspaceId,
                'Канал «' . $channel->displayName() . '» перестал работать',
                $reason,
                $url,
                fn (string $email, string $name) => $this->channelMailer->broken($email, $name, $channel->displayName(), $channel->platform->label(), $workspaceName, $workspacePublicId, $reason),
            );
        }
    }

    /**
     * @param (callable(string, string): mixed)|null $customEmail sends the email itself instead of the generic template
     */
    private function deliver(NotificationType $type, int $userId, int $workspaceId, string $title, string $body, ?string $url, ?callable $customEmail = null): void
    {
        $user = $this->users->find($userId);
        if ($user === null || $user->isBlocked()) {
            return;
        }
        $choice = $this->settings->forUser($userId)[$type->value];
        $email = $choice['email'] && $user->email !== null && $user->isVerified();
        $chat = $choice['telegram'] ? $this->links->linkOf($userId) : null;
        if ($email) {
            if ($customEmail !== null) {
                $customEmail($user->email, $user->name);
            } else {
                $this->mail->send($user->email, $title, 'notification', ['name' => $user->name, 'title' => $title, 'body' => $body, 'link' => $url ?? '', 'type' => $type->label()]);
            }
        }
        if ($chat !== null) {
            $this->queue->dispatch(new SendTelegramNotificationJob($chat['chat_id'], $title . "\n" . $body . ($url !== null ? "\n" . $url : '')));
        }
        if (!$email && $chat === null) {
            return; // switched off: nothing was said, so there is nothing to keep
        }
        $this->db->table('notifications')->insert([
            'public_id' => (string) new Ulid(),
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'type' => $type->value,
            'title' => mb_substr($title, 0, 255),
            'body' => mb_substr($body, 0, 1000),
            'url' => $url === null ? null : mb_substr($url, 0, 500),
            'sent_email' => $email ? 1 : 0,
            'sent_telegram' => $chat !== null ? 1 : 0,
            'created_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * Owner and administrators of the workspace plus an extra person (the author), each once.
     *
     * @return list<int>
     */
    private function recipients(int $workspaceId, ?int $extraUserId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT user_id FROM workspace_members WHERE workspace_id = ? AND (role IN (\'owner\', \'admin\') OR user_id = ?) ORDER BY user_id',
            [$workspaceId, $extraUserId ?? 0],
        );

        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    private function link(string $path): string
    {
        return rtrim($this->config->string('app.url'), '/') . $path;
    }
}
