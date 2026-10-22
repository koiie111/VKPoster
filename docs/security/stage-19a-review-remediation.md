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

- Targeted PHP:65 tests /1006 assertions, including migration rollback protection;
 100 concurrent actual in-process HTTP readiness requests also passed.
- Full PHP:2574 tests /44099 assertions; one opt-in real Transit test executed separately.
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
