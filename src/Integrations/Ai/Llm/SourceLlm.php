<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

use App\Domain\Ai\AiWorkspaceSettings;
use App\Integrations\ContentProviders\ProviderException;

/** Shared model selection for source text and semantic operations, using the editor's provider registry. */
final class SourceLlm
{
    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(private readonly LlmFactory $factory, private readonly LlmChain $chain, private readonly AiWorkspaceSettings $workspaceSettings)
    {
    }

    public function ownKey(int $workspaceId): bool
    {
        return $this->workspaceSettings->own($workspaceId) !== null;
    }

    public function available(int $workspaceId, string $operation): bool
    {
        if ($this->ownKey($workspaceId) || $this->chain->usable($operation) !== []) {
            return true;
        }
        $default = $this->factory->default();
        return $default !== null && !$default instanceof FakeLlmProvider;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function complete(int $workspaceId, string $operation, string $system, string $user): string
    {
        $this->metadata = [];
        $own = $this->workspaceSettings->own($workspaceId);
        $steps = $own === null ? $this->chain->usable($operation) : [];
        $provider = $own === null ? ($steps === [] ? $this->factory->default() : null) : $this->factory->own($own['base_url'], $own['model'], $own['api_key'], $own['auth']);
        if ($provider instanceof FakeLlmProvider) {
            $provider = null;
        }
        if ($provider === null && $steps === []) {
            throw new ProviderException('not_configured');
        }
        $request = new LlmRequest($system, $user, 2048, 0.2);
        $last = null;
        foreach ($provider !== null ? [null] : $steps as $step) {
            if ($step !== null && !$this->chain->acquire($step)) {
                continue;
            }
            $candidate = $step === null ? $provider : $this->factory->forStep($step['provider'], $step['model']);
            if ($candidate === null) {
                continue;
            }
            try {
                $result = $candidate->complete($request);
                $this->metadata = ['provider' => $candidate->name(), 'model' => $result->model, 'input_tokens' => $result->tokensIn, 'output_tokens' => $result->tokensOut, 'own_key' => $own !== null, 'charge_credits' => $own === null && ($step === null || $step['charge_credits'])];
                return $result->text;
            } catch (LlmException $e) {
                $last = $e;
                if ($step !== null) {
                    $this->chain->failed($step, $e->reason);
                }
                if ($step === null || $e->reason === 'refused') {
                    break;
                }
            }
        }
        $reason = $last === null ? 'unavailable' : $last->reason;
        throw new ProviderException($reason, in_array($reason, ['rate_limited', 'unavailable', 'timeout'], true));
    }
}
