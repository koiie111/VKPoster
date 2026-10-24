# PR32 review remediation — 2026-10-22

Baseline: `ae79c3271d13baaa27a3fee96585667bdd74f866`. Stage19 **IN_PROGRESS**;
no production deployment, v1.0.0, merge or Stage20. This pass addresses only the
2 Blocker +6 Important in the independent review.

| Finding | Change | Reproducible evidence |
|---|---|---|
| B1 guest links | `t1_` base64url versioned Transit MAC; bounded legacy verification through a separately imported verify-only HMAC key; routes/redaction aligned | ApprovalTest Transit create/preview/approve/comment/analytics +legacy/malformed; SecretRedactorTest; real TLS RealTransitTest import/expiry/deny-signing |
| B2 infra rollback | app-only digest manifest, owner-controlled infra.env applied last, healthy infra precondition, explicit --no-deps on rollout/rollback | test_deploy.py success/failure/lock/readiness rollback/unchanged infra; deploy.md separate owner infra operation |
| I1 atomic binlogs | locked unique partial, authenticated roundtrip verification, fsync, atomic rename; quarantine only proven legacy truncation, other corruption fails | BackupArchiveTest real SIGKILL +concurrent rerun; BinlogBackupTest truncation/rebuild/subsequent logs/authenticated corruption; actual PITR stand |
| I2 egress errors | sanitized exceptions retain connect-timeout/connect/read-timeout/uncertain distinction; no credential-bearing original request/chain | EgressRoutesTest direct/http/socks5/tunnel classification; ProviderHttp retry categories |
| I3 ops TLS | one CA/VERIFY_IDENTITY policy for CLI and PDO, negotiated TLS check before queries, no production TCP fallback | OperationsDbPolicyTest; operations-tls-stand.py real MySQL valid/mismatched hostname for both drivers |
| I4 readiness | public cache-only endpoint, generic response; scheduler-owned probe, Redis single-flight, bounded freshness | ReadinessStateTest100 forked requests/monitor ticks, bounded probe count, stale/down503, cheap liveness |
| I5 OAuth recovery | unique durable dispatch fence, prewrapped ephemeral DEK, encrypted response before later Transit calls, resumable final persistence | OAuthRefresherTest before/after Transit failure, restart/duplicate/rejection/unsaved-response reconnect; migration47 and rotation coverage |
| I6 artifact promotion | build candidates once, scan/test immutable digests, promote same manifest, refuse different existing tag, verify pulled digest before migration | test_release_artifacts.py +test_deploy.py; release.yml isolated runtime gate against scanned artifacts |

## Owner-approved OAuth distributed limitation

A provider that does not support idempotent refresh cannot guarantee response recovery
if the process dies between provider success and the first durable receipt. The owner
explicitly accepted `recovery_required`, fail closed and reconnect for this narrow
window. The old refresh token is never automatically reused. Already saved encrypted
responses recover automatically after Transit returns. This is not reported as safe
provider retry or absolute no-loss delivery. No plaintext DB/log or raw-master fallback.
See ADR0025 for the envelope/import design and operator custody steps.

## Validation ledger

- Targeted PHP:66 tests /1010 assertions, including migration rollback protection;
 100 concurrent actual in-process HTTP readiness requests also passed.
- Full PHP:2575 tests /44103 assertions; one opt-in real Transit test executed separately.
- Real TLS OpenBao:1 test /43 assertions plus actual Chromium PDF verified.
- Reader:67 cases; two actual PHP HTTP contracts executed separately (commit/ACK,
  duplicate/edit/restart, images across Sources). No live Telegram/provider requests.
- Release/deploy contracts:12 tests;17 Linux script tests +2 actual host Docker/Compose/DAC contracts passed.
- PHPStan, Ruff, schema fresh/upgrade/rollback/replay, Gitleaks history/worktree and
  synthetic detection vectors, Docker context exclusions passed.
- Semgrep:266 rules /830 targets /0 findings.
- Guest preview375/768/1440 UI/axe passed; screenshots visually checked.
- Production app/web/ops rebuild, isolated runtime/scaling/image rollback, actual
  production-mode Oracle backup/restore/PITR and HTTPS ZAP passed (0 FAIL/0 WARN,58 PASS).
- Fresh Trivy DB updated2026-10-22T01:15:55Z, downloaded2026-10-22T04:39:46Z:
  app/web/ops/MySQL/Redis/Caddy/OpenBao/reader/PDF/ClamAV all0 CRITICAL/0 HIGH.
  Official mirror timed out and the first GHCR download had a transport error;
  the bounded official GHCR HTTP/1 retry succeeded. No skip or new exception.
- All local gates passed. Mandatory remote CI is the remaining review gate; no merge.
  A local Alpine download/extraction attempt failed during an interrupted network
  interval; no dependency/security gate was bypassed. Retry is separate evidence.

## Backlog only

1. Host deployment readiness URL should respect HTTP_PORT (current documented default8080).
2. Binlog S3 delivery manifest/retention to avoid repeated versioned uploads.

No binlog delivery manifest is implemented in this pass. All production19.8 verification
items remain pending, including actual custody/cutover, EU egress, production WebAuthn,
AV signatures and separate-stand restore. No ASVS production/compliance claim is made.

## CI strict-parser follow-up

The first remote source gate found a Semgrep `PartialParsing` warning in the new
one-line deployment-manifest `case` block. A0-findings summary did not mean strict
success: JSON diagnostics and the actual exit status exposed the parser failure.
The block is now conventional multiline Bash with identical whitelist/exit behavior.
`bash -n`, all12 deploy/promotion contracts, and Semgrep `--strict --error` with
JSON `errors=[]` and0 findings passed. No rule, strict flag or finding was suppressed.
This is a small CI compatibility follow-up to the main remediation commit; published
history remains intact. Remote CI must rerun on the final branch head.

The next CI pass reached the new TLS stand but failed its first positive PDO check.
The fixture's old socket SELECT readiness could observe the entrypoint's temporary
initialization server before final TCP startup. It now waits for Oracle's verified
TCP connection (`VERIFY_IDENTITY`, the fixture CA, and the restricted fixture user)
before either driver assertion. Local real PDO/Oracle positive and hostname-mismatch
checks and Ruff passed again. Production TLS enforcement remains unchanged; the
fixture reports only bounded exception class/code on failure, not token/error bodies.

A subsequent runner stopped before TLS at the PITR shell fixture's silent second
socket probe. Its bootstrap guard now requires `@@skip_networking=0`, distinguishing
the final daemon from the entrypoint's temporary server while retaining the approved
Unix-socket operations path. A fresh isolated MySQL/Redis/OpenBao coordinate-backup,
restricted restore and authenticated binlog replay passed locally; strict Semgrep
also passed with no JSON errors. No production image, TLS mode or assertion was relaxed.

The upgrade fixture had the same socket-only bootstrap race before its first TCP
migration. It now waits for a non-empty version row only when networking is enabled,
and checks the preserved8.0 restart likewise. Fresh cold-copy8.0.46→8.4.11 passed
with all45→48 migration files, existing Cyrillic/emoji, UTC/utf8mb4 and the intact
original8.0 rollback volume. The report counts migration files rather than confusing
that count with the highest numbered filename. Strict Semgrep remained clean.


A synthetic B1 regression also covers a legacy43-character MAC whose bytes happen
to start with `t1_`. Length-based legacy verification now precedes the versioned
transport decoder; genuine Transit tokens are longer. Old valid capabilities are
not accidentally decoded as the new format. Tampering remains rejected. Updated
full PHP2575/44103 and targeted66/1010 passed; no token value is hardcoded in the test.


## Final review remediation — 2026-10-24

Baseline `c5cb5a1e1880a03046d4cae60e6416e1ddf5508a`; only the two should-fix items
are implemented. Stage19 remains **IN_PROGRESS**, owner production19.8 pending.

1. **OAuth refresh lock expiry race — FIXED.** Migration48 adds explicit
   `dispatch_started_at`; a matching dispatched attempt remains Temporary for90s
   (20s total HTTP timeout in both adapters plus Transit/database scheduling margin).
   Redis lease expiry alone does not declare uncertainty, break the channel or call
   a rotating refresh token twice. After the bounded window, an absent receipt still
   requires reconnect; explicit ambiguous provider errors fail closed immediately.
   Attempt-row locking serializes completion vs uncertain observers. Late genuine
   responses restore tokens and clear only that attempt's marked recovery error;
   paused/revoked or newer unrelated errors survive. Exact legacy recovery messages
   on active attempts are adopted during migration, never arbitrary channel errors.
   OAuthRefresherTest covers the interleaved A/B dispatch, one provider call, boundary,
   bookkeeping timestamp independence, late completion, unrelated error and upgrade.
   The previously accepted unsaved-response crash limitation remains in force.

2. **Oracle backup/restore timeout — FIXED.** Single `BACKUP_PROCESS_TIMEOUT`
   configuration (default1800s, integer1–86400, validated on config load) drives both
   CLI operations. `BackupProcess` uses monotonic elapsed time, kills/reaps a timed-out
   child and throws an explicit failure. Real isolated restore timeout proves partial
   data is not reported successful and a nonempty target is refused on retry. No
   automatic database drop/recreation is introduced. The systemd template gives the
   whole workflow45min; operators must increase it with margin when raising the CLI
   budget. A fake monotonic clock tests301s elapsed without waiting five minutes.

Minor backlog from the repeat independent review (not implemented in this pass):
- stale plaintext `work-*` after SIGKILL/OOM;
- global Content-Disposition on JSON responses;
- TRUSTED_PROXIES validation/message mismatch.

The earlier HTTP_PORT and binlog S3 manifest/retention backlog remains unchanged.
No production deploy, release tag, merge, Stage20, secret rotation or new external
provider call. Main, old branches and stash are preserved.


Final-pass validation (2026-10-24, current source):
- Targeted OAuth/backup/config regression: **68 tests /268 assertions**, PASS.
- Full PHP suite: **2589 tests /44166 assertions**, PASS; one existing opt-in
  real Transit test skipped in the default suite (no new skip added).
- Fresh schema + baseline c5cb5a1 upgrade + rollback/replay: PASS in disposable
  app_check database; migration regression also preserves/adopts existing recovery data.
- Actual production-mode Oracle8.4 encrypted backup, restricted isolated restore,
  authenticated binlog decrypt/PITR replay with isolated OpenBao: PASS.
- PHPStan strict, code style, Composer audit, env inventory and diff check: PASS.
- Gitleaks full history/worktree and negative exception vectors: PASS, no new exclusions.
- Semgrep1.181.0, pinned rule hashes/strict mode:266 rules,830 targets,0 findings,
  JSON errors=[] and exit0; new config/BackupProcess and OAuth code included. Because
  the Docker registry transfer stalled, the same pinned scanner ran from a private
  temporary venv with identical local rules (no repository dependency/config change).
- Existing deploy/promotion mocked contracts:12 tests PASS in Linux; /healthz200.
- UI unchanged; no new k6/Trivy/ZAP run was needed for these two fixes. Mandatory
  PR32 CI still must complete on the published final commit before any merge.

An overlapping invocation against the same test DB was discarded; the reported PHP
results are from a clean **sequential** targeted-then-full run. A missing DB_PASSWORD
in an earlier shell invocation was corrected in the harness, not in application/tests.

## Owner-authorized ClamAV CI remediation (2026-10-24)

CI on c55129b exposed HIGH CVE-2026-111497 in Alpine `brotli-libs1.2.0-r1`
inside the pinned `clamav/clamav:1.5.4_base` artifact. The owner explicitly authorized
a separate image-fix commit. Alpine's signed v3.24/main index provides1.2.1-r0.
`docker/clamav/Dockerfile` keeps the identical vendor digest/ClamAV engine/entrypoint
and upgrades only this package, with a minimum-version build assertion. Production
Compose, the isolated production stand and the infra scan matrix use the derived
`ezposter-clamav:stage19` image. Its Docker context admits only the Dockerfile;
the existing synthetic-secret context regression covers the new context too.
No CVE/rule/severity exception is added; app/CD infrastructure boundaries are unchanged.

Local validation: image build PASS; installed1.2.1-r0, engine1.5.4; full package
inventory differs only in brotli-libs. Vendor entrypoint/command/user/healthcheck/
volumes/ports are identical. Real isolated INSTREAM clean/infected checks PASS,
network disabled, read-only rootfs, no capabilities. Docker context secret regression,
Compose/release-artifact contracts (5 tests), env inventory and Gitleaks history/
worktree/negative vectors PASS. Fresh Trivy database download and new PR CI are
pending at commit time; no successful fresh-scan claim is made in advance.
The separate production-app Trivy failure is still under diagnosis; its local
image also contains brotli-libs1.2.0-r1. Owner approval for changing that second
artifact is requested separately; this commit does not modify the PHP runtime.
