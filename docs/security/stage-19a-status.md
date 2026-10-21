# Stage 19A — local preparation evidence, 2026-10-21

Stage19 remains **IN_PROGRESS**. Branch `stage-19-hardening-prod`, baseline
`8f01fbe31f7b52c8be0bafd9ca8f4e9f668a33b5`. Production19.8 is not executed.
No production deploy, v1.0.0, history rewrite, old branch deletion or stash change.
The former MySQL tooling and crypto-isolation blockers have been remediated below.
Local closure gates passed;PR32 is under CI, not merge or production sign-off.
Fresh CI surfaced Pillow12.3.0 decoder findings absent from the old local cached DB;
pinned13.0.0 passes reader/HTTP contracts; fresh CI scan remains enforced without
suppressions (individual ledger). Local DB refresh failed with mirror EOF and is
not counted as a successful fresh scan. Main amd64 PHP and OpenBao CI passed.

## Secret and source gates

[gitleaks.md](gitleaks.md) classifies the five original findings individually:
one revoked historical credential (owner confirmed), two synthetic fixtures and
two SDK documentation false positives. Only exact fingerprints or narrow rule/path/
value conjunctions are allowed. Full available Git history and Git-visible worktree
PASS; new-key negative vectors remain detected, including in the same fixture path.
No active credential was accepted or disclosed. Seven Docker contexts exclude env,
sessions, runtime databases and VCS data; synthetic context regression PASS.
Semgrep:266 rules /823 targets /0 findings. No broad severity/vendor ignores.

## MySQL8.4 baseline and Oracle tooling (ADR0024)

- Official-derived MySQL8.4.11: same server; pinned gosu rebuilt with patched Go;
  unused mysql-shell removed. Candidate1 CRITICAL/32 HIGH →0/0. Original8.0 image
  (1/102) is retired from production preparation, not ignored or silently upgraded
  on the owner's existing volume. [Individual findings](infra-findings-stage19a.md).
- Oracle mysql/mysqldump/mysqlbinlog8.4.11, verified RPM and private loader bundle,
  only in ops/dev. Native Alpine PHP extensions remain native. No MariaDB fallback.
- Consistent InnoDB dump with `--source-data=2`, authenticated encryption, manifest
  integrity, restricted empty-target restore and closed-binlog PITR replay PASS.
  Production-config test uses no APP_KEY/local pepper; separate offline backup role
  receives the backup encryption key. Restore never targets the application DB.
- Actual cold-copy8.0.46→8.4.11 with schema44→46 preserves fixture data, Cyrillic/
  emoji, utf8mb4 and UTC. Untouched source8.0 copy restarts as the recovery route;
  no unsupported in-place downgrade. Fresh, upgrade, supported rollback/full replay
  and generated-schema checks PASS on disposable databases.
- Existing scheduler/Queue/SKIP LOCKED/Publisher:10,000 publications,4 workers,
  all sent, p95=51.62s, p99=54.36s PASS. No alternate publisher/framework.

## Isolated crypto (ADR0025)

Production startup requires HTTPS Transit; no Sodium/env-key fallback. Six distinct,
non-exportable derived keys: app encryption, webhook encryption, links/signatures,
PDF HMAC, password pepper and click pseudonymization. OpenBao2.7.1 MPL2.0 pinned
source rebuilt with Go1.27.3/x-net0.60.0: official image0/4 HIGH →derived0/0.
Actual TLS/Raft test verifies purpose separation, forbidden key export, versioned
signatures/pepper, rotation/rewrap, retirement/recovery and legacy-v2 migration.
Actual Chromium uses a verify-only capability, never the app capability or HMAC key.

Inventory covers account/provider/TOTP/payment/AI/referral ciphertext, webhook
secrets, queued/dead encrypted mail, links, PDF, password pepper, click identity and
independent egress telemetry salt. Raw active encryption/HMAC/pepper masters are
**not exposed to normal app/worker/renderer processes**. Ordinary leaf credentials
are decrypted only when required by their integrations. Separate offline backup
and one-time legacy-keyring migration roles remain explicit exceptions, not app keys.

`crypto:rotate` supports bounded CAS updates, interruption/resume, all old APP_KEYS
IDs, remote rewrap and legacy-v2 context. Tests cover concurrent replacement and
backend outage retry before adapter dispatch. Preserve old recovery keys/backups;
never roll an old Sodium-only image against v3 rows. First-cutover old local signed
capabilities must expire or be reissued. Experimental local pepper-v1 hashes require
inventory/reset before cutover; they are not silently accepted as remote pepper.

## Other completed local preparation

- Explicit production config, read-only roots/tmpfs, resource/capability limits,
  authenticated Redis, independent health/readiness, worker heartbeats and single
  scheduler lock. Actual production images/Compose, scale worker=2, failed-image
  readiness and image-only recovery tested on isolated services.
- RU Core + per-platform existing Guzzle/Telethon direct/HTTP/SOCKS5/tunnel routing,
  redacted proxy credentials, bounded retry/backoff, rotation guards, health metrics.
  Direct internal Core API is separate; no global VPN or real EU VPS was provisioned.
- WebAuthn/password/MFA hardening; malware scan fail closed. Actual ClamAV protocol
  clean/infected/unavailable tests use synthetic signatures, not production CVD.
- Bounded redacted JSON logs,30-day retention, queue/egress/backup metrics, optional
  GlitchTip/Telegram transports; no live external alert sent.
- Release workflow/runner, shared backup/deploy lock, encrypted archives/binlogs,
  timer templates, offsite/media versioning contracts. Real offsite restore remains19.8.
- ASVS inventory, module/schema/env catalogues, deploy/runbook and ADRs updated.

## Regression ledger

| Gate | Actual local evidence |
|---|---|
| PHP | PASS2560 tests /43,534 assertions on actual Oracle-bundle ops runtime/MySQL8.4;1 opt-in skip executed separately below. Stale pre-rebuild dev container correctly failed the mandatory-client guard |
| Isolated Transit + PDF |1 opt-in integration test /39 assertions PASS plus actual Chromium PDF; ordinary suite skips this case, this stand executes it |
| PHPStan / style / audit | PASS, strict level8, php-cs-fixer and composer audit (no advisories) |
| Reader |67 offline cases (2 HTTP-only skips); both real PHP HTTP contracts separately PASS; synthetic session roundtrip, never owner session |
| Trivy | All10 selected production artifacts0 CRITICAL/0 HIGH; final app/web/ops/PDF rebuild rescanned0/0 |
| DAST/runtime | Actual isolated Caddy HTTPS ZAP:0 FAIL /0 WARN /3 INFO /58 PASS; health/readiness, worker scaling and image rollback |
| Release runner |7 Linux fake-command scenarios PASS; Compose and ambient-secret isolation negative tests PASS |
| HTTP performance | Existing successful warmed200RPS×60s:12,001 HTTP200, p95=6.55ms,0 errors/drops; unaffected by these remediations, not rerun for hours |
| DB performance |10k four-worker MySQL8.4 run above; bounded backlog EXPLAIN/slow-query sample retained |
| UI | Admin health8 cases,0 blocking axe; security screens/virtual WebAuthn enrollment→login→revoke and password behavior PASS; no owner consent accepted automatically |
| Ruff / schema | PASS; explicit fresh + baseline44 upgrade/rollback/replay on final ops/MySQL8.4 |
| Documentation | phpDocumentor PASS;201 public/literal env names documented; AGENTS/CLAUDE synchronized |

Failures retained as evidence: initial cold/concurrent HTTP run dropped6 iterations;
one worker p95=96.48s failed60s target; use tested scaling. The original MariaDB
backup failure was a tooling incompatibility, resolved with Oracle8.4 clients, not
an application incompatibility. Failed Docker image pulls are not counted as scans.
Trivy evidence uses cached2026-10-19 database; CI refreshes normally. No scan implies
complete absence of vulnerabilities or production certification.

## ASVS and owner19.8 boundary

All259 requirements individually assessed:124 PASS,92 PARTIAL,24 N/A,
14 NEEDS_OWNER,3 DEFERRED_TO_STAGE_19_8,2 IMPLEMENTED/PRODUCTION_VERIFICATION_19_8.
[The original12 FAIL](asvs-remediation-stage19a.md):7 locally verified,3 deferred
actual deployment/device/signature/custody checks,2 crypto architecture implemented
with actual isolated-backend evidence. No automatic risk acceptance or blanket
ASVS conformance claim. V1.6.2/V6.4.2 no longer have the identified local code gap;
production key custody/availability/recovery still require the owner's stand.

19.8 must verify real VDS/OS/SSH/firewall/DNS/TLS renewal, regional exit connectivity,
protected release secrets, production Transit unseal/HA/audit/capability renewal and
Raft+unseal recovery, cold DB cutover/rollback, offsite S3 object restore, SMTP/alerts/
remote uptime, fresh malware signatures and registration→payment→publication on
production. Dedicated backup key custody and old keyring/capability/hash inventories
are required. No credentials requested in chat. Stage19 DoD/tag/deploy remain pending.


## Linux CI fixture ownership follow-up

The first amd64 build exposed an arm64-only loader glob; both bundle recipes now
use the loader resolved by `ldd` and execute all three Oracle CLIs during build.
The following native amd64 PHP CI passed. Fresh CI also passed all image scans
with Pillow13.0.0, then exposed UID1001 runner→UID1000 ops access to the0700 public
baseline archive. The archive alone is0755 and has a non-root readability preflight;
repeat cold engine upgrade PASS. No key/env directory is made public by this change.

Related private TLS fixtures now retain0600 and assign the actual server UID in a
one-time initializer. Actual Linux file-open regression proves a cap-drop-all root
process cannot read another UID's0600 key; correct ownership succeeds. Capability
redaction keeps synthetic values in fixture memory rather than reopening another
UID's private file. Negative redaction test, actual TLS Transit/Chromium, production
health/worker scaling/rollback and HTTPS ZAP repeat PASS. Runtime capabilities and
production file-mode checks are unchanged. CI must execute the remaining stands;
none is skipped to obtain a green result.


### Renderer UID verification

Native CI passed the39-assertion Transit test and exposed a separate PDF mount
ownership error: Playwright pwuser is UID1001, not app UID1000. The renderer now
receives its own verify-only capability at0600/UID1001; the application mount is
unchanged. The pinned image build asserts pwuser UID/GID1001 and deploy instructions
specify separate owners. A real named-Linux-volume regression proves wrong-owner
EACCES, correct-owner success, and denial to app UID1000; it does not rely on Docker
Desktop bind-mount permission emulation. Actual Chromium/Transit repeat PASS. CI
fixture diagnostics are bounded only after root/capability redaction, allowing
further failures to be diagnosed without publishing credentials.


### ZAP report portability

A named-Linux-volume probe with the actual ZAP image reproduced UID1000 failing to
write a runner-UID1001 directory0755. The scanner now uses a unique private Linux
volume0700/UID1000; only zap.json and zap.html are exported to a new private host run
directory. No caller-directory chown, world-writable mount or root scanner is used.
Actual isolated HTTPS ZAP remains0 FAIL/0 WARN/3 INFO/58 PASS and reports persist;
volume cleanup checks its fixture label. Original/previous reports are retained.


A later native CI run stopped before contracts on an upstream PECL HTTP500 while
downloading dev-only pcov1.0.12. The existing installation now has three bounded
attempts/backoff and still fails if none succeeds; no extension/test is disabled.
This download failure is not counted as a crypto/PDF test failure or a pass.

CI follow-up: hosted amd64 PHPUnit reached the final tests without an assertion
failure before Composer terminated it at its default300-second process limit.
Only the CI full-suite command now uses an explicit bounded900-second timeout;
the30-minute job limit and every test remain enforced. This is not a passing
result until the new complete CI run finishes.
Production-image CI on0ed4343 passed all gates, including actual Transit/Chromium,
engine upgrade, encrypted PITR, Linux DAC and private-volume ZAP export. All nine
other CI jobs passed; only the Composer full-suite timeout required this follow-up.

Subsequent production CI hit upstream PECL HTTP500/502/504 on Imagick/Redis
while building its dev-only crypto fixture image. A rerun reproduced the outage.
Pinned Redis6.3.0, Imagick3.8.1 and pcov1.0.12 now install individually through
a shared three-attempt/backoff helper, requiring each compiled module before
enabling it. Exhaustion still fails closed; no test or extension is optional.
