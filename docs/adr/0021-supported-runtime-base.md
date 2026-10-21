# ADR 0021: Separate glibc build/dev from minimal supported runtime

Date: 2026-10-19. Status: accepted for Stage 19A preparation; validated locally, no production deploy.

## Evidence

With Trivy 0.76.0 and a checksum-verified official 2026-10-19 vulnerability DB:

| Actual image | Critical | High |
|---|---:|---:|
| Original Bookworm serving image | 22 | 663 |
| Trixie serving image after removing compilers/headers | 1 | 221 |
| Original nginx 1.27 Alpine | 2 | 53 |
| Adopted Alpine 3.24.2 runtime (same PHP 8.3.35, existing code/vendor/CSS) | 0 | 0 |

The Trixie remainder includes CVE-2026-6653 in libxml2. Debian explicitly reports
no fixed stable package; scanner severity/unfixed flags are not weakened:
https://security-tracker.debian.org/tracker/CVE-2026-6653 . Other representative
fixed/newer-library cases are aom (CVE-2023-6879) and GLib (CVE-2026-58016).

## Decision

Preserve PHP 8.3 and Tailwind 3.4.17. Build/dev/vendor/app-build use the official
Debian Trixie PHP digest. Only that glibc boundary runs the checksum-pinned Tailwind
binary. The serving image uses official PHP 8.3 Alpine 3.24 with independently built
PHP extensions and current supported native libraries. Copy only PHP application,
Composer production dependencies and compiled static assets across the boundary;
never copy compiled Debian extensions/libraries into musl.

This supersedes ADR 0002's need for glibc **in the serving runtime**, while retaining
its build/dev rationale. No gcompat, Node, application framework or publishing
pipeline is introduced. Runtime and builder digests are pinned. Production nginx
moves to the current official stable Alpine digest.

Explicit ImageMagick runtime modules/codecs are retained: a library-only extension
check missed missing PNG/JPEG coders in the first candidate. Builds now actually
encode PNG/JPEG/WEBP/GIF as well as check required PHP extensions. Compilers and
extension build dependencies are removed using APK's virtual dependency boundary.
The isolated ops target adds MariaDB client and its caching_sha2_password connector;
it must not downgrade MySQL authentication or disable TCP TLS verification.

## Database operations

Local backup jobs use a read-only shared MySQL Unix-socket volume, separate backup
credentials and readonly raw-binlog mount. A Unix socket is a local secure transport,
not a TLS-verification bypass. Isolated restore may receive its own socket path;
it still refuses the source DB and non-isolated/wildcard privileges. Remote restore
requires an independently trusted server certificate and operator-provisioned tools.

A real drill found MariaDB binlog output incompatible with MySQL session variables.
PITR decoding therefore uses vendor MySQL mysqlbinlog matching the source engine,
on the **separate recovery stand**. The successful local drill used Oracle 8.0.46,
checksum-verified from official repository metadata. No vendor RPM/binary or key is
committed, and no SQL statements are stripped to force replay success.

The pinned nginx stable Alpine base had two newly reported HIGH findings with
available supported fixes (libexpat/libx11). Apply `apk upgrade --no-cache` in the
web target and scan the actual result: zero HIGH/CRITICAL, no blanket suppression.
Package repository changes can alter future scans; release gates always rescan.

## Validation and owner boundary

Full Trixie dev and native Alpine ops suites pass, including codecs/font/video and
backup/restore. Actual native readonly stack/worker scaling/HTTPS ZAP and HTTP200RPS
plus 10k Fake publications pass. Final build/scan evidence is recorded in the ledger.
The earlier Trixie-only results did not certify this runtime. No production tag,
release or SSH deployment is performed until the owner-only 19.8 step.

## Reader native runtime validation (2026-10-20)

The final all-image scan found49 HIGH in Python3.13 slim/Debian, including distro
packages with no reported fix and unused pip-vendored components. Do not ignore
the Debian vendor/severity or strip only vulnerability metadata. Use the official
Python3.13.16 Alpine3.24 digest2d9aefe2… after isolated compatibility validation.
The interpreter minor and all pinned runtime dependencies stay unchanged. Pillow
uses its native musllinux aarch64 wheel, not a glibc extension copy. Install then
remove the entire unused pip/ensurepip tooling from the immutable runtime; keep
application package metadata. No package install is needed at runtime.

Evidence:67 offline reader tests PASS (2 HTTP-only skips), both separate actual PHP
HTTP contracts PASS (events/images), synthetic Telethon SQLite session
Debian→Alpine→Debian roundtrip preserves DC/auth-key state, Pillow2560×1920 delivery
and SHA checks PASS. New image Trivy0 HIGH/CRITICAL. No real Telegram login/session
was mounted or used. Existing owner state/env are not replaced or deleted; UID1000
and /state contract remain. Real Telegram/regional-exit live check remains19.8.
[Official supported tags](https://raw.githubusercontent.com/docker-library/official-images/master/library/python).
