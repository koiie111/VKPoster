

## Этап 17: ИИ-ассистент (миграция 41)
- `ai_providers` (`code` PK: пресет или `custom-<имя>`, `kind` `openai|anthropic`, `label`, `enabled`, `is_default`, `config_enc` — JSON `base_url`, `model`, `api_key`, `auth`, зашифрован `Crypto`, `updated_by`, `updated_at`).
- `ai_workspace_settings` (`workspace_id` PK/FK CASCADE, `brand_json` — голос бренда, `byok_enc` — своя модель пространства (адрес, модель, ключ) зашифрована, `byok_enabled`, `updated_at`).
- `ai_usage` (журнал запросов: `workspace_id`, `user_id`, `action`, `provider`, `model`, `byok`, `status`, `credits`, `tokens_in`, `tokens_out`, `duration_ms`, `created_at`). Текстов постов и ответов нет.
- Кредиты: месячный лимит тарифа `ai_credits` в `usage_counters` (общий с функциями «Источников») и докупленные в кошельке `wallet:CRD:<пространство>`; расход пишется проводкой `ai_spend` (`wallet:CRD:…` ↔ `spent:CRD`).
- `link_workspace_settings` (миграция 43: `workspace_id` PK/FK CASCADE, `utm_json` — правило UTM, `shorten`, `updated_at`), `link_channel_rules` (`channel_id` PK/FK CASCADE, `workspace_id`, `rule_json` — своё правило или «выключено»), `short_links` (`public_id` ULID, `code` CHAR(7) base62 уникален, `workspace_id`, `variant_id` FK SET NULL, `platform`, `target_url`, `target_hash`, уникальна пара `variant_id`+`target_hash`, `clicks`, `human_clicks`, `last_click_at`, `disabled_at`/`disabled_reason`, `created_by`, `created_at`), `short_link_clicks` (`link_id` FK CASCADE, `clicked_at`, `visitor_hash` CHAR(16) — соль-хэш без IP, `is_bot`). Подробности: [modules/links.md](modules/links.md).
- `ai_channel_styles` (миграция 42: `channel_id` PK/FK CASCADE, `workspace_id`, `style_json` — правила, тон, запретные слова, примеры одного канала, `updated_at`).


## Stage 18 — Public API and outgoing webhooks (migration 44)

- `api_tokens`: workspace/creator/public ULID/name, SHA-256 token hash (unique), scope JSON, optional expiry, revoke and throttled last-use timestamps. No plaintext token.
- `api_idempotency`: workspace/token, hashed request key + canonical request fingerprint, 2xx status/body, created/expiry; UNIQUE(token_id,key_hash), 24h TTL. Transactional with domain mutations.
- `webhook_subscriptions`: workspace/creator/public ULID, safe HTTP(S) URL, Crypto-encrypted secret, event names, enabled, consecutive failures, created.
- `outgoing_webhook_events`: workspace/public ULID, unique event key, type, minimal public JSON envelope, created/dispatched timestamps. Transactional domain outbox.
- `webhook_deliveries`: workspace/subscription/event, public ULID, lifecycle pending/processing/completed/failed/skipped, attempt count/next-attempt/completed/enqueued timestamps, fenced lease, safe HTTP status/error enum. UNIQUE(subscription_id,event_id).
- `webhook_delivery_attempts`: delivery FK, timestamp, HTTP status and safe error enum; no response body.

Workspace deletion cascades API state; token deletion cascades its idempotency records. Terminal events older than configured retention (minimum 35d) cascade deliveries/attempts only when no pending/processing delivery remains. Migration rollback drops only these six tables, in dependency order; existing posts, approvals and publishing data are preserved.
