<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/**
 * Builds the provider object for a saved provider code, for a workspace's own endpoint, or the offline fake. The fake is returned only
 * when allowed (never in production) and only when nothing real is connected, so a misconfigured production site says "not connected"
 * instead of pretending to work.
 */
final class LlmFactory
{
    public function __construct(
        private readonly LlmSettings $settings,
        private readonly LlmTransport $transport,
        private readonly bool $fakeAllowed,
    ) {
    }

    /** The provider that answers for everybody by default, or null when nothing is connected. */
    public function default(): ?LlmProvider
    {
        $code = $this->settings->defaultCode();
        if ($code !== null) {
            return $this->forCode($code);
        }

        return $this->fakeAllowed ? new FakeLlmProvider() : null;
    }

    /** A chain step: the provider's key and address, with the step's own model when it names one. */
    public function forStep(string $code, string $model): ?LlmProvider
    {
        return $this->forCode($code, $model);
    }

    public function forCode(string $code, string $model = ''): ?LlmProvider
    {
        $provider = $this->settings->usable()[$code] ?? null;
        if ($provider === null) {
            return null;
        }

        return $this->build($code, $provider['kind'], $this->settings->baseUrl($code, $provider), ($model !== '' ? $model : $this->settings->model($provider, $code)), $provider['values']['api_key'] ?? '', $provider['values']['auth'] ?? (LlmCatalog::presets()[$code]['auth'] ?? 'bearer'));
    }

    /** A workspace's own OpenAI-compatible endpoint (bring your own key). */
    public function own(string $baseUrl, string $model, string $apiKey, string $auth): LlmProvider
    {
        return $this->build('own', 'openai', $baseUrl, $model, $apiKey, $auth);
    }

    private function build(string $code, string $kind, string $baseUrl, string $model, string $key, string $auth): LlmProvider
    {
        return $kind === 'anthropic'
            ? new AnthropicProvider($code, $this->transport, $baseUrl, $model, $key)
            : new OpenAiCompatibleProvider($code, $this->transport, $baseUrl, $model, $key, $auth === 'api-key' ? 'api-key' : 'bearer');
    }
}
