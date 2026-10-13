<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

use DateTimeImmutable;

/**
 * "If … then …": an event, an action and what they concern (one post, or any post of one channel). Fires once.
 * `pending` waits for its event, `armed` has seen it and waits for `fireAt`, `done` has fired, `failed` fired but the action failed.
 */
final class Trigger
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly ?int $postId,
        public readonly ?int $channelId,
        public readonly ?int $eventPostId,
        public readonly TriggerEvent $event,
        public readonly int $delayMinutes,
        public readonly ?DateTimeImmutable $fireAt,
        public readonly TriggerAction $action,
        public readonly array $params,
        public readonly string $status,
        public readonly ?DateTimeImmutable $firedAt,
        public readonly ?string $result,
        public readonly ?int $createdBy,
    ) {
    }

    /**
     * The post the action works on: the trigger's own post, or the one whose event armed a channel trigger.
     */
    public function targetPostId(): ?int
    {
        return $this->postId ?? $this->eventPostId;
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' || $this->status === 'armed';
    }
}
