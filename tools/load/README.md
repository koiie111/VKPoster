# Stage 19 isolated load checks

Use a disposable `app_load_*` database, synthetic env and an internal-only Docker
network. Never run preparation against the normal development/production database.
No actual social/payment/AI calls: only Fake social adapters, disabled monitoring
and synthetic users/sessions. Private env/session files stay outside Git.

`bench:publish --fake --count=10000 --spread=1 --prepare-only` creates ordinary
PostService scheduled posts, arms one due_at burst after planning and returns the
workspace/count. It refuses production and requires an isolated DB for prepare-only.
The benchmark cleans only its own workspace/publication jobs, never the whole queue.
`tools/load/burst.php arm|tick|status|explain` uses the same guarded DB. `arm` refuses
completed/in-flight fixtures; prepare a new dataset for the next measurement.

Start existing workers (`php bin/console queue:work --queue=publish --sleep=1`)
from the actual production runtime image with the explicitly **non-production**
synthetic Fake env. Use 1 then N workers, equal total CPU budget, stop gracefully
between runs. The existing PublicationScheduler/Queue/Publisher performs all work.
No fake timestamps/delays or substitute HTTP response handlers. Count missing/failed
publications as failures, measure actual `sent_at - due_at` and save status JSON
outside Git. Scheduler batches 20×500 publications maximum per tick.

`web.js` uses k6 2.3.0, 200 arrivals/sec for 60s, p95 <300ms, all expected statuses,
no dropped iterations. Real HTML includes authenticated account/security and
account/notifications as well as help/status/landing; it does not benchmark healthz
instead of product pages. Staff rate limits stay enabled and are tested separately.
`LOAD_SESSION_FILE` is a private JSON synthetic session outside Git. HTTPS uses
`__Host-sid`, plain local HTTP uses `sid`. A self-signed dedicated `.test` stand may
set `SELF_SIGNED_FIXTURE=true`; never use that switch against an external host.
Warm/validate the stand before timing; 302/502 results are failures, not fast pages.

## Local results, 2026-10-20

Docker Desktop arm64, host 16 CPU/8GB. Dedicated MySQL 8.0.46 and Redis. Actual
PHP 8.3.35 native Alpine prod artifact, existing Fake adapters; no dev bind filesystem.
Publication workers share 4 CPU total; each has a 384MB upper limit (memory totals
therefore differ with worker count). Database/code/dataset construction are the same.

| Run | Result |
|---|---|
| 10k publications / 1 worker (4 CPU) | All 10000 sent, p95 72.04s / p99 75.10s: FAIL 60s goal |
| 10k publications / 4 workers (1 CPU each) | All 10000 sent, p95 31.92s / p99 33.46s: PASS |
| HTTPS HTML / actual prod image, app 1 CPU/768MB | 12000 HTTP 200, 200RPS ×60s, p95 9.25ms, zero errors/drops: PASS |
| Pending 10k backlog EXPLAIN ANALYZE | Existing idx_publications_due used, bounded query about 2.99ms; no speculative index added |

The HTTP app used APP_ENV=production, cached Twig/opcache, Caddy HTTPS and nginx.
The publishing benchmark deliberately uses APP_ENV=local to permit Fake; this does
not mean real provider latency was measured. The actual prod compose also passed
health/scaling/HTTPS ZAP on an independent disposable project.

Earlier dev artifacts: 1-worker p95 109.55s, 4-worker p95 32.48s. These are superseded
by the native artifact run. A prior 5.79ms HTTP result included redirects and is
invalid; cold/misconfigured stand failures were not counted as passing evidence.
Bounded slow-log sample: 11 queries >50ms, max 604ms, 10009 rows examined. The log
contains only synthetic fixture SQL and is not committed. Host/real providers differ;
choose production capacity from live owner evidence, not these synthetic numbers alone.

Repeat `tick` during the measurement (or run the existing scheduler). A single
10k-capped scheduler tick includes due publications from other fixture workspaces;
it must not be mistaken for a perpetual scheduler. An incomplete run with 9998/10000
left two unscheduled rows because two unrelated due fixture posts consumed the
first tick capacity. No product scheduler defect or publishing timeout was hidden:
the corrected harness drives subsequent normal scheduler ticks, retains unrelated
rows and still requires all 10000 sent.

A concurrent cold HTTP run recorded 6 dropped iterations (11994/12000 completed,
all completed HTTP 200, p95 47.45ms). It FAILED the zero-drop gate. Run isolated
workloads after warming/validating all measured pages; do not erase that failure
or relax thresholds. Final repeat results are recorded separately.

## Security-continuation repeats (2026-10-20)

Same native runtime and guarded8.0 fixture; updated auth schema, synthetic signature
ClamAV service, immutable verifier-key mount. Four workers sent all10000, p9532.66s,
p9933.90s PASS; one worker sent all10000, p9596.48s FAIL60s. Warmed isolated HTTP
repeat completed12001 HTTP200 at200RPS60s, p956.55ms,0 dropped/errors PASS.
No threshold was relaxed. Real CVD memory, external Vault/provider/egress latency and
production VDS capacity remain owner19.8 checks. MySQL8.4 performance/promotion was
not run after the coordinate-backup functional regression stopped that migration.
