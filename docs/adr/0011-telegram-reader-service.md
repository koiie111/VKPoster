# 0011. Isolated Telegram reader service

- Status: accepted for Stage 22
- Date: 2026-10-13 (supersedes the initial isolated experiment of 2026-10-04)

## Context

Public Telegram ingestion needs a user MTProto session, persistent update state and
Telegram-specific recovery. It must not expose account credentials to PHP/browser
or access VKPoster's database directly. The existing reader has passed controlled
live text import and autonomous recovery/contract tests.

## Decision

Keep the existing Python 3.13/Telethon service rather than rewrite MTProto in PHP.
Pinned, hash-verified Telethon, Pillow and qrcode dependencies are isolated in its
Docker image and checked by CI. SQLite holds durable inbox/update/outbox state and
Telethon session. One account/session reads multiple enabled Sources through the
internal authenticated HTTP contract. App commits before ACK; reader retries safely.
Connection generation binds peer identity; edits/albums/tombstones retain audit.
Photo delivery uses a separate durable job/outbox protocol. No parallel publisher.

Session lives in the existing private named volume, never Git/image/context/logs.
Host encryption, restricted backups, session access and capacity monitoring are
operator responsibilities. Normal operation has no public ports. QR temporarily
uses a loopback listener; 2FA uses hidden Terminal input, never browser/env/logs.
`login` remains available. No login or real credentials are needed by CI.

## Consequences

Reader restart resumes checkpoints/outbox; FLOOD_WAIT is respected. Quarantine
supports explicit repaired-event requeue. Authenticated heartbeat and existing app
operational health monitor freshness/failures. Retention is opt-in and retains
identities/recovery evidence. Account restrictions and history gaps remain explicit
limitations; one account, sequential RPCs and private local storage bound capacity.

The Compose project retains its legacy name solely to preserve deployed state.
Production must supervise it, protect state, use a private authenticated endpoint
and alert on stale heartbeat/disk/failure counts. Live photo transfer remains a
release verification item; CI does not pretend to test external Telegram behavior.

See [reader operations](../../tools/telegram-reader/README.md) for deployment,
recovery, ACK, retention, monitoring and supported commands. Python dependencies
are permitted for this separate service, not added to the PHP dependency whitelist.
