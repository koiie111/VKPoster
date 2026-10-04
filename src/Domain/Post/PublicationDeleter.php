<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Channel\ChannelCredentials;
use App\Domain\Channel\ChannelSystem;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\PlatformRegistry;
use App\Support\Clock;
use Throwable;

/**
 * Removes a published post from its network (the "delete after N hours" option, and deleting a published post by hand). A passing
 * failure is thrown on, so the queue tries again with backoff; a refusal (the bot lost the right to delete, the message is gone)
 * is final: the reason is stored and shown, and the timer stops.
 */
final class PublicationDeleter
{
    public function __construct(
        private readonly PublicationSystem $system,
        private readonly PlatformRegistry $registry,
        private readonly ChannelSystem $channels,
        private readonly ChannelCredentials $credentials,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return bool whether the post is gone from the network (or never needed deleting)
     * @throws PlatformError for a passing failure, to be retried
     */
    public function run(int $publicationId): bool
    {
        $publication = $this->system->find($publicationId);
        if ($publication === null || $publication->status !== PublicationStatus::Sent || $publication->deletedAt !== null || $publication->externalPostId === null) {
            return false;
        }
        $channel = $publication->channelId === null ? null : $this->channels->find($publication->channelId);
        if ($channel === null || !$this->registry->isEnabled($channel->platform)) {
            $this->system->markDeleteFailed($publication->id, 'Канал отключён, удалить пост из соцсети не удалось.');

            return false;
        }
        $started = $this->clock->now();
        try {
            $this->registry->adapter($channel->platform)->delete(
                new PublishResult($publication->externalPostId, $publication->externalUrl, $publication->externalIds),
                $channel->externalId,
                $this->credentials->forChannel($channel),
            );
        } catch (PlatformError $e) {
            if ($e->kind === ErrorKind::Temporary || $e->kind === ErrorKind::RateLimited || $e->kind === ErrorKind::UnknownOutcome) {
                throw $e;
            }
            $this->system->markDeleteFailed($publication->id, $e->forUser());
            $this->system->recordAttempt($publication, 'delete_failed', $e->kind->value, 'Не удалось удалить пост из соцсети: ' . $e->forUser(), $e->getMessage(), $started);

            return false;
        } catch (Throwable $e) {
            $this->system->markDeleteFailed($publication->id, 'Не удалось удалить пост из соцсети.');
            $this->system->recordAttempt($publication, 'delete_failed', null, 'Не удалось удалить пост из соцсети.', $e::class . ': ' . $e->getMessage(), $started);

            return false;
        }
        $this->system->markDeleted($publication->id);
        $this->system->recordAttempt($publication, 'deleted', null, 'Пост удалён из соцсети.', null, $started);

        return true;
    }
}
