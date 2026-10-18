<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/**
 * A language model behind one method. Implementations bound request time and output, never log the prompt, the answer or the key,
 * and throw `LlmException` with a stable reason code on any failure. The answer is text for a human to read, never code to run.
 */
interface LlmProvider
{
    /** Provider code as the owner knows it (`openai`, `yandex`, `custom-…`) or `fake`. */
    public function name(): string;

    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResult;
}
