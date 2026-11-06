<?php

declare(strict_types=1);

namespace App\Domain\Content\Automation;

use App\Kernel\Config;
use App\Integrations\Ai\Llm\SourceLlm;

/** Cost guard: explicit operation permission, real credentials and no demonstration work in production. */
final class AutomationGuard
{
    public function __construct(private readonly Config $config, private readonly ?SourceLlm $llm = null)
    {
    }
    public function allows(string $operation, ?int $workspaceId = null): bool
    {
        $provider = $this->config->string('content_providers.' . $operation);
        if ($provider === 'fake') {
            return !$this->config->isProduction();
        }
        if ($provider === 'disabled') {
            return true;
        }
        if ($provider === 'llm') {
            return $workspaceId !== null && $this->llm !== null && $this->llm->available($workspaceId, $operation === 'text' ? 'source_text' : 'source_semantic');
        }
        $key = match ($provider) {
            'tineye' => 'tineye_key', 'replicate' => 'replicate_token', default => null,
        };
        return $key !== null && trim($this->config->string('content_providers.' . $key)) !== '';
    }
    public function discovery(): bool
    {
        // All current Discovery providers are fixtures; no background fixtures in production.
        return !$this->config->isProduction();
    }
}
