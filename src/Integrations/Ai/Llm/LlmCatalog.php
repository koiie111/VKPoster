<?php

declare(strict_types=1);

namespace App\Integrations\Ai\Llm;

/**
 * The language-model providers that work out of the box: each is a preset (kind, address, an example model, where data goes). The
 * owner only enters a key (and a model if the default does not suit). Any other OpenAI-compatible endpoint is added as a custom
 * provider (`custom-<slug>`) with its own address. The list is code; which are on, and their keys, are data (`LlmSettings`).
 *
 * `kind`: `openai` = OpenAI-compatible chat API, `anthropic` = Claude Messages API. `auth`: how the key is sent.
 * `region`: `ru` keeps data in Russia, `world` leaves it (said to the owner in the admin area and in the privacy policy).
 */
final class LlmCatalog
{
    public const CUSTOM_PREFIX = 'custom-';

    /**
     * @return array<string, array{label: string, kind: string, base_url: string, model: string, auth: string, region: string, note: string}>
     */
    public static function presets(): array
    {
        return [
            'openai' => ['label' => 'OpenAI', 'kind' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Ключ: platform.openai.com → API keys. Из России доступ может потребовать шлюза: тогда используйте OpenRouter или свой адрес.'],
            'anthropic' => ['label' => 'Anthropic (Claude)', 'kind' => 'anthropic', 'base_url' => 'https://api.anthropic.com', 'model' => 'claude-haiku-4-5', 'auth' => 'x-api-key', 'region' => 'world', 'note' => 'Ключ: console.anthropic.com → API keys. Родной API Claude.'],
            'gemini' => ['label' => 'Google Gemini', 'kind' => 'openai', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.0-flash', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Ключ: aistudio.google.com. Совместимый с OpenAI адрес Google.'],
            'openrouter' => ['label' => 'OpenRouter', 'kind' => 'openai', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'openai/gpt-4o-mini', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Один ключ ко многим моделям (OpenAI, Claude, Llama, Qwen…). Модель пишется как «поставщик/модель».'],
            'yandex' => ['label' => 'YandexGPT (Yandex AI Studio)', 'kind' => 'openai', 'base_url' => 'https://llm.api.cloud.yandex.net/v1', 'model' => 'gpt://ИДЕНТИФИКАТОР_КАТАЛОГА/yandexgpt/latest', 'auth' => 'api-key', 'region' => 'ru', 'note' => 'Данные остаются в России. Ключ: API-ключ сервисного аккаунта; в названии модели подставьте идентификатор каталога Yandex Cloud.'],
            'deepseek' => ['label' => 'DeepSeek', 'kind' => 'openai', 'base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Ключ: platform.deepseek.com.'],
            'groq' => ['label' => 'Groq', 'kind' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Быстрые открытые модели. Ключ: console.groq.com.'],
            'mistral' => ['label' => 'Mistral', 'kind' => 'openai', 'base_url' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Ключ: console.mistral.ai.'],
            'tokenator' => ['label' => 'Tokenator', 'kind' => 'openai', 'base_url' => 'https://api.tokenator.cloud/v1', 'model' => 'free-glm-5.3-flash', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Шлюз к моделям разных разработчиков по одному ключу (sk-…, личный кабинет tokenator.cloud). Модели free-… не тратят токены пакета, но имеют суточный лимит запросов: задайте их первыми шагами цепочки ниже, платную модель последней.'],
            'together' => ['label' => 'Together AI', 'kind' => 'openai', 'base_url' => 'https://api.together.xyz/v1', 'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'auth' => 'bearer', 'region' => 'world', 'note' => 'Открытые модели. Ключ: api.together.ai.'],
        ];
    }

    /** What the form of a custom provider offers for the key header. */
    public const AUTH_SCHEMES = ['bearer' => 'Authorization: Bearer', 'api-key' => 'Authorization: Api-Key'];

    public static function isCustom(string $code): bool
    {
        return preg_match('/^custom-[a-z0-9][a-z0-9-]{0,24}$/D', $code) === 1;
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::presets()[$code]) || self::isCustom($code);
    }
}
