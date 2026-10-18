<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/**
 * The de-facto standard chat API (`POST {base_url}/chat/completions`) spoken by OpenAI, OpenRouter, Yandex AI Studio, Gemini's
 * compatibility endpoint, DeepSeek, Groq, Mistral, Together, Ollama, vLLM and many gateways. One class covers all of them: only the
 * base URL, the model and the way the key is sent differ (`Authorization: Bearer` or `Api-Key`).
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
    public function __construct(
        private readonly string $code,
        private readonly LlmTransport $transport,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $authScheme = 'bearer',
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
        $auth = $this->authScheme === 'api-key' ? 'Api-Key ' . $this->apiKey : 'Bearer ' . $this->apiKey;
        $data = $this->transport->post(rtrim($this->baseUrl, '/') . '/chat/completions', ['Authorization' => $auth], [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'stream' => false,
        ]);
        $choice = is_array($data['choices'] ?? null) && is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
        $content = is_array($choice['message'] ?? null) ? ($choice['message']['content'] ?? null) : null;
        if (!is_string($content) || trim($content) === '') {
            throw new LlmException(($choice['finish_reason'] ?? '') === 'content_filter' ? 'refused' : 'bad_response');
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new LlmResult(
            trim($content),
            is_string($data['model'] ?? null) ? mb_substr($data['model'], 0, 120) : $this->model,
            self::count($usage['prompt_tokens'] ?? null),
            self::count($usage['completion_tokens'] ?? null),
        );
    }

    private static function count(mixed $value): int
    {
        return is_int($value) && $value > 0 ? min($value, 10_000_000) : 0;
    }
}
