<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Audit\AuditLog;
use App\Domain\Analytics\Analytics;
use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelCredentials;
use App\Domain\Channel\ChannelHealthService;
use App\Domain\Channel\ChannelStatus;
use App\Domain\Channel\ChannelSystem;
use App\Domain\Notification\Notifier;
use App\Domain\Workspace\WorkspaceRepository;
use App\Integrations\Social\Contracts\CommentingAdapter;
use App\Integrations\Social\Contracts\ErrorKind;
use App\Integrations\Social\Contracts\OutcomeVerifier;
use App\Integrations\Social\Contracts\PlatformAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishRequest;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\Contracts\VideoProcessingAdapter;
use App\Integrations\Social\Contracts\VideoThumbnailAdapter;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Config;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Redis;
use Throwable;

/**
 * Publishes one publication (master plan §4.4), the heart of the product. The rule that shapes everything: better not to publish
 * and tell the owner than to publish twice.
 *
 * 1. A lock in Redis keeps a second worker away; the database claim (`queued → sending`, one winner) is the real guarantee.
 * 2. The channel is checked (paused, broken, rights) and the variant validated before anything is sent.
 * 3. The adapter publishes. A success is stored with every message id. A failure is classified by `ErrorKind`:
 *    temporary and rate-limited ones go back to the queue with backoff (1, 5, 15, 60 minutes, five attempts; a `Retry-After` wins);
 *    permanent ones fail, and the channel is re-checked; auth ones fail and mark the channel broken; an unknown outcome (the request
 *    may have arrived) stops for a person to decide. Anything unexpected during the call counts as unknown too.
 * 4. After a success: pin, first comment and the deletion timer, each best-effort (their failure never turns a published post into a
 *    failed one), then the post status is recomputed and people are told.
 *
 * A worker that dies mid-way leaves the publication in `sending`; `PublicationScheduler::reapStuck()` turns it into `unknown`.
 */
final class Publisher
{
    public const MAX_ATTEMPTS = 5;

    /** @var list<int> seconds before attempt 2, 3, 4, 5 */
    private const BACKOFF = [60, 300, 900, 3600];

    private const LOCK_SECONDS = 900;
    private const VIDEO_LOCK_SECONDS = 7500;

    public function __construct(
        private readonly PublicationSystem $system,
        private readonly PlatformRegistry $registry,
        private readonly ChannelSystem $channels,
        private readonly ChannelCredentials $credentials,
        private readonly ChannelHealthService $health,
        private readonly PublishRequestBuilder $builder,
        private readonly PublishingContexts $contexts,
        private readonly PostValidator $validator,
        private readonly Notifier $notifier,
        private readonly Queue $queue,
        private readonly WorkspaceRepository $workspaces,
        private readonly AuditLog $audit,
        private readonly Redis $redis,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly \App\Domain\Content\Publishing\ContentOriginGuard $origin,
        private readonly \App\Domain\Automation\Trigger\TriggerEngine $triggers,
        private readonly \App\Domain\Approval\ApprovalWorkflow $approvals,
        private readonly VideoProviderSessions $videoSessions,
        private readonly Analytics $analytics,
    ) {
    }

    public function run(int $publicationId): void
    {
        $publication = $this->system->find($publicationId);
        $loaded = $publication === null ? null : $this->system->load($publication);
        $lockSeconds = $loaded !== null && $loaded['post']->kind === PostKind::ShortVideo ? self::VIDEO_LOCK_SECONDS : self::LOCK_SECONDS;
        $key = 'publish:lock:' . $publicationId;
        $token = bin2hex(random_bytes(8));
        if ($this->redis->set($key, $token, ['nx', 'ex' => $lockSeconds]) !== true) {
            $this->logger->info('Publication is being handled by another worker', ['publication' => $publicationId]);

            return;
        }
        try {
            $this->execute($publicationId);
        } finally {
            // Release only our own lock: it may have expired and been taken by somebody else.
            $this->redis->eval('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end', [$key, $token], 1);
        }
    }

    private function execute(int $publicationId): void
    {
        $publication = $this->system->claim($publicationId);
        if ($publication === null) {
            return;
        }
        $started = $this->clock->now();
        $loaded = $this->system->load($publication);
        if ($loaded === null) {
            $this->system->markFailed($publication, 'post_missing', 'Пост был удалён.', 'post or variant row is gone');
            $this->system->syncPostStatus($publication->postId);

            return;
        }
        ['post' => $post, 'variant' => $variant] = $loaded;
        $originError = $this->origin->problem($post);
        $problemCode = 'origin_stale';
        if ($originError === null && ($originError = $this->approvals->publicationProblem($post)) !== null) {
            $problemCode = 'approval_required';
        }
        if ($originError !== null) {
            $this->fail($publication, $post, $variant, $started, $problemCode, $originError, 'content origin preflight failed');
            return;
        }
        $channel = $variant->channelId === null ? null : $this->channels->find($variant->channelId);
        if ($channel === null) {
            $this->fail($publication, $post, $variant, $started, 'channel_missing', 'Канал отключён от сервиса, поэтому пост не отправлен.', 'channel row is gone');

            return;
        }
        if ($channel->status === ChannelStatus::Paused) {
            $this->fail($publication, $post, $variant, $started, 'channel_paused', 'Канал стоит на паузе. Возобновите его в разделе «Каналы» и повторите публикацию.', 'channel is paused');

            return;
        }
        if (!$this->registry->isEnabled($channel->platform)) {
            $this->fail($publication, $post, $variant, $started, 'platform_disabled', 'Эта соцсеть сейчас отключена.', 'platform is not enabled');

            return;
        }
        $channel = $this->preflight($channel);
        if ($channel->status !== ChannelStatus::Active) {
            $this->fail($publication, $post, $variant, $started, 'channel_broken', 'Канал сейчас не работает' . ($channel->lastError !== null ? ': ' . $channel->lastError : '') . '. Переподключите его в разделе «Каналы».', 'channel status ' . $channel->status->value);

            return;
        }
        $context = $this->contexts->forPost($post);
        if ($context === null) {
            $this->fail($publication, $post, $variant, $started, 'no_owner', 'В пространстве не осталось участников, от имени которых можно опубликовать.', 'no member to act for');

            return;
        }
        $adapter = $this->registry->adapter($channel->platform);
        $resolved = $variant->resolve($post);
        $problems = $this->validator->problems($context, $resolved, $channel, false);
        if ($problems !== []) {
            $this->fail($publication, $post, $variant, $started, 'invalid_content', implode(' ', $problems), 'validation failed before sending');

            return;
        }

        $built = null;
        try {
            $built = $this->builder->build($context, $resolved, $adapter->capabilities(), true);
        } catch (PostException $e) {
            $this->abortBeforeSending($publication, $post, $variant, $started, $e->retryable ? ErrorKind::Temporary : ErrorKind::Permanent, 'media', $e->getMessage(), 'building the request failed');

            return;
        } catch (Throwable $e) {
            $this->abortBeforeSending($publication, $post, $variant, $started, ErrorKind::Temporary, 'prepare', 'Не удалось подготовить пост к отправке. Попробуем ещё раз.', $e::class . ': ' . $e->getMessage());

            return;
        }

        $request = $built->request->withIdempotencyKey($publication->idempotencyKey);
        try {
            // Media preparation may take time. Revalidate immediately before the existing adapter sends anything.
            $originError = $this->origin->problem($post);
            $problemCode = 'origin_stale';
            if ($originError === null && ($originError = $this->approvals->publicationProblem($post)) !== null) {
                $problemCode = 'approval_required';
            }
            if ($originError !== null) {
                $this->fail($publication, $post, $variant, $started, $problemCode, $originError, 'content origin changed during preparation');
                return;
            }
            $result = $adapter->publish($request, $channel->externalId, $this->credentials->forChannel($channel), $publication->idempotencyKey);
        } catch (PlatformError $e) {
            // An unknown outcome is first checked against the channel itself (where the network allows it): a post that is there is a success.
            $result = $e->kind === ErrorKind::UnknownOutcome ? $this->findPublished($adapter, $request, $channel, $started) : null;
            if ($result === null) {
                $this->onPlatformError($publication, $post, $variant, $channel, $started, $e);

                return;
            }
        } catch (Throwable $e) {
            // Something broke while the request may have been on its way: the outcome is unknown, never retried by itself.
            $result = $this->findPublished($adapter, $request, $channel, $started);
            if ($result === null) {
                $this->system->recordAttempt($publication, 'unknown', ErrorKind::UnknownOutcome->value, 'Не удалось выяснить, вышел ли пост.', $e::class . ': ' . $e->getMessage(), $started);
                $this->system->markUnknown($publication, 'internal', 'Не удалось выяснить, вышел ли пост. Проверьте канал.', $e::class . ': ' . $e->getMessage());
                $this->afterFailure($post, $variant, 'Не удалось выяснить, вышел ли пост. Проверьте канал и отметьте результат на странице поста.', true);

                return;
            }
        } finally {
            $built->cleanup();
        }

        if ($result->processing) {
            if ($this->system->move($publication->id, PublicationStatus::Sending, PublicationStatus::Processing, ['external_post_id' => $result->externalId])) {
                $this->system->recordAttempt($publication, 'processing', null, null, null, $started);
                $this->system->syncPostStatus($post->id);
                $this->videoSessions->pollLater($publication->idempotencyKey, 30);
                $this->queue->dispatch(new VideoProcessingJob($publication->id), 30, PublishJob::VIDEO_QUEUE);
            }
            return;
        }
        $this->onSuccess($publication, $post, $variant, $channel, $adapter, $result, $started);
    }

    /** Poll a network that accepted a video but has not finished processing it. */
    public function pollVideo(int $publicationId): void
    {
        $key = 'publish:poll:' . $publicationId;
        $token = bin2hex(random_bytes(8));
        if ($this->redis->set($key, $token, ['nx', 'ex' => 120]) !== true) {
            return;
        }
        try {
            $publication = $this->system->find($publicationId);
            if ($publication === null || $publication->status !== PublicationStatus::Processing) {
                return;
            }
            $loaded = $this->system->load($publication);
            $channel = $publication->channelId === null ? null : $this->channels->find($publication->channelId);
            $session = $this->videoSessions->find($publication->idempotencyKey);
            if ($loaded === null || $channel === null || $session === null || $session['remote_id'] === null) {
                $message = 'Не удалось проверить обработку видео. Проверьте канал.';
                $this->system->markUnknown($publication, 'video_session_lost', $message, 'video session missing', PublicationStatus::Processing);
                if ($loaded !== null) {
                    $this->afterFailure($loaded['post'], $loaded['variant'], $message, true);
                } else {
                    $this->system->syncPostStatus($publication->postId);
                }
                return;
            }
            if ($session['deadline_at'] <= $this->clock->now()) {
                $message = 'Сеть слишком долго обрабатывает видео. Проверьте канал, прежде чем повторять публикацию.';
                $this->system->markUnknown($publication, 'video_timeout', $message, 'processing deadline exceeded', PublicationStatus::Processing);
                $this->afterFailure($loaded['post'], $loaded['variant'], $message, true);
                return;
            }
            $adapter = $this->registry->adapter($channel->platform);
            if (!$adapter instanceof VideoProcessingAdapter) {
                throw new \LogicException('A processing video needs a video processing adapter.');
            }
            try {
                $result = $adapter->pollVideo($session['remote_id'], $channel->externalId, $this->credentials->forChannel($channel));
            } catch (PlatformError $error) {
                if (in_array($error->kind, [ErrorKind::Temporary, ErrorKind::RateLimited], true)) {
                    $this->pollAgain($publication, $error->retryAfter ?? 120);
                    return;
                }
                $message = $error->forUser();
                if ($error->kind === ErrorKind::UnknownOutcome) {
                    $this->system->markUnknown($publication, 'video_status_unknown', $message, $error->getMessage(), PublicationStatus::Processing);
                    $this->afterFailure($loaded['post'], $loaded['variant'], $message, true);
                } else {
                    $this->system->markFailed($publication, 'video_processing', $message, $error->getMessage(), PublicationStatus::Processing);
                    $this->afterFailure($loaded['post'], $loaded['variant'], $message, false);
                }
                return;
            }
            if ($result === null) {
                $elapsedMinutes = $publication->startedAt === null ? 0 : max(0, intdiv($this->clock->now()->getTimestamp() - $publication->startedAt->getTimestamp(), 60));
                $this->pollAgain($publication, min(900, 30 * (2 ** min(5, $elapsedMinutes))));
                return;
            }
            $coverId = $loaded['variant']->resolve($loaded['post'])->options->shortVideo?->coverMediaId;
            if ($coverId !== null && $adapter instanceof VideoThumbnailAdapter) {
                $context = $this->contexts->forPost($loaded['post']);
                $coverPath = null;
                try {
                    if ($context === null) {
                        throw new PostException('Не удалось подготовить обложку: нет участника пространства.');
                    }
                    $coverPath = $this->builder->coverFile($context, $coverId);
                    $adapter->setThumbnail($result->externalId, $coverPath, $this->credentials->forChannel($channel));
                } catch (Throwable $error) {
                    $this->system->recordAttempt(
                        $publication,
                        'thumbnail_failed',
                        $error instanceof PlatformError ? $error->kind->value : null,
                        'Ролик опубликован, но обложку YouTube установить не удалось.',
                        $error::class . ': ' . $error->getMessage(),
                        $this->clock->now()
                    );
                } finally {
                    if ($coverPath !== null) {
                        @unlink($coverPath);
                    }
                }
            }
            $this->onSuccess($publication, $loaded['post'], $loaded['variant'], $channel, $adapter, $result, $publication->startedAt ?? $this->clock->now(), PublicationStatus::Processing);
        } finally {
            $this->redis->eval('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end', [$key, $token], 1);
        }
    }

    private function pollAgain(Publication $publication, int $seconds): void
    {
        $delay = max(30, min(900, $seconds));
        $this->videoSessions->pollLater($publication->idempotencyKey, $delay);
        $this->queue->dispatch(new VideoProcessingJob($publication->id), $delay, PublishJob::VIDEO_QUEUE);
    }

    /**
     * Look for a post whose publication ended without a clear answer, among the latest posts of the channel. Never throws: when the channel
     * cannot be read or the network cannot tell, the outcome stays unknown and a person decides.
     */
    private function findPublished(PlatformAdapter $adapter, PublishRequest $request, Channel $channel, DateTimeImmutable $started): ?PublishResult
    {
        if (!$adapter instanceof OutcomeVerifier) {
            return null;
        }
        try {
            $found = $adapter->findPublished($request, $channel->externalId, $this->credentials->forChannel($channel), $started);
        } catch (Throwable $e) {
            $this->logger->info('publish.verify_failed', ['channel' => $channel->publicId, 'reason' => $e::class]);

            return null;
        }
        if ($found !== null) {
            $this->logger->info('publish.verified', ['channel' => $channel->publicId]);
        }

        return $found;
    }

    private function onSuccess(Publication $publication, Post $post, PostVariant $variant, Channel $channel, PlatformAdapter $adapter, PublishResult $result, DateTimeImmutable $started, PublicationStatus $from = PublicationStatus::Sending): void
    {
        $options = $variant->resolve($post)->options;
        $capabilities = $adapter->capabilities();
        $deleteAt = $options->deleteAfterMinutes !== null && $capabilities->delete ? $this->clock->now()->modify(sprintf('+%d minutes', $options->deleteAfterMinutes)) : null;
        $url = $result->url ?? $channel->postUrl($result->externalId);
        $stored = $this->system->markSent($publication, $result->externalId, $url, $result->allIds, $deleteAt, $from);
        if (!$stored) {
            if ($from === PublicationStatus::Processing) {
                return;
            }
            // A reaper judged this publication lost while a very slow upload was still running: the post did go out, so correct the record.
            $this->system->move($publication->id, PublicationStatus::Unknown, PublicationStatus::Sent, [
                'external_post_id' => $result->externalId,
                'external_ids_json' => json_encode($result->allIds === [] ? [$result->externalId] : $result->allIds, JSON_THROW_ON_ERROR),
                'external_url' => $url,
                'sent_at' => DbTime::format($this->clock->now()),
                'delete_at' => $deleteAt === null ? null : DbTime::format($deleteAt),
                'error_code' => null,
                'error_message' => null,
                'error_detail' => null,
            ]);
        }
        $this->system->recordAttempt($publication, 'sent', null, null, null, $started);
        if ($post->kind === PostKind::ShortVideo) {
            $this->analytics->track('short_video_published', null, $post->workspaceId, ['platform' => $channel->platform->value], 'short_video_published:' . $publication->id);
        }

        $credential = null;
        if ($options->pin && $capabilities->pin) {
            try {
                $credential = $this->credentials->forChannel($channel);
                $adapter->pin($result, $channel->externalId, $credential, true);
                $this->system->markPinned($publication);
            } catch (Throwable $e) {
                $this->system->recordAttempt($publication, 'pin_failed', $e instanceof PlatformError ? $e->kind->value : null, 'Пост опубликован, но закрепить его не удалось' . ($e instanceof PlatformError ? ': ' . $e->forUser() : '.'), $e::class . ': ' . $e->getMessage(), $this->clock->now());
            }
        }
        if ($options->firstComment !== '' && $capabilities->firstComment && $adapter instanceof CommentingAdapter) {
            try {
                $adapter->comment($result, $channel->externalId, $credential ?? $this->credentials->forChannel($channel), TextFormatter::toPlain($options->firstComment));
            } catch (Throwable $e) {
                $this->system->recordAttempt($publication, 'comment_failed', $e instanceof PlatformError ? $e->kind->value : null, 'Пост опубликован, но первый комментарий оставить не удалось' . ($e instanceof PlatformError ? ': ' . $e->forUser() : '.'), $e::class . ': ' . $e->getMessage(), $this->clock->now());
            }
        }

        $previous = $post->status;
        $status = $this->system->syncPostStatus($post->id);
        $workspace = $this->workspaces->findById($post->workspaceId);
        if ($status === PostStatus::Published && $previous !== PostStatus::Published) {
            $this->audit->record('post.published', null, 'post', $post->publicId, ['title' => $post->title(60)], $post->workspaceId);
        }
        if ($workspace !== null) {
            $this->notifier->published($post, $variant, $workspace->publicId, $url);
        }
        $this->triggers->onPublished($post, $variant);
        // The metric of the product promise "published within 60 seconds": how late the post was, from the planned moment.
        $this->logger->info('publish.latency', [
            'publication' => $publication->publicId,
            'platform' => $channel->platform->value,
            'seconds' => round((float) $this->clock->now()->format('U.u') - (float) $publication->dueAt->format('U.u'), 3),
            'attempts' => $publication->attempt,
        ]);
    }

    private function onPlatformError(Publication $publication, Post $post, PostVariant $variant, Channel $channel, DateTimeImmutable $started, PlatformError $error): void
    {
        $detail = $error->kind->value . ($error->platformCode !== null ? ' ' . $error->platformCode : '') . ': ' . $error->getMessage();
        $this->system->recordAttempt($publication, $error->kind->value, $error->kind->value, $error->forUser(), $detail, $started);

        switch ($error->kind) {
            case ErrorKind::Temporary:
            case ErrorKind::RateLimited:
                if ($publication->attempt >= self::MAX_ATTEMPTS) {
                    $this->system->markFailed($publication, 'retries_exhausted', $error->forUser() . ' Мы пробовали ' . self::MAX_ATTEMPTS . ' раз.', $detail);
                    $this->afterFailure($post, $variant, $error->forUser() . ' Мы пробовали ' . self::MAX_ATTEMPTS . ' раз, повторите позже.', false);

                    return;
                }
                $delay = $error->kind === ErrorKind::RateLimited && $error->retryAfter !== null
                    ? max(1, min($error->retryAfter, 90000))
                    : self::BACKOFF[min($publication->attempt, count(self::BACKOFF)) - 1];
                $this->requeue($publication, $post->kind, $delay, $error->kind === ErrorKind::RateLimited ? 'rate_limited' : 'temporary', $error->forUser(), $detail);
                $this->system->syncPostStatus($post->id);

                return;
            case ErrorKind::Auth:
                $this->system->markFailed($publication, 'auth', $error->forUser(), $detail);
                $this->health->markBroken($channel, ChannelStatus::Error, $error->forUser(), $error);
                $this->afterFailure($post, $variant, $error->forUser(), false);

                return;
            case ErrorKind::Permanent:
                $this->system->markFailed($publication, 'rejected', $error->forUser(), $detail);
                // The refusal may be about the content or about the channel: a fresh check tells (and marks it, if broken).
                $this->health->check($channel);
                $this->afterFailure($post, $variant, $error->forUser(), false);

                return;
            case ErrorKind::UnknownOutcome:
                $this->system->markUnknown($publication, 'unknown_outcome', $error->forUser(), $detail);
                $this->afterFailure($post, $variant, $error->forUser() . ' Проверьте канал и отметьте результат на странице поста.', true);

                return;
        }
    }

    /**
     * Nothing was sent (the failure was before the call): retry later, or give up when the attempts are used up or the cause is permanent.
     */
    private function abortBeforeSending(Publication $publication, Post $post, PostVariant $variant, DateTimeImmutable $started, ErrorKind $kind, string $code, string $message, string $detail): void
    {
        $this->system->recordAttempt($publication, $kind->value, $kind->value, $message, $detail, $started);
        if ($kind === ErrorKind::Temporary && $publication->attempt < self::MAX_ATTEMPTS) {
            $this->requeue($publication, $post->kind, self::BACKOFF[min($publication->attempt, count(self::BACKOFF)) - 1], $code, $message, $detail);
            $this->system->syncPostStatus($post->id);

            return;
        }
        $this->system->markFailed($publication, $code, $message, $detail);
        $this->afterFailure($post, $variant, $message, false);
    }

    private function requeue(Publication $publication, PostKind $kind, int $delaySeconds, string $code, string $message, string $detail): void
    {
        $runAt = $this->clock->now()->modify(sprintf('+%d seconds', $delaySeconds));
        if (!$this->system->requeue($publication, $runAt, $code, $message, $detail)) {
            return;
        }
        // The job for the next attempt is created right away, so a retry does not wait for the next scheduler tick.
        $this->queue->dispatch(new PublishJob($publication->id), $delaySeconds, $kind === PostKind::ShortVideo ? PublishJob::VIDEO_QUEUE : PublishJob::QUEUE);
        $this->system->markEnqueued([$publication->id]);
    }

    private function fail(Publication $publication, Post $post, PostVariant $variant, DateTimeImmutable $started, string $code, string $message, string $detail): void
    {
        $this->system->recordAttempt($publication, 'failed', null, $message, $detail, $started);
        $this->system->markFailed($publication, $code, $message, $detail);
        $this->afterFailure($post, $variant, $message, false);
    }

    private function afterFailure(Post $post, PostVariant $variant, string $reason, bool $uncertain): void
    {
        if ($post->kind === PostKind::ShortVideo) {
            $this->analytics->track('short_video_failed', null, $post->workspaceId, ['platform' => $variant->platform->value, 'uncertain' => $uncertain ? 1 : 0]);
        }
        $this->system->syncPostStatus($post->id);
        $workspace = $this->workspaces->findById($post->workspaceId);
        if ($workspace !== null) {
            $this->notifier->publicationFailed($post, $variant, $workspace->publicId, $reason, $uncertain);
        }
        if (!$uncertain) {
            $this->triggers->onFailed($post, $variant);
        }
    }

    /**
     * The channel is checked right before publishing when the last check is older than `platforms.channels.preflight_minutes`.
     */
    private function preflight(Channel $channel): Channel
    {
        $limit = $this->clock->now()->modify(sprintf('-%d minutes', max(0, $this->config->int('platforms.channels.preflight_minutes', 15))));
        if ($channel->lastHealthAt !== null && $channel->lastHealthAt >= $limit && $channel->status === ChannelStatus::Active) {
            return $channel;
        }

        return $this->health->check($channel);
    }
}
