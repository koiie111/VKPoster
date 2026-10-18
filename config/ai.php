<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * AI assistant. Providers and keys are data (admin area `/admin/ai`), not environment; this file holds only limits and the offline stand-in.
 * `fake`: with no real provider connected the assistant answers with the test model; never in production (the factory checks `APP_ENV`).
 */
return static fn (Env $env): array => [
    'fake' => $env->bool('AI_FAKE', true),
    'max_input_chars' => max(200, min(20000, $env->int('AI_MAX_INPUT_CHARS', 6000))),
    'max_output_tokens' => max(64, min(4096, $env->int('AI_MAX_OUTPUT_TOKENS', 1024))),
    'workspace_per_minute' => max(1, min(120, $env->int('AI_WORKSPACE_PER_MINUTE', 10))),
    'timeout_seconds' => max(5, min(120, $env->int('AI_TIMEOUT_SECONDS', 60))),
];
