# ADR 0025 — non-exporting, purpose-isolated Transit crypto

Date: 2026-10-21. Implementation verified locally; production custody/HA/recovery is19.8.

## Backend and licensing

Use the existing `Crypto` façade with `IsolatedCrypto`, implemented by `VaultTransit`
through the existing HTTP boundary. Select self-hosted OpenBao's stable Transit API;
HashiCorp Vault-compatible endpoints remain possible without changing consumers.
OpenBao2.7.1 is MPL2.0; HashiCorp's current distribution uses BUSL1.1 with additional
use conditions. OpenBao avoids a new SaaS/account requirement. This licensing choice
does not replace an operator's legal/license-notice obligations for redistribution.
Upstream maintenance/release activity and exact source/license were checked.

Production requires CRYPTO_BACKEND=vault, HTTPS, a private0400/0600 token capability
and a trust anchor. Startup refuses APP_KEY/APP_KEYS/local pepper and the Sodium
backend. Readiness verifies all configured purpose-key policies. Local/testing can
explicitly use the existing Sodium provider; no network failure causes local fallback.
A dedicated real TLS/Raft OpenBao fixture proves actual encrypt/decrypt/HMAC/verify/
rewrap, key versions, retirement/recovery and ACLs; mocked contracts are additional.
No root token or unseal share is sent to PHP or Chromium. Fixture capabilities are
synthetic, short-lived and kept outside Git; the fixture never loads owner credentials.

## Actual crypto consumer inventory

| Consumer/data | Purpose/key suffix | Boundary/migration |
|---|---|---|
| Platform account/bot credentials, refresh tokens | app | CredentialVault/ChannelCredentials/OAuthRefresher, encrypted application secret data |
| TOTP seeds, payment/AI provider configs, BYOK | app | Existing Crypto callers, no new raw master key |
| Referral payout details | app | Previously omitted rotation column now included |
| Queued/dead-letter mail template data | app | Exact SendMailJob payloads in jobs/failed_jobs are CAS-rotated; other jobs unchanged |
| Public API pagination cursor | app | Short-lived ciphertext; expire/drain old cursors before retiring legacy keys |
| webhook_subscriptions.secret_enc | webhook | Independent encryption key and explicit purpose on write/read/rotation |
| Signed media/guest/unsubscribe capabilities | links | Versioned remote HMAC/verify via existing Signer; renderer cannot sign these |
| PDF request authentication | pdf | PHP remote HMAC, Chromium server verify-only token; no shared HMAC key or APP_KEY init container in production |
| Password verifier pepper | password | Remote HMAC before Argon2id; version stored in hash, old key versions verify after rotation |
| Daily click pseudonymization | clicks | Remote HMAC; no master-derived salt in PHP |
| Egress config fingerprint | no authority key | Independent telemetry salt; does not encrypt/sign/authorize anything; rotation only invalidates observations |
| Backup encryption | dedicated offline ops role | External backup key is mounted only in backup service, never the web/worker process; independent recovery custody |
| MTProto reader session/API credentials | separate reader boundary | Not APP_KEY-encrypted data; dedicated persistent session volume/private files, no browser/Core DB exposure; encrypted host volume/custody remains19.8 |
| OAuth/provider/internal bearer credentials | leaf credentials | Config/private capability files or encrypted DB values; may be used as protocol credentials, never confused with master encryption/signing material |

Keys are independently generated non-exportable, non-plaintext-backup, non-convergent,
derived AES256-GCM Transit keys named `<prefix>-app/-webhook/-links/-pdf/-password/-clicks`.
Context binds every operation to its purpose. New format is `v3:<purpose>:vault:vN:...`;
a ciphertext cannot be relabeled for another consumer. No export/datakey API is used.
The app token can read policy metadata and perform only the six required operations;
it cannot create/rotate/configure/export keys. The renderer token can only verify PDF
HMAC, not sign, decrypt or read other purposes. Native HMAC tokens retain key version;
both documented base64 and hex encodings are bounded/validated. Long-running workers
restart after operator rotation to refresh metadata; old signatures/pepper versions
remain verifiable according to the retained key policy.

## Legacy migration, interruption and recovery

Normal production PHP holds no raw current/old master or pepper key. For original
`v1:id:secretbox` data, use a separate offline, non-production CLI maintenance container
against the stopped-write database and an explicitly mounted private JSON old keyring:
`crypto:rotate --legacy-keyring-file=/run/legacy/keys.json`. No key values in argv/env.
The option is refused in production and HTTP. The migrator decrypts legacy values only
inside that one-time trusted maintenance process and immediately encrypts through
Transit with the destination purpose. Unmount/destroy that process afterwards; retain
the old keyring in offline recovery custody for old backups, never in normal app config.
Earlier experimental v2 Transit data can be read through explicit VAULT_LEGACY_TRANSIT_KEY
with its original context; grant decrypt-only on that key, never implicit key fallback.

Rows are processed in bounded batches. Each UPDATE compares the original ciphertext/
payload; a concurrent update is not overwritten. A failure leaves the current row
unchanged, completed rows readable and reruns resumable. Dry-run does not modify rows.
All stored secret columns and queued/dead mail payloads are included; no old key is
retired until a dry-run/audit finds no remaining dependency and backups have recovery
keys. Transit v3 rotation uses rewrap without returning plaintext to the app.
Successful login upgrades original unpeppered password hashes. Local-only experimental
pepper:v1 hashes require the old local verifier in controlled migration/reset; they
must be inventoried before cutover, not silently treated as wrong passwords. No such
production rollout occurred during Stage19A. New production hashes use remote v2 pepper.

A return to pre-Transit code cannot read v3 data. Do not declare that cross-format
release backward-compatible or blindly image-roll it back. Keep maintenance mode,
verified DB snapshot, prior code and old keys; recover them together on an isolated
stand, or roll forward with the new façade and retained server key versions. The
local test retires/re-enables an old key version only on synthetic data; real key
retirement must consider historical backup restore and audit retention.

## Production operations still required in19.8

Provision a patched/scanned isolated backend, private TLS endpoint, app/renderer
least-privilege tokens, audit device without raw request/response values, token renewal/
rotation and monitored readiness. Use integrated Raft/HA and an active-node endpoint;
HTTP redirects are deliberately not followed with bearer credentials. No automatic
retry of a cryptographic call hides failure. Credential lookup failure before a social
publish dispatch is explicitly Temporary and uses the existing retry/backoff.

Back up Raft snapshots plus the separate unseal/auto-unseal recovery material; exercise
isolated backend restore and verify app ciphertext, PDF and pepper after restore. Loss
of that recovery material is data loss: do not rely only on the SQL backup. Confirm
latency/capacity, outage behavior, alert delivery, real key custody and old-key retirement
on the actual VDS. A transient fixture is not evidence of production HA or an HSM.

## Primary sources

- https://openbao.org/docs/secrets/transit/
- https://openbao.org/api-docs/secret/transit/
- https://openbao.org/docs/internals/high-availability/
- https://github.com/openbao/openbao/blob/v2.7.1/LICENSE
- https://www.hashicorp.com/en/blog/hashicorp-updates-licensing-faq-based-on-community-questions


### Existing signed capabilities at first Transit cutover

New signatures use `t1_` plus base64url of the native versioned Transit MAC.
The version remains inside the authenticated token; guest routes accept this URL-safe
format and the legacy 43-character format. Logs mask both.

Keep existing bounded-TTL capabilities valid during first cutover by importing **only
the old HKDF-SHA256 signer subkey**, not the application master, into a separate
non-exportable Transit `hmac` key. Use an isolated owner-controlled migration process:
read the old private keyring, derive `HKDF(master, info="signer", length=32)`, place
its standard-base64 value in a private0600 tmpfs file; run the official
`bao transit import transit/keys/<legacy-links> @<private-file> type=hmac exportable=false allow_plaintext_backup=false`
with an operator capability and verified TLS; remove the temporary file. Never pass
key material as argv/env, never mount it or the operator capability into normal PHP.
Import is one-time and fails if the key already exists. Verify a synthetic/owner
capability before rollout, without logging its URL/signature.

The application ACL receives only `update` on
`transit/verify/<legacy-links>/sha2-256`; no `hmac`, export, import or rotation rights
for this historical key. Set `VAULT_LEGACY_SIGNER_KEY` to its name and
`VAULT_LEGACY_SIGNER_UNTIL` to the UTC epoch of the last outstanding capability expiry.
The default0 disables compatibility. Keep this import unchanged through the TTL;
after expiry remove the verify ACL/config. New links always use the new purpose key.
This also preserves old media/unsubscribe URLs that use the shared Signer; encrypted
pagination cursors follow the explicit ciphertext/keyring migration policy above.

Reference: https://openbao.org/docs/commands/transit/import/ (OpenBao2.7 supports
standard-base64 symmetric HMAC import via a private file and BYOK wrapping).
The disposable real TLS fixture proves legacy verification and denies legacy MAC
generation to PHP. Owner19.8 must perform the actual private-key custody/import.

### One-shot OAuth refresh recovery

Before provider dispatch, wrap a random per-attempt secretbox DEK with the application
Transit purpose and persist a unique active credential fence. After provider success,
locally encrypt and durably save the response with this ephemeral key **before any
further Transit operation**. Only the wrapped DEK and encrypted response enter SQL;
raw master/HMAC/pepper keys still never enter PHP. The ephemeral DEK exists only in
process memory and is zeroed. Final token encryption can fail safely: retry unwraps
the saved response and atomically replaces the token pair without another provider call.
Credential fingerprints bind plaintext token identity, not random wrapping bytes, so
`crypto:rotate` cannot permit an old one-shot refresh to be resent. Rotation includes
`oauth_refresh_attempts.recovery_key_enc`.

Distributed limitation, explicitly accepted by the owner: if the process dies after
dispatch/provider success but before the first durable encrypted receipt, the provider
cannot replay an idempotent refresh. Mark `recovery_required`, mark channels as requiring
reconnection, and never automatically reuse the old refresh token. Saved responses
recover automatically; an unsaved response requires reconnect. No plaintext fallback.
Migration47 refuses rollback while unresolved attempts exist. App image rollback is
separate from schema rollback and must retain this forward-compatible recovery state.
