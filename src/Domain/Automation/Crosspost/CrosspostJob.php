<?php

declare(strict_types=1);

namespace App\Domain\Automation\Crosspost;

use App\Kernel\Queue\AbstractJob;

/**
 * Handles one buffered channel post or album a few seconds after it arrived (the delay lets all parts of an album come in).
 * Safe to run twice: the buffer claim lets only the first run take the messages.
 */
final class CrosspostJob extends AbstractJob
{
    public function __construct(public readonly int $chatId, public readonly string $groupKey)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['chat_id'] ?? null) ? $payload['chat_id'] : 0, is_string($payload['group'] ?? null) ? $payload['group'] : '');
    }

    public function toPayload(): array
    {
        return ['chat_id' => $this->chatId, 'group' => $this->groupKey];
    }

    public function handle(CrosspostProcessor $processor): void
    {
        $processor->process($this->chatId, $this->groupKey);
    }
}
