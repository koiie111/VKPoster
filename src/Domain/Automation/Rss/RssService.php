<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use App\Domain\Audit\AuditLog;
use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Queue\QueueRepository;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\PostService;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\PlatformRegistry;

/**
 * Creating and changing RSS feeds, with what the plan allows and a first reading to prove the address works.
 */
final class RssService
{
    /** @var list<int> minutes */
    public const INTERVALS = [15, 30, 60, 180, 360, 1440];
    public const MAX_TEMPLATE = 2000;

    public function __construct(
        private readonly RssRepository $feeds,
        private readonly ChannelRepository $channels,
        private readonly QueueRepository $queues,
        private readonly PostService $posts,
        private readonly FeedFetcher $fetcher,
        private readonly PlatformRegistry $registry,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param list<string> $channelPublicIds
     * @throws AutomationException
     */
    public function create(WorkspaceContext $context, string $name, string $url, string $template, string $mode, ?string $queuePublicId, int $intervalMinutes, array $channelPublicIds): RssFeed
    {
        $this->authorize($context);
        $this->assertRoom($context, null);
        [$name, $url, $template, $queueId, $channelIds] = $this->check($context, $name, $url, $template, $mode, $queuePublicId, $intervalMinutes, $channelPublicIds);
        try {
            $this->fetcher->fetch($url, null, null);
        } catch (FeedException $e) {
            throw new AutomationException($e->getMessage());
        }
        $feed = $this->feeds->create($context, $name, $url, $template, $mode, $queueId, $intervalMinutes, $channelIds);
        $this->audit->record('rss.created', $context->userId, 'rss_feed', $feed->publicId, ['name' => $name], $context->workspaceId);

        return $feed;
    }

    /**
     * @param list<string> $channelPublicIds
     * @throws AutomationException
     */
    public function update(WorkspaceContext $context, RssFeed $feed, string $name, string $url, string $template, string $mode, ?string $queuePublicId, int $intervalMinutes, array $channelPublicIds): void
    {
        $this->authorize($context);
        [$name, $url, $template, $queueId, $channelIds] = $this->check($context, $name, $url, $template, $mode, $queuePublicId, $intervalMinutes, $channelPublicIds);
        $this->feeds->update($context, $feed, $name, $url, $template, $mode, $queueId, $intervalMinutes, $channelIds);
        $this->audit->record('rss.updated', $context->userId, 'rss_feed', $feed->publicId, ['name' => $name], $context->workspaceId);
    }

    public function setEnabled(WorkspaceContext $context, RssFeed $feed, bool $enabled): void
    {
        $this->authorize($context);
        if ($enabled && !$feed->enabled) {
            $this->assertRoom($context, $feed);
        }
        $this->feeds->setEnabled($context, $feed, $enabled);
        $this->audit->record($enabled ? 'rss.enabled' : 'rss.disabled', $context->userId, 'rss_feed', $feed->publicId, [], $context->workspaceId);
    }

    public function delete(WorkspaceContext $context, RssFeed $feed): void
    {
        $this->authorize($context);
        $this->feeds->delete($context, $feed);
        $this->audit->record('rss.deleted', $context->userId, 'rss_feed', $feed->publicId, ['name' => $feed->name], $context->workspaceId);
    }

    /**
     * @throws AutomationException
     */
    private function authorize(WorkspaceContext $context): void
    {
        if (!$this->permissions->allows($context->role, 'posts.publish')) {
            throw new AutomationException('Ленты настраивают редакторы и администраторы.', forbidden: true);
        }
        try {
            $this->entitlements->assertFeature($context->workspaceId, 'crosspost', 'Ленты RSS');
        } catch (PlanLimitException $e) {
            throw new AutomationException($e->getMessage(), planLimit: true);
        }
    }

    /**
     * The number of feeds a plan allows is `rss_feeds`; a plan stored before that limit existed follows its cross-posting limit.
     *
     * @throws AutomationException
     */
    private function assertRoom(WorkspaceContext $context, ?RssFeed $except): void
    {
        $plan = $this->entitlements->plan($context->workspaceId);
        $limit = array_key_exists('rss_feeds', $plan->limits) ? $plan->limits['rss_feeds'] : $plan->limit('crosspost_rules');
        if ($limit === null) {
            return;
        }
        $active = count(array_filter($this->feeds->all($context), static fn (RssFeed $f): bool => $f->enabled && ($except === null || $f->id !== $except->id)));
        if ($active >= $limit) {
            throw new AutomationException(sprintf('На тарифе «%s» можно включить лент: %d. Выключите одну из них или перейдите на тариф выше.', $plan->name, $limit), planLimit: true);
        }
    }

    /**
     * @param list<string> $channelPublicIds
     * @return array{string, string, string, ?int, list<int>}
     * @throws AutomationException
     */
    private function check(WorkspaceContext $context, string $name, string $url, string $template, string $mode, ?string $queuePublicId, int $intervalMinutes, array $channelPublicIds): array
    {
        $name = trim($name);
        $url = trim($url);
        $template = trim($template) === '' ? RssFeed::DEFAULT_TEMPLATE : trim($template);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new AutomationException('Назовите ленту: от 1 до 100 символов.');
        }
        if (preg_match('~^https?://[^\s]{4,1990}$~i', $url) !== 1) {
            throw new AutomationException('Вставьте адрес ленты, который начинается с http:// или https://.');
        }
        if (mb_strlen($template) > self::MAX_TEMPLATE) {
            throw new AutomationException('Шаблон поста длиннее ' . self::MAX_TEMPLATE . ' символов.');
        }
        if (!in_array($intervalMinutes, self::INTERVALS, true)) {
            throw new AutomationException('Выберите, как часто читать ленту.');
        }
        if (!in_array($mode, [RssFeed::MODE_DRAFT, RssFeed::MODE_QUEUE], true)) {
            throw new AutomationException('Выберите, что делать с новыми записями.');
        }
        $queueId = null;
        if ($mode === RssFeed::MODE_QUEUE) {
            $queue = $this->queues->find($context, $queuePublicId ?? '') ?? throw new AutomationException('Выберите очередь, в которую ставить записи.');
            $queueId = $queue->id;
        }
        $allowed = $this->posts->allowedChannels($context);
        $ids = [];
        foreach ($channelPublicIds as $publicId) {
            $channel = $this->channels->find($context, $publicId);
            if ($channel === null || ($allowed !== null && !in_array($channel->id, $allowed, true))) {
                throw new AutomationException('Один из выбранных каналов не найден или недоступен вам.');
            }
            if (!$this->registry->isEnabled($channel->platform)) {
                throw new AutomationException('Сеть «' . $channel->platform->label() . '» сейчас отключена.');
            }
            if ($queueId !== null && !in_array($channel->id, $this->queues->findById($context, $queueId)?->channelIds ?? [], true)) {
                throw new AutomationException('Канал «' . $channel->displayName() . '» не входит в выбранную очередь.');
            }
            $ids[$channel->id] = $channel->id;
        }
        if ($ids === []) {
            throw new AutomationException('Выберите хотя бы один канал, куда публиковать записи.');
        }

        return [$name, $url, $template, $queueId, array_values($ids)];
    }
}
