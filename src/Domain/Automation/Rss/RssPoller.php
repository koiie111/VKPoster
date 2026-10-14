<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Crosspost\TelegramText;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Automation\Queue\QueueService;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Media\MediaException;
use App\Domain\Media\MediaFetcher;
use App\Domain\Media\MediaService;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostException;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostService;
use App\Domain\Post\PublishingContexts;
use App\Domain\Post\VariantInput;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads feeds and makes posts from new entries.
 *
 * `tick()` (every minute) finds the feeds whose time has come, moves their next time forward at once (so a slow reading is not
 * started twice) and queues a job each. `poll()` reads one feed with a conditional request. The first reading of a feed only notes
 * what is already there: a new subscription publishes what appears afterwards, not the whole archive. Each entry is claimed by its
 * guid before a post is made, so an entry becomes a post once, however many times the feed is read. A failing feed is tried again
 * later and later (up to a day) and its last error is shown to the person.
 */
final class RssPoller
{
    public const MAX_NEW_PER_READING = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly RssRepository $feeds,
        private readonly FeedFetcher $fetcher,
        private readonly PostService $posts,
        private readonly ChannelRepository $channels,
        private readonly QueueRepository $queues,
        private readonly QueueService $queueService,
        private readonly MediaFetcher $mediaFetcher,
        private readonly MediaService $media,
        private readonly PublishingContexts $contexts,
        private readonly Queue $queue,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int feeds queued for reading
     */
    public function tick(): int
    {
        $now = DbTime::format($this->clock->now());
        $queued = 0;
        foreach ($this->db->select('SELECT id, interval_minutes FROM rss_feeds WHERE enabled = 1 AND next_check_at <= ? ORDER BY next_check_at LIMIT 100', [$now]) as $row) {
            $next = DbTime::format($this->clock->now()->modify('+' . max(5, (int) $row['interval_minutes']) . ' minutes'));
            if ($this->db->execute('UPDATE rss_feeds SET next_check_at = ? WHERE id = ? AND next_check_at <= ?', [$next, (int) $row['id'], $now]) === 1) {
                $this->queue->dispatch(new RssFeedJob((int) $row['id']));
                ++$queued;
            }
        }

        return $queued;
    }

    /**
     * Read one feed now.
     *
     * @return int posts made
     */
    public function poll(int $feedId): int
    {
        $header = $this->db->table('rss_feeds')->where('id', '=', $feedId)->first();
        if ($header === null) {
            return 0;
        }
        $context = $this->contexts->forWorkspace((int) $header['workspace_id'], isset($header['created_by']) ? (int) $header['created_by'] : null);
        $feed = $context === null ? null : $this->feeds->findById($context, $feedId);
        if ($context === null || $feed === null || !$feed->enabled) {
            return 0;
        }
        try {
            $result = $this->fetcher->fetch($feed->url, $feed->etag, $feed->lastModified);
        } catch (FeedException $e) {
            $this->feeds->recordCheck($context, $feed, null, null, $e->getMessage(), $this->retryAt($feed));

            return 0;
        } catch (Throwable $e) {
            $this->logger->error('RSS reading failed', ['feed' => $feed->publicId, 'exception' => $e]);
            $this->feeds->recordCheck($context, $feed, null, null, 'Не удалось прочитать ленту.', $this->retryAt($feed));

            return 0;
        }
        $made = 0;
        $error = null;
        if ($result['items'] !== null) {
            $first = $feed->lastCheckedAt === null;
            foreach (array_slice(array_reverse($result['items']), $first ? 0 : -self::MAX_NEW_PER_READING) as $item) {
                if (!$this->feeds->claimEntry($context, $feed, $item->key()) || $first) {
                    continue;
                }
                try {
                    $post = $this->makePost($context, $feed, $item);
                    $this->feeds->attachPost($context, $feed, $item->key(), $post);
                    ++$made;
                } catch (PostException | AutomationException $e) {
                    $error = 'Запись «' . mb_substr($item->title, 0, 60) . '» не превращена в пост: ' . $e->getMessage();
                }
            }
        }
        $this->feeds->recordCheck($context, $feed, $result['etag'], $result['last_modified'], $error, $this->clock->now()->modify('+' . max(5, $feed->intervalMinutes) . ' minutes'));

        return $made;
    }

    /**
     * The latest entries of a feed as they would become posts, for the "check the feed" button. Makes nothing.
     *
     * @return list<array{title: string, text: string, image: ?string}>
     * @throws FeedException
     */
    public function preview(string $url, string $template): array
    {
        $result = $this->fetcher->fetch($url, null, null);
        $out = [];
        foreach (array_slice($result['items'] ?? [], 0, 3) as $item) {
            $out[] = ['title' => $item->title, 'text' => self::render($template, $item), 'image' => $item->imageUrl];
        }

        return $out;
    }

    /**
     * The text of a post from a template: `{title}`, `{summary}` and `{link}` are replaced (and escaped, so a star in a title is not
     * read as formatting), empty lines are squeezed.
     */
    public static function render(string $template, FeedItem $item): string
    {
        $text = strtr($template, ['{title}' => TelegramText::escape($item->title), '{summary}' => TelegramText::escape($item->summary), '{link}' => TelegramText::escape($item->link)]);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return int id of the post
     * @throws PostException|AutomationException
     */
    private function makePost(WorkspaceContext $context, RssFeed $feed, FeedItem $item): int
    {
        $variants = [];
        foreach ($feed->channelIds as $channelId) {
            $channel = $this->channels->findById($context, $channelId);
            if ($channel !== null) {
                $variants[] = new VariantInput($channel->publicId);
            }
        }
        if ($variants === []) {
            throw new AutomationException('Каналы ленты отключены.');
        }
        $mediaIds = [];
        if ($item->imageUrl !== null) {
            $file = null;
            try {
                $file = $this->mediaFetcher->fetch($item->imageUrl);
                $mediaIds[] = $this->media->upload($context, $file['path'], $file['name'] !== '' ? $file['name'] : 'rss.jpg')->media->publicId;
            } catch (MediaException) {
                // A picture that can not be fetched or stored does not stop the post.
            } finally {
                if ($file !== null) {
                    @unlink($file['path']);
                }
            }
        }
        $post = $this->posts->saveDraft($context, null, new PostDraft(self::render($feed->template, $item), $mediaIds, new PostOptions(), false, $variants));
        if ($feed->mode === RssFeed::MODE_QUEUE && $feed->queueId !== null) {
            $queue = $this->queues->findById($context, $feed->queueId);
            if ($queue !== null) {
                $this->queueService->enqueue($context, $post, $queue, 'rss');
            }
        }

        return $post->id;
    }

    private function retryAt(RssFeed $feed): DateTimeImmutable
    {
        $minutes = min(1440, max(5, $feed->intervalMinutes) * (2 ** min(5, $feed->errorCount)));

        return $this->clock->now()->modify('+' . $minutes . ' minutes');
    }
}
