<?php

declare(strict_types=1);

namespace App\Domain\Automation\Trigger;

use App\Kernel\Queue\AbstractJob;

/**
 * Runs one armed trigger now. Safe to run twice: `TriggerEngine::fire()` claims the trigger, so only one run does anything.
 */
final class FireTriggerJob extends AbstractJob
{
    public function __construct(public readonly int $triggerId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new static(is_int($payload['trigger_id'] ?? null) ? $payload['trigger_id'] : 0);
    }

    public function toPayload(): array
    {
        return ['trigger_id' => $this->triggerId];
    }

    public function handle(TriggerEngine $engine): void
    {
        $engine->fire($this->triggerId);
    }
}
