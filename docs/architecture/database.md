

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

<!-- generated-schema:start -->
## Полный каталог схемы (автоматический)

Проверка: `scripts/check-schema.php`; машиночитаемые типы/индексы: [schema.json](schema.json).

- `addon_packages`: `id`, `code`, `name`, `kind`, `quantity`, `days`, `prices_json`, `active`, `sort`, `created_at`
- `admin_notes`: `id`, `user_id`, `author_id`, `body`, `created_at`
- `ai_channel_styles`: `channel_id`, `workspace_id`, `style_json`, `updated_at`
- `ai_providers`: `code`, `kind`, `label`, `enabled`, `is_default`, `config_enc`, `updated_by`, `updated_at`
- `ai_usage`: `id`, `workspace_id`, `user_id`, `action`, `provider`, `model`, `byok`, `status`, `credits`, `tokens_in`, `tokens_out`, `duration_ms`, `created_at`
- `ai_workspace_settings`: `workspace_id`, `brand_json`, `byok_enc`, `byok_enabled`, `updated_at`
- `analytics_events`: `id`, `name`, `user_id`, `workspace_id`, `visitor_id`, `once_key`, `props_json`, `occurred_at`
- `analytics_tasks`: `id`, `workspace_id`, `channel_id`, `publication_id`, `task_key`, `due_at`, `completed_at`, `enqueued_at`, `error_code`, `lease_token`, `lease_until`
- `announcement_dismissals`: `announcement_id`, `user_id`
- `announcements`: `id`, `title`, `body`, `level`, `plans_json`, `platforms_json`, `starts_at`, `ends_at`, `is_active`, `created_by`, `created_at`
- `api_idempotency`: `id`, `workspace_id`, `token_id`, `key_hash`, `request_hash`, `status`, `response_json`, `created_at`, `expires_at`
- `api_tokens`: `id`, `public_id`, `workspace_id`, `created_by`, `name`, `token_hash`, `scopes_json`, `expires_at`, `revoked_at`, `last_used_at`, `created_at`
- `app_settings`: `name`, `value_json`, `updated_by`, `updated_at`
- `approvals`: `id`, `workspace_id`, `post_id`, `revision_hash`, `decision`, `actor_id`, `guest_link_id`, `created_at`
- `audit_log`: `id`, `workspace_id`, `actor_id`, `action`, `subject_type`, `subject_id`, `ip`, `meta_json`, `created_at`
- `auth_tokens`: `id`, `user_id`, `type`, `selector`, `token_hash`, `payload_json`, `expires_at`, `used_at`, `created_at`
- `channel_connect_codes`: `id`, `public_id`, `workspace_id`, `user_id`, `platform`, `code_hash`, `channel_id`, `failure`, `expires_at`, `used_at`, `created_at`
- `channel_stats_snapshots`: `id`, `workspace_id`, `channel_id`, `task_id`, `observed_at`, `followers`
- `channels`: `id`, `public_id`, `workspace_id`, `platform`, `external_id`, `mode`, `title`, `alias`, `username`, `kind`, `avatar_key`, `status`, `credential_id`, `settings_json`, `last_health_at`, `last_error`, `created_by`, `created_at`, `updated_at`
- `cms_blocks`: `name`, `value`, `updated_by`, `updated_at`
- `cms_pages`: `id`, `kind`, `slug`, `title`, `version`, `required`, `body_md`, `status`, `created_by`, `created_at`, `published_at`
- `content_automation_calls`: `id`, `workspace_id`, `operation_key`, `operation`, `created_at`, `status`, `error_category`, `attempts`, `available_at`, `result_json`
- `content_automation_runs`: `id`, `workspace_id`, `settings_id`, `item_id`, `run_key`, `revision_hash`, `selection_hash`, `settings_version`, `settings_snapshot_json`, `status`, `step`, `last_successful_step`, `attempts`, `text_version`, `video_version`, `draft_id`, `queued_at`, `available_at`, `error`, `created_at`, `updated_at`, `finished_at`
- `content_automation_settings`: `id`, `workspace_id`, `scope_key`, `source_id`, `version`, `settings_json`, `next_discovery_at`, `scan_cursor`, `updated_by`, `created_at`, `updated_at`
- `content_post_origins`: `id`, `workspace_id`, `item_id`, `post_id`, `text_processing_id`, `revision_hash`, `selection_hash`, `idempotency_key`, `snapshot_json`, `created_by`, `created_at`
- `content_storage_objects`: `storage_key`, `created_at`, `deleted_at`
- `crosspost_buffer`: `id`, `chat_id`, `message_id`, `group_key`, `payload_json`, `taken_at`, `created_at`
- `crosspost_items`: `id`, `rule_id`, `message_id`, `post_id`, `created_at`
- `crosspost_rules`: `id`, `public_id`, `workspace_id`, `source_channel_id`, `name`, `enabled`, `filters_json`, `transforms_json`, `delay_minutes`, `mode`, `sync_edits`, `last_run_at`, `last_note`, `posts_created`, `created_by`, `created_at`, `updated_at`
- `crosspost_targets`: `rule_id`, `channel_id`
- `data_requests`: `id`, `public_id`, `user_id`, `email`, `type`, `status`, `note`, `created_by`, `created_at`, `completed_by`, `completed_at`
- `discovery_clusters`: `id`, `public_id`, `workspace_id`, `title`, `summary`, `tokens_json`, `story_at`, `discovered_at`, `trend_score`, `score_json`, `status`, `created_at`, `updated_at`
- `discovery_imports`: `workspace_id`, `discovery_item_id`, `material_id`, `imported_by`, `created_at`
- `discovery_item_keys`: `workspace_id`, `key_type`, `key_hash`, `item_id`
- `discovery_items`: `id`, `public_id`, `workspace_id`, `cluster_id`, `provider`, `source_type`, `source_key`, `source_name`, `canonical_url`, `external_id`, `title`, `excerpt`, `normalized_title`, `fingerprint`, `published_at`, `discovered_at`, `metadata_json`, `engagement_json`, `providers_json`, `language`, `trend_score`, `status`, `created_at`, `updated_at`
- `discovery_runs`: `id`, `public_id`, `workspace_id`, `provider`, `status`, `found_count`, `created_count`, `error`, `created_at`, `finished_at`
- `email_campaign_recipients`: `id`, `campaign_id`, `user_id`, `email`, `status`, `error`, `sent_at`, `unsubscribed_at`
- `email_campaigns`: `id`, `public_id`, `name`, `subject`, `body_md`, `segment_json`, `status`, `total`, `created_by`, `created_at`, `started_at`, `finished_at`
- `evergreen_pools`: `id`, `public_id`, `workspace_id`, `queue_id`, `name`, `min_interval_days`, `keep_ahead`, `enabled`, `created_at`, `updated_at`
- `evergreen_posts`: `id`, `pool_id`, `post_id`, `last_used_at`, `uses`, `created_at`
- `failed_jobs`: `id`, `queue`, `payload_json`, `attempts`, `error`, `failed_at`
- `guest_link_posts`: `workspace_id`, `link_id`, `post_id`
- `guest_links`: `id`, `public_id`, `workspace_id`, `token_hash`, `pin_hash`, `expires_at`, `revoked_at`, `created_by`, `created_at`, `scope_json`
- `import_batches`: `id`, `public_id`, `workspace_id`, `user_id`, `filename`, `mode`, `status`, `rows_json`, `total`, `ok_count`, `error_count`, `created_count`, `failed_json`, `created_at`, `updated_at`
- `invitations`: `id`, `public_id`, `workspace_id`, `email`, `role`, `token_hash`, `invited_by`, `expires_at`, `accepted_at`, `revoked_at`, `created_at`
- `invite_codes`: `id`, `code_hash`, `hint`, `note`, `max_uses`, `uses`, `expires_at`, `revoked_at`, `created_by`, `created_at`
- `invoices`: `id`, `public_id`, `number`, `workspace_id`, `subscription_id`, `plan_id`, `period`, `kind`, `amount`, `list_price`, `discount`, `promo_code_id`, `addon_package_id`, `currency`, `status`, `description`, `customer_email`, `period_start`, `period_end`, `created_at`, `paid_at`, `expires_at`
- `jobs`: `id`, `queue`, `payload_json`, `available_at`, `reserved_at`, `reserved_by`, `attempts`, `max_attempts`, `last_error`, `created_at`
- `ledger_accounts`: `id`, `code`, `name`, `kind`, `currency`, `created_at`
- `ledger_entries`: `id`, `txn_id`, `account_id`, `amount`, `currency`, `ref_type`, `ref_id`, `memo`, `created_at`
- `link_channel_rules`: `channel_id`, `workspace_id`, `rule_json`, `updated_at`
- `link_workspace_settings`: `workspace_id`, `utm_json`, `shorten`, `updated_at`
- `login_attempts`: `id`, `user_id`, `outcome`, `ip`, `user_agent`, `created_at`
- `mail_templates`: `template`, `subject`, `body_md`, `updated_by`, `updated_at`
- `media`: `id`, `public_id`, `workspace_id`, `uploader_id`, `folder_id`, `kind`, `original_name`, `storage_key`, `thumb_key`, `mime`, `size`, `width`, `height`, `duration_ms`, `codec`, `animated`, `sha256`, `variants_json`, `created_at`
- `media_folders`: `id`, `public_id`, `workspace_id`, `name`, `created_at`
- `member_channel_access`: `workspace_id`, `user_id`, `channel_id`, `granted_at`
- `metrics_daily`: `day`, `metric`, `dim`, `value`
- `migrations`: `id`, `migration`, `batch`
- `notification_settings`: `user_id`, `type`, `email`, `telegram`
- `notifications`: `id`, `public_id`, `user_id`, `workspace_id`, `type`, `title`, `body`, `url`, `sent_email`, `sent_telegram`, `read_at`, `created_at`
- `outgoing_webhook_events`: `id`, `public_id`, `workspace_id`, `event_key`, `type`, `payload_json`, `created_at`, `dispatched_at`
- `payment_methods`: `id`, `public_id`, `workspace_id`, `provider`, `provider_method_id`, `customer_key`, `title`, `status`, `created_at`
- `payment_providers`: `code`, `enabled`, `config_enc`, `updated_by`, `updated_at`
- `payments`: `id`, `public_id`, `invoice_id`, `workspace_id`, `provider`, `provider_payment_id`, `status`, `provider_status`, `provider_ref`, `amount`, `currency`, `refunded_amount`, `payment_method_id`, `save_method`, `confirmation_url`, `error_message`, `created_at`, `updated_at`
- `plan_prices`: `id`, `plan_id`, `period`, `currency`, `amount`
- `plans`: `id`, `code`, `name`, `sort`, `limits_json`, `features_json`, `is_public`, `created_at`, `updated_at`
- `platform_credentials`: `id`, `public_id`, `workspace_id`, `platform`, `kind`, `secret_enc`, `refresh_enc`, `expires_at`, `scopes`, `device_id`, `account_id`, `hint`, `created_at`, `updated_at`
- `post_comments`: `id`, `public_id`, `workspace_id`, `post_id`, `parent_id`, `actor_id`, `guest_link_id`, `body`, `created_at`
- `post_stats_snapshots`: `id`, `workspace_id`, `channel_id`, `publication_id`, `task_id`, `observed_at`, `metrics_json`
- `post_templates`: `id`, `public_id`, `workspace_id`, `name`, `payload_json`, `created_by`, `created_at`
- `post_variants`: `id`, `post_id`, `workspace_id`, `channel_id`, `platform`, `channel_name`, `text`, `media_ids_json`, `options_json`
- `post_versions`: `id`, `workspace_id`, `post_id`, `revision_hash`, `snapshot_json`, `actor_id`, `created_at`
- `posts`: `id`, `public_id`, `workspace_id`, `author_id`, `status`, `base_text`, `media_ids_json`, `options_json`, `per_network`, `scheduled_at`, `timezone`, `published_at`, `created_at`, `updated_at`
- `promo_codes`: `id`, `code`, `kind`, `value`, `currency`, `plan_codes_json`, `first_payment_only`, `max_redemptions`, `valid_from`, `valid_until`, `active`, `note`, `created_by`, `created_at`
- `promo_redemptions`: `id`, `promo_id`, `workspace_id`, `user_id`, `invoice_id`, `discount`, `created_at`
- `publication_attempts`: `id`, `publication_id`, `workspace_id`, `attempt`, `outcome`, `error_kind`, `message`, `detail`, `started_at`, `finished_at`
- `publications`: `id`, `public_id`, `workspace_id`, `post_id`, `variant_id`, `channel_id`, `status`, `attempt`, `due_at`, `run_at`, `enqueued_at`, `idempotency_key`, `external_post_id`, `external_ids_json`, `external_url`, `error_code`, `error_message`, `error_detail`, `started_at`, `sent_at`, `delete_at`, `delete_enqueued_at`, `deleted_at`, `delete_error`, `pinned`, `created_at`, `updated_at`
- `queue_channels`: `queue_id`, `channel_id`
- `queue_entries`: `id`, `queue_id`, `post_id`, `position`, `source`, `note`, `created_at`
- `queue_slots`: `id`, `queue_id`, `weekday`, `at_time`
- `queues`: `id`, `public_id`, `workspace_id`, `name`, `paused`, `created_by`, `created_at`, `updated_at`
- `recovery_codes`: `id`, `user_id`, `code_hash`, `used_at`, `created_at`
- `recurring_rules`: `id`, `public_id`, `workspace_id`, `post_id`, `frequency`, `interval_days`, `weekdays`, `month_day`, `at_time`, `timezone`, `starts_on`, `ends_on`, `max_count`, `done_count`, `next_run_at`, `enabled`, `last_error`, `created_by`, `created_at`, `updated_at`
- `recurring_runs`: `id`, `rule_id`, `occurrence_at`, `post_id`, `created_at`
- `referral_bonuses`: `id`, `referral_id`, `payment_id`, `amount`, `reversed`, `created_at`
- `referral_codes`: `user_id`, `code`, `created_at`
- `referral_payouts`: `id`, `public_id`, `user_id`, `amount`, `details`, `status`, `staff_note`, `handled_by`, `created_at`, `handled_at`
- `referrals`: `id`, `referrer_id`, `referred_id`, `status`, `reason`, `ip_hash`, `created_at`
- `rss_feed_channels`: `feed_id`, `channel_id`
- `rss_feeds`: `id`, `public_id`, `workspace_id`, `name`, `url`, `template`, `mode`, `queue_id`, `interval_minutes`, `enabled`, `etag`, `last_modified`, `last_checked_at`, `next_check_at`, `last_error`, `error_count`, `created_by`, `created_at`, `updated_at`
- `rss_items`: `id`, `feed_id`, `guid_hash`, `post_id`, `created_at`
- `semantic_selection_evaluations`: `id`, `workspace_id`, `scope_key`, `origin_type`, `origin_id`, `revision_hash`, `deterministic_hash`, `settings_version`, `settings_snapshot_json`, `provider`, `status`, `deterministic_status`, `deterministic_reason`, `deterministic_rule`, `decision`, `score`, `confidence`, `reason`, `matched_criteria_json`, `review_flags_json`, `final_decision`, `final_reason`, `error`, `created_at`, `finished_at`, `provider_metadata_json`, `attempt_count`, `input_json`, `started_at`, `error_category`
- `semantic_selection_settings`: `id`, `workspace_id`, `scope_key`, `version`, `settings_json`, `updated_by`, `created_at`, `updated_at`
- `short_link_clicks`: `id`, `link_id`, `clicked_at`, `visitor_hash`, `is_bot`
- `short_links`: `id`, `public_id`, `code`, `workspace_id`, `variant_id`, `platform`, `target_url`, `target_hash`, `clicks`, `human_clicks`, `last_click_at`, `disabled_at`, `disabled_reason`, `created_by`, `created_at`
- `source_events`: `id`, `workspace_id`, `source_id`, `event_id`, `event_type`, `payload_hash`, `payload_json`, `status`, `created_at`
- `source_image_processings`: `id`, `public_id`, `workspace_id`, `source_id`, `item_id`, `message_id`, `telegram_message_id`, `peer_id`, `telegram_photo_id`, `revision_hash`, `selection_hash`, `status`, `error`, `selected_variant`, `created_by`, `created_at`, `updated_at`, `finished_at`
- `source_image_variants`: `id`, `public_id`, `workspace_id`, `processing_id`, `kind`, `storage_key`, `preview_key`, `sha256`, `width`, `height`, `mime`, `bytes`, `quality`, `metrics_json`, `source_url`, `verification`, `confidence`, `verification_json`, `provider`, `created_at`, `provider_metadata_json`
- `source_items`: `id`, `public_id`, `workspace_id`, `source_id`, `peer_id`, `item_key`, `grouped_id`, `text`, `content_type`, `status`, `published_at`, `edited_at`, `created_at`, `updated_at`, `connection_version`
- `source_messages`: `id`, `workspace_id`, `source_id`, `item_id`, `peer_id`, `message_id`, `grouped_id`, `text`, `entities_json`, `media_json`, `metadata_json`, `published_at`, `edited_at`, `revision_hash`, `created_at`, `updated_at`, `connection_version`
- `source_selection_decisions`: `id`, `workspace_id`, `source_id`, `item_id`, `selection_status`, `decision_mode`, `reason`, `matched_rule`, `rules_version`, `rules_snapshot_json`, `decided_by`, `created_at`, `updated_at`, `revision_hash`
- `source_selection_rules`: `id`, `workspace_id`, `source_id`, `version`, `rules_json`, `updated_by`, `created_at`, `updated_at`
- `source_text_processings`: `id`, `public_id`, `workspace_id`, `source_id`, `item_id`, `revision_hash`, `selection_hash`, `original_text`, `processed_text`, `mode`, `settings_version`, `settings_json`, `status`, `error`, `provider`, `created_by`, `created_at`, `updated_at`, `finished_at`, `provider_metadata_json`, `error_category`, `retryable`
- `source_video_generations`: `id`, `public_id`, `workspace_id`, `source_id`, `item_id`, `revision_hash`, `selection_hash`, `settings_version`, `settings_json`, `basis_json`, `status`, `provider`, `result_json`, `error`, `selected`, `created_by`, `created_at`, `updated_at`, `started_at`, `finished_at`, `provider_metadata_json`, `provider_job_id`, `poll_claimed_at`, `lease_generation`, `error_category`
- `sources`: `id`, `public_id`, `workspace_id`, `name`, `type`, `telegram_username`, `status`, `enabled`, `created_by`, `created_at`, `updated_at`, `connection_version`
- `staff_members`: `user_id`, `role`, `created_by`, `created_at`
- `subscriptions`: `id`, `public_id`, `workspace_id`, `plan_id`, `status`, `period`, `currency`, `price_amount`, `current_period_start`, `current_period_end`, `trial_ends_at`, `trial_reminded_at`, `cancel_at_period_end`, `pending_plan_id`, `pending_period`, `payment_method_id`, `renewal_attempts`, `first_attempt_at`, `next_renewal_attempt_at`, `last_failure`, `created_at`, `updated_at`
- `support_messages`: `id`, `ticket_id`, `kind`, `author_id`, `body`, `delivered_via`, `created_at`
- `support_tickets`: `id`, `public_id`, `user_id`, `email`, `subject`, `status`, `source`, `assignee_id`, `context_json`, `telegram_chat_id`, `created_at`, `updated_at`
- `telegram_link_tokens`: `id`, `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`
- `telegram_links`: `user_id`, `chat_id`, `username`, `linked_at`
- `telegram_stats_reactions`: `workspace_id`, `channel_id`, `message_id`, `event_date`, `reactions`
- `triggers`: `id`, `public_id`, `workspace_id`, `post_id`, `channel_id`, `event_post_id`, `event`, `delay_minutes`, `fire_at`, `action`, `params_json`, `status`, `fired_at`, `result`, `created_by`, `created_at`
- `usage_counters`: `workspace_id`, `metric`, `period_key`, `value`, `updated_at`
- `user_activity_days`: `user_id`, `day`
- `user_attribution`: `user_id`, `utm_source`, `utm_medium`, `utm_campaign`, `referrer`, `landing`, `visitor_id`, `first_seen_at`
- `user_consents`: `id`, `user_id`, `version`, `ip`, `accepted_at`
- `user_identities`: `id`, `user_id`, `provider`, `provider_user_id`, `email`, `display_name`, `linked_at`, `last_login_at`
- `user_sessions`: `id`, `public_id`, `user_id`, `session_id_hash`, `ip`, `user_agent`, `created_at`, `last_seen_at`, `revoked_at`
- `users`: `id`, `email`, `email_verified_at`, `password_hash`, `password_changed_at`, `name`, `locale`, `timezone`, `totp_secret_enc`, `totp_enabled_at`, `totp_last_step`, `is_superadmin`, `status`, `block_reason`, `consent_version`, `consent_at`, `marketing_opt_in_at`, `marketing_unsubscribed_at`, `created_at`, `updated_at`
- `watermarks`: `id`, `public_id`, `workspace_id`, `name`, `storage_key`, `width`, `height`, `position`, `opacity`, `scale`, `margin`, `is_default`, `created_at`, `updated_at`
- `webauthn_challenges`: `id`, `user_id`, `session_hash`, `challenge_hash`, `purpose`, `expires_at`, `consumed_at`
- `webauthn_credentials`: `id`, `user_id`, `credential_id`, `credential_hash`, `public_key`, `sign_count`, `label`, `created_at`, `last_used_at`, `revoked_at`
- `webhook_deliveries`: `id`, `public_id`, `workspace_id`, `subscription_id`, `event_id`, `status`, `attempts`, `next_attempt_at`, `completed_at`, `enqueued_at`, `lease_token`, `lease_until`, `response_status`, `error_code`
- `webhook_delivery_attempts`: `id`, `delivery_id`, `attempted_at`, `response_status`, `error_code`
- `webhook_events`: `id`, `provider`, `event_id`, `type`, `payment_ref`, `outcome`, `detail`, `payload`, `received_at`
- `webhook_subscriptions`: `id`, `public_id`, `workspace_id`, `created_by`, `url`, `secret_enc`, `events_json`, `enabled`, `consecutive_failures`, `created_at`
- `workspace_addons`: `id`, `workspace_id`, `package_id`, `kind`, `quantity`, `invoice_id`, `valid_until`, `created_at`
- `workspace_approval_settings`: `workspace_id`, `required`, `approvers_json`, `brand_name`, `brand_color`, `brand_logo`, `updated_at`
- `workspace_members`: `workspace_id`, `user_id`, `public_id`, `role`, `channels_restricted`, `invited_by`, `joined_at`
- `workspaces`: `id`, `public_id`, `owner_id`, `name`, `timezone`, `locale`, `is_personal`, `plan_id`, `created_at`, `updated_at`
<!-- generated-schema:end -->
