<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Domain\Content\Automation\Automation;
use App\Kernel\Queue\AbstractJob;

/** One workspace's policy scan; payload contains no source content or credentials. */
final class AutomationScanJob extends AbstractJob
{
    public function __construct(public readonly int $workspaceId)
    {
    }

    public static function fromPayload(array $payload): static
    {
        return new self((int) ($payload['workspace_id'] ?? 0));
    }

    public function toPayload(): array
    {
        return ['workspace_id' => $this->workspaceId];
    }

    public function handle(Automation $automation): void
    {
        $automation->scanWorkspace($this->workspaceId);
    }
}
