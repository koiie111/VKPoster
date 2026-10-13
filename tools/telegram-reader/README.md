# Telegram reader — Stage 22 Sources service

Separate Python/Telethon service for enabled Telegram Sources. One authorized user
account reads multiple public channels. It accesses VKPoster exclusively through
an authenticated internal HTTP API, never its database or publishing credentials.
It does not publish, join channels, select content or call AI providers.

## Deployment and authorization

From the repository root:

```sh
cp tools/telegram-reader/.env.example tools/telegram-reader/.env
chmod 600 tools/telegram-reader/.env
docker compose -f tools/telegram-reader/compose.yaml build reader
# Stop the reader before authorizing the same session.
docker compose -f tools/telegram-reader/compose.yaml run --rm -p 127.0.0.1:18186:8765 reader login-qr
# After local authorization:
docker compose -f tools/telegram-reader/compose.yaml up -d reader
```

Set Telegram API credentials only in the local env file. QR is displayed on the
loopback HTTP listener and cleared on exit, not saved in logs/files. Telegram 2FA
uses hidden foreground Terminal input. `login` is the fallback (phone from local
`TELEGRAM_PHONE`, hidden OTP/2FA). Never run authorization in CI.
An authorized persistent session skips login; never reset it just to deploy code.

Use a dedicated, authorized account, a private encrypted host and backed-up state.
The Compose project retains its historical name `vkposter-telegram-reader-spike`
**only to preserve the existing named volume**; the service image is
`vkposter-telegram-reader`. Do not rename the project or use `down -v` on deployment.

Persistent `reader-state` volume:

- `/state/telegram.session`: Telethon session, an account credential; protect like a password.
- `/state/reader.sqlite`: checkpoints, message revisions, albums, outbox and delivery state.
- `/state/photos`: private content-addressed original-photo cache.

One process owns the state lock. Container uses non-root UID 1000, a read-only root
filesystem, bounded CPU/memory, temporary `/tmp`, no capabilities and no public
port during normal operation. Session is not encrypted by Telethon: encrypted
host storage and restricted backups are deployment requirements.

Set `VKPOSTER_INTERNAL_URL` to a private app endpoint. Docker Desktop development
uses `http://host.docker.internal:8080`; use HTTPS outside a trusted private network.
Set the same random `SOURCES_READER_SECRET` (at least 32 characters) on both sides.
No secret or Telegram access hash/session is returned to browsers. The HTTP client
rejects redirects and embedded credentials. Do not expose this API to an untrusted
network; app authentication remains mandatory even on the private network.

## Lifecycle and recovery

`sources` refreshes the enabled-source list every 10 seconds. Telegram RPCs share
one client/lock. Each connection generation has separate peer/checkpoint identity;
old-binding outbox is never applied to a newly connected channel. Disabled Sources
are ignored and retain local recovery state. Photo delivery continues fairly
across Sources, so one unavailable channel does not block the others.

First connection reads the last 10 messages plus neighboring album members.
Messages use stable peer/message IDs, entities retain Telegram UTF-16 offsets,
edits retain history, and tombstones explicitly propagate deletions. Albums use a
five-second quiet window; late members/edits/deletions revise one logical item.
Quiescent is not a guarantee of complete history.

Restart resumes persistent high-water/pts and channel differences; recent 100
messages are reconciled for edits. A difference-too-long gap is recorded, not
silently treated as recovered. Telegram cannot recover every intermediate edit or
a message deleted before the reader ever saw it. FLOOD_WAIT persists the required
wait; the reader never floods Telegram or circumvents its limits.

Every event is persisted to SQLite **before** sending. App ACK follows committed
inbox/material state. Lost ACK, crash and duplicate delivery replay the same event
identity safely. Startup rebuilds missing snapshots from durable messages/albums.
HTTP/network/5xx/auth failures retry with bounded exponential backoff (max 300 s).
HTTP 422 quarantines a retained event rather than looping forever. With the reader
stopped, inspect safe IDs/statuses and requeue only a repaired event:

```sh
docker compose -f tools/telegram-reader/compose.yaml run --rm reader status
docker compose -f tools/telegram-reader/compose.yaml run --rm reader outbox-retry --event-id EVENT_SHA256
```

Image jobs are separate authenticated requests for approved/current materials.
Reader downloads the largest complete Telegram PhotoSize (including progressive
sizes, excluding stripped/path thumbnails), caches bytes and returns SHA-256 and
metadata. The original before Telegram compression is not promised. PHP validates
size/MIME/dimensions independently (16 MiB transfer cap). Image outbox persists
before delivery and repeated ACK/restart does not duplicate import. Video/documents
retain metadata; this service does not download them for automatic processing.

## Monitoring and retention

A successful cycle posts an authenticated heartbeat. App content operational health
reports reader freshness, automation/discovery/queue state and provider failures;
`status` reports local outbox counts and quarantined IDs. Monitor stale heartbeat,
recovery gaps, repeated failures, available disk space and Telegram restrictions.
Operational logs exclude source text, phone, OTP, keys, QR URL and passwords.
Raw content exists only in private persistent state, not test artifacts/logs.

`READER_ACK_RETENTION_DAYS=0` disables cleanup by default. Values >=7 compact at
most 100 old ACKed payloads per cycle. Event identities, pending/failed rows, session,
checkpoints and media cache remain. Cache/state growth needs operator monitoring;
backup and host capacity remain deployment responsibilities. App media retention
is documented in [content hardening](../../docs/architecture/modules/content-hardening.md).

## Dependencies and CI (no credentials)

Python 3.13 base digest and all dependencies (Telethon, Pillow, qrcode and transitive
packages) are pinned with hashes in `requirements.txt`. Docker installs with
`pip --require-hashes`. CI builds this image, runs offline unit tests, pinned Ruff
check/format and two synthetic HTTP contracts against isolated `app_test`.

```sh
docker build -t vkposter-telegram-reader tools/telegram-reader
docker run --rm --network none --read-only --tmpfs /tmp --entrypoint python vkposter-telegram-reader -m unittest discover -s tests -v
# After the full PHP suite; do not run concurrently against app_test:
sh tools/telegram-reader/tests/run_php_contract.sh
```

Tests use Telethon TL objects, fake transport, synthetic media and test-only secrets.
They never authorize Telegram or contact paid providers. `.env`, sessions, SQLite,
credentials and local files are excluded recursively from Git and Docker contexts.
The Docker context regression checks nested secret fixtures without reading real
credentials. CI artifacts must never include private `/state` or env files.

Live controlled `@sansillusions` text import → selection → automation → Draft was
confirmed. Real Telegram photo delivery into Image Processing was not separately
live-tested. Account availability, multi-account scaling, unlimited polling and
recovery of all deleted history are not guaranteed. No publishing occurs here.

See [ADR 0011](../../docs/adr/0011-telegram-reader-service.md) and
[Sources architecture](../../docs/architecture/modules/sources.md).
