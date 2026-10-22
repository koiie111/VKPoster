# Stage 19 trust boundaries and failure cases

Assets: workspace content/media, OAuth and payment credentials, session/guest capabilities,
publication idempotency and payment ledger, encrypted backups and external decryption keys.

Boundaries:
- Internet → host Caddy TLS → loopback nginx → non-root PHP: untrusted headers/input.
  Trusted proxies must describe this exact chain, never all source IPs.
- PHP/workers → private MySQL/Redis: workspace policy, prepared SQL, queue claims;
  production Redis authentication required. Host access/firewall/TLS evidence remains 19.8.
- Outbound social/payment/content URLs: existing typed adapters and SSRF DNS pinning;
  no live requests from tests, uncertain paid outcomes must not be blindly retried.
- Browser → workspace/admin: permission checks, CSRF, step-up 2FA, guest expiry/revoke/PIN.
  No operational endpoints with credentials are public; ops:status is CLI only.
- CI → image registry → deployment SSH: immutable release refs, full scanners,
  trusted host key, protected production environment, main ancestry + v* tag gate.
- Backup producer → encrypted S3 archive → isolated restore account: key never in archive,
  auth frames/final marker + manifest hashes; restricted empty app_restore_* target.

Threats to test: spoofed forwarding headers, cross-workspace identifiers, stale leases,
retry after lost ACK/unknown paid outcome, credential leakage in context/exception/logs,
archive corruption/truncation, symlink substitution, deploy concurrency and incompatible DDL.
Recovery must distinguish image rollback from schema recovery. No down -v/reset/production
restore in release automation. Local SQL/binlog replay is verified; actual offsite/S3-version recovery remains owner evidence.

Open: ASVS residual FAIL/PARTIAL remediation/sign-off, actual production host controls,
cloud media/offsite recovery and remaining final validations. Every ASVS requirement
has an explicit primary assessment; this model does not turn documented gaps into PASS.
