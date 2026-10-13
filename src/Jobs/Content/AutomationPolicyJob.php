<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Domain\Content\Automation\Automation;
use App\Kernel\Queue\AbstractJob;

/** Bounded policy orchestration isolated by workspace; payload contains identities only. */
final class AutomationPolicyJob extends AbstractJob
{
    public function __construct(public readonly int $workspaceId, public readonly int $policyId, public readonly int $version)
    {
    }
    public static function fromPayload(array $payload): static
    {
        return new self((int) ($payload['workspace_id'] ?? 0), (int) ($payload['policy_id'] ?? 0), (int) ($payload['version'] ?? 0));
    }
    public function toPayload(): array
    {
        return ['workspace_id' => $this->workspaceId, 'policy_id' => $this->policyId, 'version' => $this->version];
    }
    public function handle(Automation $automation): void
    {
        $automation->scanPolicy($this->workspaceId, $this->policyId, $this->version);
    }
}
