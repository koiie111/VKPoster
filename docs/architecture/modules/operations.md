# Production operations (Stage 19A, IN_PROGRESS)

The operations boundary lives in `src/Kernel/Operations`, not in a second
publishing system. `OperationalMonitor` reuses `SystemStatus`, queue counts,
platform/payment statuses and existing heartbeat keys. `ops:status` is CLI-only;
existing `/admin/system` remains protected by the staff permissions and rate limits.
`config/schedule.php` opts into deduplicated Telegram alerts only when configured.
A complete host outage must be detected by an external uptime check in 19.8.

`HealthCheck` performs dependency probes in the existing scheduler; `ReadinessState`
caches the generic result with single-flight and a bounded age. HTTP probes skip
session/Twig middleware and reveal no component names, credentials or errors. Redis authenticates when configured; production requires it.
Queue heartbeats are per publish/default/analytics; the common worker key remains
compatible. `ScheduleRunCommand` uses a connection-scoped MySQL advisory lock.
A second instance refuses to run, and MySQL releases the lock after disconnect.

`BackupArchive` streams authenticated sodium frames, requires an external key and
rejects wrong keys, corruption and incomplete streams. `BackupService` creates a
logical SQL archive using a private temporary client config, then verifies it.
`BackupDestination` requires HTTPS and S3 bucket versioning; failures propagate,
so retention never follows a failed offsite upload. Restore requires an empty
`app_restore_*` database and an account granted only that target. The application
DB cannot be the target; there is no automatic DROP DATABASE or production rollback.
Local encrypted SQL + closed-binlog PITR replay has passed on an isolated MySQL target.
Actual S3 object-version/offsite restore remains owner-only 19.8 evidence, not
a claim from mocked bucket-versioning/upload contracts. See the current status ledger.

`LoggerFactory` uses dated JSON files; `SecretRedactor` bounds payload depth/size
and removes credentials/PII/body/exception details. `LogRetention` removes only
old dated app logs. Docker local logs have independent size bounds. GlitchTip sends
only event code/class with bounded HTTP timeouts; notification errors cannot recurse.

Release builds and deployment scripts are in `.github/workflows/release.yml` and
`scripts/deploy-release.sh`. They gate main-history tags, run checks, publish GHCR
exact digest artifacts by promotion (no rebuild), and require owner-enabled protected environment.
App-only manifests and --no-deps prevent application rollback from changing infrastructure. Failed readiness rolls back
images only when the schema is explicitly compatible; no DB rollback is implied.
No production tag or actual SSH deployment has been performed in Stage 19A.

See [runbook](../../runbook.md), [deploy](../../deploy.md),
[verification ledger](../../security/stage-19a-status.md), ADR 0019/0020 and tests
`Backup*Test`, `OperationalMonitorTest`, `HealthTest`, `QueueTest`, `GlitchTipTest`,
`LogRetentionTest`, `SecurityRuleTest`, `ConfigTest`, `SecretRedactorTest`.

## Final Stage19A boundaries

ADR0024 selects official-derived MySQL8.4.11 and a signed/checksummed Oracle8.4.11
CLI bundle. BackupService never selects MariaDB, uses --source-data=2 and verifies
restores/binlog replay on isolated targets. No client is installed on the host/web image.
ADR0025 selects purpose-isolated Transit. crypto:rotate covers all encrypted columns,
referral details and queued/dead mail with CAS and retry-safe offline migration.
Production readiness includes key policies; PDF authentication has no shared raw key.
Actual key custody/backend HA, offsite recovery, RPO/RTO and engine cutover remain19.8.


### Oracle process timeout (Stage19A final review)

`BACKUP_PROCESS_TIMEOUT=1800` governs both dump and restore CLI children; valid range
1–86400 seconds, checked at config load. `BackupProcess` uses a monotonic deadline,
kills/reaps the child on expiry and returns an explicit failure. The systemd backup
template has a45min **whole workflow** budget (default30min CLI + lock/archive/offsite
margin). If raised, coordinate both budgets; very large backups may need more margin.
Timeout/nonzero exit never means a successful restore: the isolated target can contain
partial data. Stop and have the owner recreate **only that isolated target** before retry;
the command refuses nonempty targets and never drops/creates a production database.
