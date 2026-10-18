

## Этап 17: ИИ-ассистент (миграция 41)
- `ai_providers` (`code` PK: пресет или `custom-<имя>`, `kind` `openai|anthropic`, `label`, `enabled`, `is_default`, `config_enc` — JSON `base_url`, `model`, `api_key`, `auth`, зашифрован `Crypto`, `updated_by`, `updated_at`).
- `ai_workspace_settings` (`workspace_id` PK/FK CASCADE, `brand_json` — голос бренда, `byok_enc` — своя модель пространства (адрес, модель, ключ) зашифрована, `byok_enabled`, `updated_at`).
- `ai_usage` (журнал запросов: `workspace_id`, `user_id`, `action`, `provider`, `model`, `byok`, `status`, `credits`, `tokens_in`, `tokens_out`, `duration_ms`, `created_at`). Текстов постов и ответов нет.
- Кредиты: месячный лимит тарифа `ai_credits` в `usage_counters` (общий с функциями «Источников») и докупленные в кошельке `wallet:CRD:<пространство>`; расход пишется проводкой `ai_spend` (`wallet:CRD:…` ↔ `spent:CRD`).
- `link_workspace_settings` (миграция 43: `workspace_id` PK/FK CASCADE, `utm_json` — правило UTM, `shorten`, `updated_at`), `link_channel_rules` (`channel_id` PK/FK CASCADE, `workspace_id`, `rule_json` — своё правило или «выключено»), `short_links` (`public_id` ULID, `code` CHAR(7) base62 уникален, `workspace_id`, `variant_id` FK SET NULL, `platform`, `target_url`, `target_hash`, уникальна пара `variant_id`+`target_hash`, `clicks`, `human_clicks`, `last_click_at`, `disabled_at`/`disabled_reason`, `created_by`, `created_at`), `short_link_clicks` (`link_id` FK CASCADE, `clicked_at`, `visitor_hash` CHAR(16) — соль-хэш без IP, `is_bot`). Подробности: [modules/links.md](modules/links.md).
- `ai_channel_styles` (миграция 42: `channel_id` PK/FK CASCADE, `workspace_id`, `style_json` — правила, тон, запретные слова, примеры одного канала, `updated_at`).
