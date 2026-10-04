<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Telegram\TelegramClient;
use App\Integrations\Storage\MediaStorage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a copy of a channel's picture in our storage (Telegram file links contain the bot token, so they can never be
 * shown directly). Best effort: a channel without a picture, or a failed download, just shows initials.
 */
final class ChannelAvatars
{
    private const MAX_BYTES = 524288;

    public function __construct(
        private readonly ChannelSystem $channels,
        private readonly MediaStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function refresh(Channel $channel, TelegramClient $client, ?string $fileId): void
    {
        if ($fileId === null) {
            return;
        }
        try {
            $bytes = $client->downloadFile($client->getFilePath($fileId), self::MAX_BYTES);
            $info = @getimagesizefromstring($bytes);
            if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                return;
            }
            $key = 'avatars/' . $channel->workspaceId . '/' . $channel->publicId . '.img';
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                return;
            }
            fwrite($stream, $bytes);
            rewind($stream);
            $this->storage->put($key, $stream);
            fclose($stream);
            $this->channels->setAvatar($channel, $key);
        } catch (Throwable $e) {
            $this->logger->info('Channel avatar not saved', ['channel' => $channel->publicId, 'reason' => $e::class]);
        }
    }

    /**
     * @return array{stream: resource, mime: string}|null
     */
    public function open(Channel $channel): ?array
    {
        if ($channel->avatarKey === null || !$this->storage->exists($channel->avatarKey)) {
            return null;
        }
        $stream = $this->storage->read($channel->avatarKey);
        $head = (string) stream_get_contents($stream, 16);
        rewind($stream);
        $mime = str_starts_with($head, "\xFF\xD8") ? 'image/jpeg' : (str_starts_with($head, "\x89PNG") ? 'image/png' : 'image/webp');

        return ['stream' => $stream, 'mime' => $mime];
    }

    public function delete(Channel $channel): void
    {
        if ($channel->avatarKey !== null) {
            try {
                $this->storage->delete($channel->avatarKey);
            } catch (Throwable) {
                // An orphaned avatar file is harmless; the cleanup command of stage 19 collects such leftovers.
            }
        }
    }
}
