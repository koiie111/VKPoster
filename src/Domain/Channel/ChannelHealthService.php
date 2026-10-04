<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\PlatformRegistry;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\HealthStatus;
use App\Kernel\Config;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use DateInterval;
use Psr\Log\LoggerInterface;

/**
 * Is the channel still usable: the bot is still there and may still post. Run every few hours for all channels, right
 * before publishing, when the bot reports a change in its membership, and on the owner's request. A channel that stops working
 * is marked `error` or `revoked` with the reason, and the owner and administrators get one email (not one per check).
 */
final class ChannelHealthService
{
    private const BATCH = 500;

    public function __construct(
        private readonly ChannelSystem $channels,
        private readonly ChannelCredentials $credentials,
        private readonly PlatformRegistry $registry,
        private readonly ChannelMailer $mailer,
        private readonly Queue $queue,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queue a check for every channel not checked within the interval. Called by the scheduler (hourly, so the load is spread).
     *
     * @return int number of checks queued
     */
    public function enqueueDue(): int
    {
        $hours = max(1, $this->config->int('platforms.channels.health_interval_hours', 6));
        $older = $this->clock->now()->sub(new DateInterval('PT' . $hours . 'H'));
        $ids = $this->channels->dueForHealthCheck($this->registry->enabled(), $older, self::BATCH);
        foreach ($ids as $id) {
            $this->queue->dispatch(new CheckChannelHealthJob($id), 0, 'default');
        }

        return count($ids);
    }

    /**
     * Check one channel now and store the verdict. Returns the channel as it is after the check.
     */
    public function check(Channel $channel): Channel
    {
        if (!$this->registry->isEnabled($channel->platform)) {
            return $channel;
        }
        try {
            $status = $this->registry->adapter($channel->platform)->healthCheck($channel->externalId, $this->credentials->forChannel($channel));
        } catch (PlatformError $e) {
            $status = $e->concernsChannel() ? HealthStatus::broken($e->forUser()) : HealthStatus::unknown($e->forUser());
        }

        if ($status->ok) {
            $this->channels->recordHealthy($channel, $status->rights, $status->title);
        } elseif ($status->transient) {
            $this->logger->info('Channel health check inconclusive', ['channel' => $channel->publicId]);
            $this->channels->recordAttempt($channel);
        } else {
            $this->markBroken($channel, $status->revoked ? ChannelStatus::Revoked : ChannelStatus::Error, $status->message);
        }

        return $this->channels->find($channel->id) ?? $channel;
    }

    /**
     * Record that a channel is broken and, if it just stopped working, tell the people in charge. A paused channel keeps its
     * status (the owner chose it) but the reason is stored.
     */
    public function markBroken(Channel $channel, ChannelStatus $status, string $reason): void
    {
        $this->channels->recordBroken($channel, $channel->status === ChannelStatus::Paused ? ChannelStatus::Paused : $status, $reason);
        if ($channel->status !== ChannelStatus::Active) {
            return;
        }
        $workspace = $this->channels->workspaceInfo($channel->workspaceId);
        if ($workspace === null) {
            return;
        }
        foreach ($this->channels->alertRecipients($channel->workspaceId) as $person) {
            $this->mailer->broken($person['email'], $person['name'], $channel->displayName(), $channel->platform->label(), $workspace['name'], $workspace['public_id'], $reason);
        }
    }
}
