<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/** Claude through the native Messages API (`POST {base_url}/v1/messages`); the base URL is configurable for gateways. */
final class AnthropicProvider implements LlmProvider
{
    public const DEFAULT_URL = 'https://api.anthropic.com';

    public function __construct(
        private readonly string $code,
        private readonly LlmTransport $transport,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey,
    ) {
    }

    public function name(): string
    {
        return $this->code;
    }

    public function complete(LlmRequest $request): LlmResult
    {
        if ($this->apiKey === '' || $this->model === '' || preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw new LlmException('not_configured');
        }
        $data = $this->transport->post(rtrim($this->baseUrl, '/') . '/v1/messages', ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'], [
            'model' => $this->model,
            'system' => $request->system,
            'messages' => [['role' => 'user', 'content' => $request->user]],
            'max_tokens' => $request->maxTokens,
            'temperature' => min(1.0, $request->temperature),
        ]);
        $text = '';
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if (trim($text) === '') {
            throw new LlmException(($data['stop_reason'] ?? '') === 'refusal' ? 'refused' : 'bad_response');
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new LlmResult(
            trim($text),
            is_string($data['model'] ?? null) ? mb_substr($data['model'], 0, 120) : $this->model,
            is_int($usage['input_tokens'] ?? null) ? max(0, $usage['input_tokens']) : 0,
            is_int($usage['output_tokens'] ?? null) ? max(0, $usage['output_tokens']) : 0,
        );
    }
}
