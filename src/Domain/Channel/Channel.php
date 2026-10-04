<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\Platform;
use DateTimeImmutable;

/**
 * A connected channel or group of one workspace. `publicId` (ULID) is the only id that may appear in URLs. It holds no
 * secrets: the token behind it is reached through `ChannelCredentials`.
 */
final class Channel
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly Platform $platform,
        public readonly string $externalId,
        public readonly ChannelMode $mode,
        public readonly string $title,
        public readonly ?string $alias,
        public readonly ?string $username,
        public readonly string $kind,
        public readonly ?string $avatarKey,
        public readonly ChannelStatus $status,
        public readonly ?int $credentialId,
        public readonly array $settings,
        public readonly ?DateTimeImmutable $lastHealthAt,
        public readonly ?string $lastError,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function displayName(): string
    {
        return $this->alias !== null && $this->alias !== '' ? $this->alias : $this->title;
    }

    /**
     * What the bot may do here (post, edit, delete, pin), as the last check saw it.
     *
     * @return array<string, bool>
     */
    public function rights(): array
    {
        $rights = $this->settings['rights'] ?? [];
        $result = [];
        if (is_array($rights)) {
            foreach ($rights as $name => $allowed) {
                $result[(string) $name] = $allowed === true;
            }
        }

        return $result;
    }

    /**
     * Public link to a message of this channel, or null when the platform gives none.
     */
    public function postUrl(string $externalPostId): ?string
    {
        if ($this->platform === Platform::Vk) {
            return 'https://vk.com/wall-' . $this->externalId . '_' . $externalPostId;
        }
        if ($this->platform !== Platform::Telegram) {
            return null;
        }
        if ($this->username !== null) {
            return 'https://t.me/' . $this->username . '/' . $externalPostId;
        }

        return str_starts_with($this->externalId, '-100') ? 'https://t.me/c/' . substr($this->externalId, 4) . '/' . $externalPostId : null;
    }

    /**
     * "@name" for public channels, otherwise the kind of chat.
     */
    public function handle(): string
    {
        if ($this->platform === Platform::Vk) {
            return $this->username !== null ? 'vk.com/' . $this->username : 'Сообщество';
        }

        return $this->username !== null ? '@' . $this->username : ($this->kind === 'group' ? 'Группа' : 'Закрытый канал');
    }
}
