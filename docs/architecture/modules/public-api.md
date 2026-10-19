# Public REST API and outgoing webhooks — Stage 18

## Boundaries

`Application` selects a stateless `SecurityHeaders → PublicApi` pipeline for `/api/v1` before session/auth/consent middleware. Existing browser settings remain session-authenticated, permission-scoped and CSRF-protected. `PublicApi` authenticates a hashed `ApiTokens` secret, rebuilds the workspace context from live membership, checks billing and fail-closed Redis rate limit, then dispatches thin `ResourcesController` actions. Errors are safe JSON without exceptions/SQL/credentials. No cookies are read as credentials or emitted.

`ApiResources` maps public ULIDs and scopes to existing `PostService`, `MediaService`/`MediaFetcher`, `PostAnalytics`, channel/publication repositories. Mixed allowed/disallowed channel variants are hidden as a whole. Mutations retain approval, quota, channel access and publishing constraints. There is no second publisher: scheduling remains PostService → PublicationScheduler → Queue → Publisher.

`Idempotency` hashes canonical method/path/query/body/file digests and serializes mutation + stored response in one transaction under `(token_id,key_hash)`. Concurrent first requests use INSERT IGNORE + FOR UPDATE, not delete/gap locking. A 2xx result is replayed for 24h, a different request conflicts; failed operations roll back. Replay rechecks current membership, scope, role and post visibility. Storage cleanup of an uploaded file after a DB rollback follows the existing media orphan-retention policy; remote file downloads are not exactly-once side effects.

`Cursor` encrypts/authenticates collection/token/public-ID position using the application's Crypto; pages expose no numeric IDs. Token scopes only narrow live member privileges. Workspace management: owner/admin and the existing `api` Pro+ entitlement. Webhook management additionally requires unrestricted channel access because events span the workspace.

## Atomic outgoing events and delivery

`PublicationSystem` captures sent/terminal failed state transitions with the state CAS; `ChannelSystem` captures broken-channel transitions; `AuditLog` captures approval transitions. `WebhookOutbox` persists minimal public envelopes transactionally with the relevant write. No HTTP occurs in those transactions. Events are captured only while an enabled subscriber exists. Existing Queue gets `DeliverWebhookJob` from `WebhookDispatcher::tick` each minute.

Fanout has a unique `(subscription_id,event_id)` fence. Delivery uses a random 2-minute lease plus conditional result update. A stale worker cannot complete a newer lease. A completed job retried after queue ACK loss sends no HTTP. The scheduler recreates lost queue work; a crash before recording an external ACK can still repeat delivery, so the receiver must durably deduplicate the stable event ID. Ordering across events is not promised.

Each HTTP attempt revalidates membership/entitlement/subscription, validates DNS with `SsrfGuard`, and requests `user_url=true` so `GuzzleHttpClient` pins the actual connection. Redirects are disabled, including the custom client's redirect loop. Request timeout 10s/connect 3s; response is streamed and closed without retaining third-party text. Five attempts use 60/300/900/3600s backoff. Per-subscription failure counter auto-disables at configured threshold; manual retry reuses the same event ID. Only safe status/error category and attempt time persist.

API token plaintext is returned once and never persisted; webhook secret is encrypted with Crypto and disclosed once. Scheduler pruning removes expired request fences and terminal webhook events after at least 35 days, with cascade to delivery attempts. Pending/recoverable events survive retention. Staff workspace inspection shows only token/subscription/delivery counts; audit/analytics track management actions without secrets. `metrics_daily.api_created` groups token/webhook creation events.

## Contract and verification

JSON is valid YAML 1.2, so `public/api/openapi.yaml` uses JSON syntax for deterministic PHP parsing without adding a runtime YAML dependency. OpenAPI 3.1 is validated by a pinned developer/CI Swagger Parser tool, while PHP tests compare actual Router operations bidirectionally with documented paths, scopes, request bodies and headers. `/api/docs` uses the pinned, checksummed, self-hosted Redoc bundle (ADR 0018).

Tests: `tests/Feature/Api`, `tests/Integration/Api`, `tests/Unit/Api`; includes separate-process concurrent idempotency, tenant/scope/role changes, approval/billing, secret handling, DNS rebinding, HMAC, retries, independent subscriptions, lease/ACK recovery, retention and migration rollback/replay. HTTP mocks are used for webhooks; tests never contact real recipients.

Usage/integration instructions: `resources/help/public-api.md`.
