<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/** Offline stand-in for tests and local work: echoes a marker and the material. Refused in production by the factory. */
final class FakeLlmProvider implements LlmProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function complete(LlmRequest $request): LlmResult
    {
        $material = $request->user;
        foreach (['material', 'brief'] as $tag) {
            if (preg_match('/<' . $tag . '>\s*(.*?)\s*<\/' . $tag . '>/su', $request->user, $m) === 1) {
                $material = $m[1];
                break;
            }
        }

        return new LlmResult('Тестовый ответ: ' . mb_substr($material, 0, 500), 'fake-1', 10, 20);
    }
}
