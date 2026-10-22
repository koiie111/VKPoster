# ADR 0020: Operations transports and isolated verification tools

Date: 2026-10-19. Status: accepted for Stage 19A preparation; no production deployment.

## Decision

Use the existing HTTP client for opt-in Telegram operational alerts and a minimal
Sentry-compatible transport to a self-hosted GlitchTip instance. No Sentry PHP SDK,
new application framework or parallel queue is introduced. `SENTRY_DSN` is env-only.
The transport sends stable event identifiers and exception class, not exception
messages, stack arguments, bodies, headers, email, IP or arbitrary contexts.
Reporting has bounded timeouts and cannot recursively log its own failure.
Empty DSN disables the transport. Instance location, access control, retention and
an actual test event are owner verification in 19.8, not proven by mocked HTTP.

Security/load tools run in separate Docker containers, never the application image:
Gitleaks 8.30.1 (digest pinned), Semgrep 1.181.0, Trivy 0.76.0, ZAP 2.17.0 and k6 2.3.0.
These are explicitly required by the official Stage 19 plan. Vendored Semgrep PHP
and OWASP rules have recorded source/version/checksums; scans disable telemetry.
They do not receive production env/session files. ZAP uses synthetic isolated data;
its spider may submit discovered forms and must never target the owner's working app.
Version updates require re-running scanners and recording changed findings.

MySQL command-line tools exist in dev/ops targets only. Backup encryption uses the
existing PHP sodium extension, authenticated secretstream frames, and a separate
external 0600 key. Offsite upload uses the already installed AWS SDK via Flysystem's
S3 dependency, not another SDK. Bucket versioning is checked before upload; failures
propagate. The S3 mock contract does not prove an actual offsite restore.

## Evidence and limitations

`GlitchTipTest`, `OperationalMonitorTest`, `BackupArchiveTest`, `BackupDestinationTest`,
`BackupTest`, scanner reports and `docs/security/stage-19a-status.md` record results.
Composer audit checks application dependencies; Trivy must independently verify the
built runtime. Tool version pinning alone does not establish absence of CVEs.
GlitchTip DSN and bot credentials are provisioned only by the owner; no external
notification was sent while implementing this ADR.

## Official references

- https://glitchtip.com/documentation/error-tracking/
- https://www.zaproxy.org/docs/docker/baseline-scan/
- https://trivy.dev/docs/latest/advanced/air-gap/

ZAP exit 2 (warnings) remains a failed gate until each warning is assessed; no `-I`
or blanket IGNORE is used. HTTP dev-stand results do not establish HTTPS production safety.
