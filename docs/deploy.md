# Production preparation and deployment

Stage 19 is **IN_PROGRESS**. This is a reviewed preparation/runbook, not evidence
that a production server exists. Owner-only activation is 19.8: VDS/domain/DNS,
SSH host keys and protected GitHub environment, real credentials, TLS, offsite
restore and registration → payment → publication. No v1.0.0 is created in 19A.
See [current evidence](security/stage-19a-status.md), [runbook](runbook.md),
[configuration](architecture/configuration.md), [ASVS findings](security/asvs-l2.md).

## Host and trust boundary

Use a supported Linux host with Docker Engine/Compose plugin, systemd, `flock`,
`curl`, OpenSSL and time synchronization. Actual region/legal/data-residency,
capacity, SMTP and storage providers must be approved by the owner before deploy.
Do not size the host from the old 2 CPU/4 GB MVP estimate: compose contains explicit
per-service memory/CPU/PID limits, plus MySQL, Redis, optional Chromium and workers.
The load report states its measured hardware; it is not a universal sizing guarantee.

Host Caddy terminates public HTTPS/HTTP2 and compresses responses. Adapt
[docker/caddy/Caddyfile](../docker/caddy/Caddyfile) only after DNS/owner approval;
configure service supervision, certificate persistence and renewal. Caddy → nginx
is loopback `127.0.0.1:8080`; MySQL/Redis have no public ports. SSH is restricted to
approved operators. Verify firewall and exact trusted-proxy addresses on the host.
The isolated test CA is **never** installed as a production trust root.

App/worker images use a native Alpine runtime; build/dev/CSS use glibc (ADR 0021).
Root filesystems are read-only, writable storage/tmp paths are explicit, capabilities
are dropped and `no-new-privileges`/resource limits applied. PHP runs non-root.
Private-container TCP traffic is not a substitute for inter-host TLS; see ASVS gaps.

## Secrets and configuration

Provision `/etc/ezposter/app.env` externally, owner/mode 0600, from the **public**
`.env.production.example`. Never put it in an image, Git or command output.
Keep `APP_KEY`/old `APP_KEYS` in a separate protected recovery vault. Configure a
non-default application DB account, root DB password and required Redis AUTH.
`APP_URL` must be HTTPS, `APP_DEBUG=0`, `DEV_*` disabled; `TRUSTED_PROXIES` must
identify the actual proxy chain, never wildcard/universal networks.
Configure SMTP, legal text, storage and only the providers actually used.

A separate backup key file is base64 32 random bytes, regular file/mode 0600,
accessible to the app UID in the ops container. Store a second recoverable copy
outside the host and outside backup archives. Never print either application or
backup keys in CI logs. Key rotation: [runbook](runbook.md); preserve old keys until
ciphertext migration and recovery validation complete. Reader session/env remain
separate private runtime state; production reader provisioning is not implied here.

## Release layout and first activation (owner 19.8)

Provision `/opt/ezposter` with `compose.prod.yaml`, `docker/mysql/my.cnf`,
`docker/mysql/production.cnf`, `scripts/deploy-release.sh` and the directories needed
by these reviewed relative mounts. The owner provisions infrastructure files; application
release CI copies only Compose, its app runner and the verified image manifest over pinned SSH.
Provision a least-privilege GHCR pull login on the server independently of app env.
No Docker socket is mounted in application containers. Protect GitHub `production`
environment, require approval and set deploy host/user/key/known-hosts secrets there.
A host key mismatch is a hard failure, not a reason to disable SSH checking.

Release workflow accepts only a `v*` tag whose commit is in main history, runs CI
and security, then publishes versioned app/web/ops/pdf images to GHCR. Deploy is off
unless the original repository sets `PRODUCTION_DEPLOY_ENABLED=true`. An explicit
`RELEASE_SCHEMA_BACKWARD_COMPATIBLE=true` declaration is mandatory. The deploy runner
uses the common `.operations.lock`, pulls images, migrates before rollout, restarts
app/workers/scheduler, and waits for `/readyz`. The previous `.release.env` image refs
are retained for rollback. Tags must not be overwritten; retain previous images.

Analytics renderer is optional: `PRODUCTION_ANALYTICS_ENABLED=true` enables the
release `analytics` profile, updates the verify-only renderer; no master/derived HMAC key init container in production.
Renderer receives only its derived read-only key, never master APP_KEY/app env.
Set the corresponding app analytics configuration consistently. On first activation
create a staff administrator using the existing CLI and enable 2FA; inspect the
one-time initial password only locally. Enable real webhooks only after HTTPS works.
No public test publication or real payment is part of automated 19A validation.

## Availability and rollback

`/healthz` is liveness only, independent of DB/Redis/session. `/readyz` reads bounded-age
scheduler health state and returns503 on failed, stale or missing state; no per-request remote probes. Configure external uptime on
HTTPS `/readyz`; queue checks also use `ops:status` and queue-specific heartbeats.
Exactly one scheduler process is intended; MySQL advisory lock also rejects overlaps.
Scale existing `worker` services, not a second publishing implementation.

A failed migration stops rollout while preserving running images. Failed readiness
rolls back **images only**, and only when the declared schema is backwards-compatible.
No automatic DDL reversal, reset, queue truncation or volume deletion. For incompatible
DDL, stop and use the separately reviewed backup/migration recovery procedure.
First deploy has no previous release to restore. Compose `up` is not a zero-downtime
rolling orchestrator; allow a maintenance window and check in-flight worker shutdown.

## Encrypted backup and recovery

Use `ops:backup` from the **matching released ops image**, not an ad-hoc plaintext
`mysqldump` shell command. External directories must exist with private permissions;
never let Compose silently create secret files. SQL backup uses single-transaction,
coordinate-bearing dump when binlog backup is enabled, authenticated encryption,
manifest/checksum validation, offsite upload and only then local SQL retention.
Credentials stay in a private temporary option file, not argv. Use a separate
least-privilege backup account; do not give the app global backup privileges.
The read-only MySQL Unix socket is available to ops on the same host; it avoids
weakening certificate validation for MySQL client utilities.

`docker/systemd/ezposter-backup.*` and `ezposter-binlogs.*` are **inactive templates**.
After a successful release they read `/etc/ezposter/app.env` and `.release.env`, and
share `.operations.lock` with deployment. Daily SQL and hourly closed-binlog archives
are encrypted and uploaded; active binlogs are excluded, source binlogs never purged
by the app. Optional explicit rotation requires the separate backup account.
Local SQL retention defaults to 30 days (minimum 7), only after verified offsite upload.
Encrypted binlogs are retained conservatively until a tested continuity/retention
policy exists. MySQL raw-binlog expiry is 7 days: alert and restore-drill before this
window is exhausted. Never delete a log required by a retained base snapshot.
Offsite bucket lifecycle must preserve the base-snapshot/binlog recovery chain.
Monitor timer failure, archive age, free disk and offsite reachability; a missed timer
must alert, not silently rely on a future daily backup. Check timers after reboot.

S3 archive and media buckets require versioning enabled. `ops:backup check-media`
rejects local media as an offsite/versioned backup. Actual offsite object-version
restore is owner 19.8; contracts alone are not proof of cloud recovery. SQL restore
requires an empty `app_restore_*` DB and a separate account granted **only that exact
escaped name**. Never restore into the live app database. Real local SQL + encrypted
binlog replay uses Oracle MySQL tools matching the source on a separate recovery
stand; MariaDB output is not rewritten merely to force MySQL compatibility.

## Monitoring and logs

`ops:status` is the existing queue/system/platform/payment read model exposed via CLI.
Queue workers heartbeat separately; alerts use opt-in `OPS_*` settings and Redis
suppression. Test alert delivery/uptime independently in 19.8. GlitchTip store transport
is optional, HTTPS only, bounded timeout and no bodies/headers/arguments/PII; provision
self-hosted GlitchTip separately (ADR 0020). No alert was sent during local tests.

App JSON logs rotate daily and expire after 30 days. Container logs are size-bounded;
nginx operational logs omit request URL/query, IP, cookies and referrer so capability
links do not leak. Keep production `LOG_LEVEL` at an appropriate non-debug level.
Protect log access/shipper and configure host retention/remote transport; Docker size
rotation alone does not prove a 30-day remote retention policy. MySQL slow log is off
by default because SQL may contain PII; enable bounded incident capture only, then
sanitize and remove it. See the runbook's 16 incident procedures.

## Activation checklist

- Review ASVS residual risks and host controls; do not claim L2 certification.
- Green release/security CI, previous-image and isolated-restore drill available.
- Private env/key permissions, firewall/proxy chain and public TLS/renewal checked.
- Healthy app/database/Redis and all queue heartbeats; singleton scheduler.
- SMTP and opt-in alert delivery, external uptime, backup timers and offsite age checked.
- S3 versioning, source/media recovery and application keys independently recoverable.
- Owner registration → payment → publication and restore on a separate stand.
- Only then decide production readiness/version tag; Stage 19A does not do this.


### Stage 19A gates added on 2026-10-20

Do not deploy this uncommitted preparation. MySQL 8.4.11 passed fresh/upgrade/replay
and the full PHP suite but failed PITR snapshot compatibility: current MariaDB dump
client issues removed SHOW MASTER STATUS. Engine migration is stopped; 8.0 baseline
is unchanged and its infrastructure CVEs remain blocking. See
[per-finding disposition](security/infra-findings-stage19a.md), not an ignore list.

Provision isolated purpose-key Transit and private app/renderer capabilities with
TLS trust anchor before startup (ADR0025). Production rejects APP_KEY/APP_KEYS/local
pepper files. Pepper HMAC stays inside Transit; do not mount an active master key.
Use VAULT_APP_TOKEN_HOST_PATH, VAULT_PDF_TOKEN_HOST_PATH and VAULT_CA_HOST_PATH;
app capability files are UID1000,0400/0600; the separate verify-only PDF capability
is UID1001 (pwuser),0400/0600. The image build checks this UID explicitly. Never
make a capability world/group-readable to work around a mount ownership mismatch. Tokens are replaceable credentials,
not key material. Verify renewal/revocation and server recovery in19.8.

[ADR 0022](adr/0022-regional-egress.md) defines RU Core with per-platform regional
egress. Keep Core/internal API direct; VK/MAX may remain direct while Telegram
reader/publisher use a configured EU proxy/private tunnel. No global server VPN.
Live exit-IP/connectivity/failure validation is 19.8; no EU VPS has been provisioned.

## Stage19A final MySQL / crypto baseline

MySQL8.4.11 derived official server and matching Oracle8.4.11 CLI are defined in
ADR0024. Build `docker/mysql` target `server` before a local production-stand run;
never change the owner's existing8.0 volume by an unattended Compose restart.
Production cutover is a separate reviewed owner19.8 action. Dev's existing8.0
container is deliberately unchanged; the isolated test profile and CI use8.4.

Read ADR0025 before crypto cutover. Use initialized durable OpenBao-compatible
Transit with independently generated app/webhook/links/pdf/password/clicks keys,
least-privilege renewable app token and separate PDF verify-only token. The endpoint
must select an active node; authenticated redirects are not followed. Do not use a
single-node ephemeral test fixture as a production backend. Real HA, Raft snapshot/
unseal recovery and audited custody require owner verification.

Local-only PDF shared-key init remains available in compose.yaml for development.
Production renderer has only its verify capability and CA, not a shared signing key.
`CRYPTO_BACKEND=vault` is mandatory; an unset backend/token/CA or forbidden raw key
must be fixed, never bypassed with the dev backend. See runbook10 for migration and
cross-format rollback constraints.


## Stage19A review: immutable application promotion and infrastructure boundary

Release builds each candidate once, scans `repository@sha256:...`, records the digest,
and runs the isolated runtime/crypto/PITR checks against those exact artifacts. Only
then does `release-artifacts.py promote` tag unchanged manifests and emit
`release-images.env`. SBOM/provenance remain attached. There is no rebuild after this
gate. Existing different version tags, scan/publish mismatch, or pulled/deployed digest
mismatch fail closed. The separate dev harness is not a published release artifact.

Owner-controlled `/etc/ezposter/infra.env` (regular0600) owns all infrastructure image
pins/settings (MYSQL_IMAGE and REDIS_IMAGE in the base Compose). Caddy/OpenBao
remain separately owner-provisioned infra. ClamAV uses `docker/clamav/Dockerfile`:
the same digest-pinned1.5.4 vendor scanner plus Alpine `brotli-libs>=1.2.1-r0`
(CVE-2026-111497), without scanner exclusions or entrypoint changes. Build it with
`docker build --platform linux/amd64 -t ezposter-clamav:stage19 docker/clamav`,
then run `sh scripts/security/scan.sh trivy ezposter-clamav:stage19` before separately
provisioning it. CI scans this derived image and the isolated production stand uses
the same image. Their upgrade files/profiles are outside app CD. The
application release manifest contains only APP_IMAGE, WEB_IMAGE, PDF_IMAGE, OPS_IMAGE.
The infra env is applied last, including during rollback of old app release files that
may contain obsolete infrastructure settings. Do not upload/replace infra config from
the app release workflow. Provision its reviewed pins/production.cnf independently.

Application invocation:
`MIGRATIONS_BACKWARD_COMPATIBLE=true bash scripts/deploy-release.sh VERSION REGISTRY_PREFIX /opt/ezposter/release-images.env`
requires healthy pre-existing MySQL, Redis and ClamAV. It pulls pinned app artifacts,
runs forward migration, then starts only app/nginx/worker/scheduler/analytics-worker
(and optional analytics-pdf) with `--no-deps --no-build`. Failed readiness restores
only previous application images. **Application rollback is not database rollback.**

A MySQL/infra upgrade is a separate owner-approved19.8 maintenance operation, for
example `docker compose -f compose.prod.yaml --env-file /etc/ezposter/app.env --env-file /opt/ezposter/.release.env --env-file /etc/ezposter/infra.env up -d --no-deps --no-build mysql`
only after verified backup, isolated restore, compatibility rehearsal and explicit
infra pin change. Never downgrade the DB engine on its current data volume. App CD
never executes this command or targets stateful infra. Check old/new container IDs
and image digests before/after the owner maintenance window.

`/healthz` remains cheap public liveness. `/readyz` returns only generic cached
`ok`/`unavailable`; it never probes Transit, clamd or SQL. The existing scheduler
refreshes dependency health once per minute with a45s Redis single-flight lease,
15s minimum probe interval and90s freshness bound (Redis TTL120s). Missing, stale,
or inaccessible state fails closed503. Start/restore the scheduler when readiness
is stale; repeatedly requesting the endpoint cannot repair or overload dependencies.
The cached identity includes schema/runtime endpoints; no dependency details appear
in the public response. Retain the scheduler during rollout and allow its first tick.

Operations TCP transport requires an explicit mounted `OPERATIONS_DB_TLS_CA_FILE`.
Oracle CLI uses VERIFY_IDENTITY; PDO verifies CA/hostname and checks negotiated TLS
before any grants query or FLUSH. No insecure production TCP fallback. Unix socket
backup stays the default. For isolated remote restore mount that target's CA into the
ops container read-only using a reviewed Compose override. Local/testing can use the
separate unencrypted fixture policy; never apply it in production.


Migration47 cutover also requires a reviewed rollback target: a pre47 image whose
readiness demands the older migration count cannot become ready after migration47 merely by
setting the compatibility flag. Keep recovery data/schema forward, and rehearse an
actually compatible application recovery image before declaring compatibility in19.8.
The runner never compensates by downgrading MySQL or destroying OAuth recovery state.


### Oracle process timeout (Stage19A final review)

`BACKUP_PROCESS_TIMEOUT=1800` governs both dump and restore CLI children; valid range
1–86400 seconds, checked at config load. `BackupProcess` uses a monotonic deadline,
kills/reaps the child on expiry and returns an explicit failure. The systemd backup
template has a45min **whole workflow** budget (default30min CLI + lock/archive/offsite
margin). If raised, coordinate both budgets; very large backups may need more margin.
Timeout/nonzero exit never means a successful restore: the isolated target can contain
partial data. Stop and have the owner recreate **only that isolated target** before retry;
the command refuses nonempty targets and never drops/creates a production database.
