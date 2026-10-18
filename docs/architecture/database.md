

## Этап 17: ИИ-ассистент (миграция 41)
- `ai_providers` (`code` PK: пресет или `custom-<имя>`, `kind` `openai|anthropic`, `label`, `enabled`, `is_default`, `config_enc` — JSON `base_url`, `model`, `api_key`, `auth`, зашифрован `Crypto`, `updated_by`, `updated_at`).
- `ai_workspace_settings` (`workspace_id` PK/FK CASCADE, `brand_json` — голос бренда, `byok_enc` — своя модель пространства (адрес, модель, ключ) зашифрована, `byok_enabled`, `updated_at`).
- `ai_usage` (журнал запросов: `workspace_id`, `user_id`, `action`, `provider`, `model`, `byok`, `status`, `credits`, `tokens_in`, `tokens_out`, `duration_ms`, `created_at`). Текстов постов и ответов нет.
- Кредиты: месячный лимит тарифа `ai_credits` в `usage_counters` (общий с функциями «Источников») и докупленные в кошельке `wallet:CRD:<пространство>`; расход пишется проводкой `ai_spend` (`wallet:CRD:…` ↔ `spent:CRD`).
- `ai_channel_styles` (миграция 42: `channel_id` PK/FK CASCADE, `workspace_id`, `style_json` — правила, тон, запретные слова, примеры одного канала, `updated_at`).
