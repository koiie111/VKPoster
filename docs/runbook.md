# Operational runbook — Stage 19A preparation

Status: IN_PROGRESS. This document is for the approved production layout, not a
statement that production has been deployed. Domain/IP, SSH, TLS, offsite retention
and restore evidence must be completed with the owner in 19.8.

Run from `/opt/ezposter` as the designated operator. In the commands below:

```sh
export APP_ENV_FILE=/etc/ezposter/app.env
ops() { docker compose -f compose.prod.yaml --env-file "$APP_ENV_FILE" "$@"; }
```

The env file must be a regular 0600 file. Do not paste it, DSNs, passwords, raw
provider responses, tokens or database dumps into tickets. Record UTC incident time,
release SHA, queue name and non-sensitive entity IDs. Never run `migrate:fresh`,
`down -v`, `FLUSHALL`, mass retries, forced payments or unqualified SQL deletes.
There is no `queue:retry` CLI in this project: do not invent one or replay unknown
external operations. Preserve audit records and use the existing administration UI.

## 1. Readiness fails, liveness succeeds

`curl --fail --max-time 5 http://127.0.0.1:8080/readyz` and `ops ps`.
Check DB/Redis/schema states without exposing their configuration. `ops exec -T app
php bin/console migrate:status` shows the migration ledger. Apply reviewed forward
migrations only; restarting containers cannot repair an absent migration.

## 2. Application is not live

Check `ops ps` and bounded `ops logs --tail=50 app nginx` privately. Check host disk
and memory (`df -h`, `free -m`), then the image/release and rootfs permissions.
Restart only the affected service after capturing diagnostics. External uptime must
probe the public HTTPS `/readyz`; an in-app scheduler cannot alert if the host is down.

## 3. Redis unavailable or authentication mismatch

Check `ops ps redis` and its health status, without printing container env or auth
arguments. Ensure the same owner-provisioned `REDIS_PASSWORD` is used by app and
Redis. Restart consumers after a reviewed credential update. PIN/rate-limit paths
must remain fail-closed; never bypass protection to restore access.

## 4. Queue backlog grows

`ops exec -T app php bin/console ops:status` reports waiting/failed/oldest age.
Check worker heartbeat for the affected queue and provider failures first. Scale
ordinary workers with `ops up -d --no-deps --scale worker=4 worker` only within host
capacity. Keep exactly one scheduler. Separate analytics-worker from publish.
Do not retry unknown paid/publish operations just to clear the dashboard.

## 5. Worker died while executing a job

Check queue heartbeat, process exit and provider outcome. `ops restart worker`
restarts long-lived code; existing Queue lease/recovery is responsible for abandoned
reservations. A lease timeout does not prove a remote publish/payment failed.
Inspect unknown outcomes before manual retry; do not delete idempotency records.

## 6. Scheduler heartbeat stopped

`ops exec -T app php scripts/runtime-health.php scheduler`; inspect bounded logs.
`ops restart scheduler` after confirming DB connectivity. MySQL advisory lock
allows only one active scheduler and releases on disconnect. Do not disable it or
scale schedulers to compensate for a backlog.

## 7. VK/MAX/Telegram API outage or 429

Check platform status, queue age and failure classifications. Preserve existing
retry/backoff/rate-limit handling. A provider timeout can have an uncertain outcome;
never blindly re-publish. Mark the provider incident using the existing admin UI,
communicate affected channels, resume after recovery without mass replay.

## 8. Payment pending or callback delayed

Use the existing billing admin and gateway dashboard to compare invoice/payment IDs.
Verify callback signature and provider status through supported reconciliation.
Do not force renewal or manually mark paid on a timeout. Preserve idempotency keys,
ledger and callbacks. Escalate unresolved disagreement to the owner/payment provider.

## 9. Suspected leaked key

Contain access, revoke the provider credential at the provider, issue a new one
through the owner, audit affected operations. Do not print either value or rewrite
Git history. Scan history/worktree, document a narrow revoked-finding exception only
after owner confirmation, and prove a new credential remains detectable.

## 10. Isolated crypto rotation / legacy APP_KEY migration

Production uses purpose-isolated OpenBao-compatible Transit, never APP_KEY/APP_KEYS.
See ADR0025 for the complete inventory, token ACLs and migration boundaries.
An operator rotates the required purpose keys, retains old versions, restarts workers,
then runs `crypto:rotate --dry-run`, `crypto:rotate` and another dry-run. This uses
remote rewrap. Do not retire an old server key version while historical backups,
queued/dead mail, signed links or password verifiers still require it.

For original v1 secretbox data, stop writes and use only the isolated non-production
CLI maintenance container with `--legacy-keyring-file=/run/legacy/keys.json` (0400/0600).
No old key in normal app env/config; no HTTP service in that migration container.
A partial run can resume: ciphertext/payload CAS updates preserve concurrent changes.
Verify all encrypted columns and mail dead letters, then remove that container/mount.
Retain offline old keyring and prior DB snapshot for historical recovery.
Do not run pre-Transit code against newly migrated v3 data. Cross-format rollback
requires restoring the matching snapshot/code/key recovery set; it is not image-only.

If backend readiness fails, check private TLS/CA, sealed/active-node status, token TTL/
policy and purpose-key metadata. Never enable Sodium fallback or print provider errors,
root token, recovery shares, application capabilities or plaintext secrets.

## 11. Daily backup failed

Inspect `systemctl status ezposter-backup.timer ezposter-backup.service` and bounded
private journal output. Check directory ownership/mode 0700, external key 0600,
free space, S3 connectivity/versioning and credentials. An encrypted local file is
not proof of offsite success. Keep it for investigation; do not prune older offsite
versions or change the key to make a failed verification pass.

## 12. Isolated restore drill

Provision an empty `app_restore_*` database and separate account granted only that
database; restore refuses the current DB and broad grants. Supply `RESTORE_DB_*`
through a temporary private env, with the original backup key mounted read-only.
`ops --profile ops run --rm --no-deps backup php bin/console ops:backup verify /backup/ARCHIVE.ezb`
and then `... ops:backup restore /backup/ARCHIVE.ezb app_restore_drill --confirm-isolated`.
Compare row counts/selected synthetic checksums, schema and media links. Record RPO,
RTO and release/key versions. Never expose the target publicly or overwrite production.
PITR local drill has passed: coordinate-bearing SQL snapshot, encrypted closed log,
restricted isolated SQL restore, then matching Oracle MySQL8.4.11 row-event replay using `/opt/mysql/mysqlbinlog`. Do not use
MariaDB binlog output against MySQL, strip unknown statements, or assume a checksum
proves replay. Follow the SQL dump coordinates and require a continuous verified log
sequence. Test on a separate server; database-name rewriting does not rewrite every
qualified DDL name. Owner must repeat with production backups in 19.8.

Local ops use `/run/mysql/mysqld.sock` from a readonly socket volume. An isolated
restore gets its own `RESTORE_DB_SOCKET`; never point it at the source DB. Database
grants with underscores must escape them (or have verified partial_revokes literal
semantics), otherwise MySQL treats them as wildcards. For offsite media configure
S3 versioning and run `ops:backup check-media`; local storage is not reported as
versioned offsite backup. Verify actual historical object recovery with the owner.

Hourly `ezposter-binlogs.timer` needs explicit BACKUP_BINLOG_ENABLED and
BACKUP_BINLOG_ROTATE plus a separate backup account (SELECT/SHOW VIEW/TRIGGER/EVENT
on the app DB; RELOAD/REPLICATION CLIENT/SHOW_ROUTINE as required by the server).
Do not give these privileges to the web app. Daily SQL and hourly logs share the
host operations lock with deployment, preventing schema changes during a dump.

## 13. Disk full or logs growing

Use `df -h`, `docker system df` and bounded directory size checks. App JSON logs
rotate daily; the retention task removes dated files older than 30 days. Docker
local logs have size limits. Do not use `docker system prune --volumes` or delete
media, SQL volumes, key files, audit history or selected processing results.
Verify backup/offsite success before any approved retention operation.

## 14. Release readiness fails / rollback

`scripts/deploy-release.sh` deploys immutable versioned images, runs forward
migrations and checks readiness. It requires explicit backward-schema compatibility.
On failed readiness, previous images are restored; the DB is not automatically
rolled back. On migration failure, stop deployment and assess compatibility/restore.
Do not run `migrate:rollback` on production to conceal a failed migration.
No previous release means no automatic image rollback: first deploy needs owner.

## 15. PDF renderer unavailable

Check `ops --profile analytics ps` and bounded renderer logs. The renderer receives
only its derived key file; never pass `APP_KEY`, env_file or DB credentials to
Chromium. Recreate the trusted `analytics-pdf-key` init and renderer together after
rotation. Existing dashboard/CSV must remain available while PDF is degraded.

## 16. Monitoring silent or reports contain sensitive data

Check `OPS_MONITOR_ENABLED`, scheduler heartbeat and configured destinations by
presence only. Alerts are deduplicated for 15 minutes. Empty DSN/bot settings disable
external transport; transport mocks do not prove delivery. Validate a deliberate
non-sensitive event only with owner permission. Redact any ticket attachment,
contain a logging leak, rotate exposed credentials, and remove unnecessary payload
logging. Do not globally disable audit or secret scanning to reduce alert noise.

MySQL8.4 snapshot coordinates are SOURCE_LOG_FILE/SOURCE_LOG_POS from
`mysqldump --source-data=2`; the old MariaDB SHOW MASTER STATUS path is unsupported.
Do not downgrade8.4 dictionary files to8.0. Preserve the pre-upgrade cold source and
verify restore/replay on a separate target before traffic cutover (ADR0024).
RPO≤1hour requires enabled rotation, successful hourly archive/offsite delivery and
monitoring. With binlogs disabled, only the last verified daily snapshot is recoverable.
Record actual RTO from a production-sized isolated drill in19.8.


## Review remediation recovery procedures (Stage19A)

- OAuth: `oauth_refresh_attempts.response_saved` means encrypted credentials are durable;
  restore Transit and retry the normal credential consumer. It recovers without contacting
  the provider. `recovery_required` means an unsaved/uncertain one-shot response: reconnect
  explicitly; never clear the fence to replay the old refresh token. Do not dump encrypted
  envelopes/credential rows into logs. Migration47 rollback refuses unresolved recovery.
- Backup: only verified/fsynced atomic renamed files are final. Target locks serialize
  writers and are released by the OS on crash. Next run removes only regular stale partials
  for its locked target. Legacy truncated final binlogs are quarantined and rebuilt from
  the still-available immutable closed source; other corruption/auth failure stops loudly.
  Preserve quarantine evidence and source logs for investigation. Never consider a partial
  an offsite success. Archive-directory lock prevents overlapping binlog runs.
- Egress: connect timeout/TLS-connect failure stays safe pre-dispatch and retryable across
  direct/HTTP/SOCKS/tunnel; post-dispatch/read timeout remains uncertain. Error chains do
  not retain credential-bearing requests or raw proxy diagnostics.
- App rollback: follow deploy.md; never invoke dependency reconciliation or DB downgrade.
  Verify all pulled digest references against the promotion manifest before migration.
- Backlog (not implemented in this pass): configurable readiness HTTP_PORT in host runner;
  binlog S3 delivery manifest/retention to avoid repeated versioned uploads.
