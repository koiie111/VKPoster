> 2026-10-21: the validated official-derived MySQL8.4.11 server now scans0 CRITICAL/0 HIGH.
> The original reports below are retained as before evidence, not active production exceptions.
> Every8.4 candidate finding was fixed by rebuilding gosu1.19 with Go1.27.3 (25 Go
> findings, including the CRITICAL), or removing unused mysql-shell (8 Python findings).
> Oracle mysqld, mysql, mysqldump and version-matched mysqlbinlog remain present and
> actual engine upgrade/backup/PITR/10k/runtime tests pass; see ADR0024. No ignore used.

# Infrastructure vulnerability disposition — Stage 19A

IN_PROGRESS. No HIGH/CRITICAL ignore is used. These are scanner package findings,
not a claim that every issue is remotely exploitable. Classification A is an
open remediation until the applicable patched artifact passes compatibility and scan.

MySQL 8.4.11 is the validated production baseline (ADR0024), after functional,
upgrade and backup/PITR verification, not scanner counts alone. The original8.0
artifact is retired; its rows retain historical classifications, not active exceptions.

## mysql

Original artifact: `mysql:8.0`. Findings: 103.

| CVE | Severity | Package / target | Installed | Fixed version | Classification and action |
|---|---|---|---|---|---|
| CVE-2026-1965 | HIGH | curl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-3783 | HIGH | curl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-8286 | HIGH | curl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-8458 | HIGH | curl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.7 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-8927 | HIGH | curl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.7 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-9547 | HIGH | curl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2025-14087 | HIGH | glib2 / mysql:8.0 (oracle 9.7) | 2.68.4-18.el9_7.1 | 2.68.4-19.el9_8.1 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-58016 | HIGH | glib2 / mysql:8.0 (oracle 9.7) | 2.68.4-18.el9_7.1 | 2.68.4-19.el9_8.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-33845 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-33846 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-3832 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-3833 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42009 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42010 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42011 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42012 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42013 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42014 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42015 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-5260 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-5419 | HIGH | gnutls / mysql:8.0 (oracle 9.7) | 3.8.3-10.el9_7 | 3.8.10-4.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-40355 | HIGH | krb5-libs / mysql:8.0 (oracle 9.7) | 1.21.1-9.0.1.el9_7 | 1.21.1-10.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-40356 | HIGH | krb5-libs / mysql:8.0 (oracle 9.7) | 1.21.1-9.0.1.el9_7 | 1.21.1-10.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-54369 | HIGH | libacl / mysql:8.0 (oracle 9.7) | 2.3.1-4.el9 | 2.4.0-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-54370 | HIGH | libacl / mysql:8.0 (oracle 9.7) | 2.3.1-4.el9 | 2.4.0-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-4878 | HIGH | libcap / mysql:8.0 (oracle 9.7) | 2.48-10.el9_7.1 | 2.48-10.el9_8.1 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-1965 | HIGH | libcurl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-3783 | HIGH | libcurl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-8286 | HIGH | libcurl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-8458 | HIGH | libcurl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.7 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-8927 | HIGH | libcurl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.7 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-9547 | HIGH | libcurl / mysql:8.0 (oracle 9.7) | 7.76.1-35.el9_7.3 | 7.76.1-40.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63379 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63381 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63382 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63383 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63384 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63385 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63387 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63388 | HIGH | libevent / mysql:8.0 (oracle 9.7) | 2.1.12-8.el9_4 | 2.1.13-1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-74860 | HIGH | libxml2 / mysql:8.0 (oracle 9.7) | 2.9.13-14.el9_7 | 2.9.13-14.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-86138 | HIGH | libxml2 / mysql:8.0 (oracle 9.7) | 2.9.13-14.el9_7 | 2.9.13-14.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-86140 | HIGH | libxml2 / mysql:8.0 (oracle 9.7) | 2.9.13-14.el9_7 | 2.9.13-14.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-86143 | HIGH | libxml2 / mysql:8.0 (oracle 9.7) | 2.9.13-14.el9_7 | 2.9.13-14.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-86144 | HIGH | libxml2 / mysql:8.0 (oracle 9.7) | 2.9.13-14.el9_7 | 2.9.13-14.el9_8.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-14456 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-14457 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-18798 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-54874 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63073 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63074 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63075 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63076 | HIGH | openssl / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-14456 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-14457 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-18798 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-54874 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63073 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63074 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63075 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-63076 | HIGH | openssl-libs / mysql:8.0 (oracle 9.7) | 1:3.5.1-7.0.1.el9_7 | 1:3.5.8-1.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-103111 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-86145 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-89156 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-89157 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-89158 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-89160 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-89161 | HIGH | pcre2 / mysql:8.0 (oracle 9.7) | 10.40-6.0.1.el9 | 10.40-7.0.1.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-11822 | HIGH | sqlite-libs / mysql:8.0 (oracle 9.7) | 3.34.1-9.el9_7 | 3.34.1-11.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-11824 | HIGH | sqlite-libs / mysql:8.0 (oracle 9.7) | 3.34.1-9.el9_7 | 3.34.1-11.el9_8 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-69247 | HIGH | cryptography / Python | 46.0.5 | 50.0.0 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-69249 | HIGH | cryptography / Python | 46.0.5 | 49.0.0 | A — fixed version reported; compatibility/build/rescan pending |
| GHSA-537c-gmf6-5ccf | HIGH | cryptography / Python | 46.0.5 | 48.0.1 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-27459 | HIGH | pyOpenSSL / Python | 25.3.0 | 26.0.0 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-44431 | HIGH | urllib3 / Python | 2.6.3 | 2.7.0 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-44432 | HIGH | urllib3 / Python | 2.6.3 | 2.7.0 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-97687 | HIGH | urllib3 / Python | 2.6.3 | 2.8.0 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-97689 | HIGH | urllib3 / Python | 2.6.3 | 2.8.0 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2025-68121 | CRITICAL | stdlib / usr/local/bin/gosu | v1.24.6 | 1.24.13, 1.25.7, 1.26.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2025-61726 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.24.12, 1.25.6 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2025-61729 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.24.11, 1.25.5 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-25679 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.8, 1.26.1 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-27145 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.11, 1.26.4 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-32280 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.9, 1.26.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-32281 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.9, 1.26.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-32283 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.9, 1.26.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-33811 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.10, 1.26.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-33814 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.10, 1.26.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-33818 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-39820 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.10, 1.26.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-39821 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-39822 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.12, 1.26.5, 1.27.0-rc.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-39836 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.10, 1.26.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42499 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.10, 1.26.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-42504 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.11, 1.26.4 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-56853 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-56858 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-56859 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-56860 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-56862 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-78667 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.26.9, 1.27.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-78669 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.26.9, 1.27.2 | A — fixed version reported; compatibility/build/rescan pending |
| CVE-2026-97031 | HIGH | stdlib / usr/local/bin/gosu | v1.24.6 | 1.26.9, 1.27.2 | A — fixed version reported; compatibility/build/rescan pending |

## redis

Original artifact: `redis:7-alpine`. Findings: 2.

| CVE | Severity | Package / target | Installed | Fixed version | Classification and action |
|---|---|---|---|---|---|
| CVE-2026-84782 | HIGH | libcrypto3 / redis:7-alpine (alpine 3.21.8) | 3.3.7-r1 | 3.3.7-r2 | A — distro packages upgraded r1→r2 in `docker/redis/Dockerfile`; patched image scan 0 HIGH/CRITICAL and Redis PING/shutdown passed |
| CVE-2026-84782 | HIGH | libssl3 / redis:7-alpine (alpine 3.21.8) | 3.3.7-r1 | 3.3.7-r2 | A — distro packages upgraded r1→r2 in `docker/redis/Dockerfile`; patched image scan 0 HIGH/CRITICAL and Redis PING/shutdown passed |

## caddy

Original artifact: `caddy:2.11.7-alpine`. Findings: 4.

| CVE | Severity | Package / target | Installed | Fixed version | Classification and action |
|---|---|---|---|---|---|
| CVE-2026-78669 | HIGH | golang.org/x/net / usr/bin/caddy | v0.59.0 | 0.60.0 | A — rebuilt stable Caddy 2.11.7 with Go 1.27.3 / x/net0.60.0; actual runtime image scan 0 HIGH/CRITICAL |
| CVE-2026-78667 | HIGH | stdlib / usr/bin/caddy | v1.26.8 | 1.26.9, 1.27.2 | A — rebuilt stable Caddy 2.11.7 with Go 1.27.3 / x/net0.60.0; actual runtime image scan 0 HIGH/CRITICAL |
| CVE-2026-78669 | HIGH | stdlib / usr/bin/caddy | v1.26.8 | 1.26.9, 1.27.2 | A — rebuilt stable Caddy 2.11.7 with Go 1.27.3 / x/net0.60.0; actual runtime image scan 0 HIGH/CRITICAL |
| CVE-2026-97031 | HIGH | stdlib / usr/bin/caddy | v1.26.8 | 1.26.9, 1.27.2 | A — rebuilt stable Caddy 2.11.7 with Go 1.27.3 / x/net0.60.0; actual runtime image scan 0 HIGH/CRITICAL |

## Current after state

| Image | Before Critical / High | After Critical / High | Gate |
|---|---|---|---|
| MySQL 8.0 | 1 / 102 | 8.4 candidate: 1 / 32 (not adopted) | BLOCKED: MariaDB dump client SHOW MASTER STATUS SQL 1064; no baseline promotion |
| Redis 7 | 0 / 2 | 0 / 0 (`ezposter-redis:stage19`) | Local patch and startup passed; final production regression pending |
| Caddy 2.11.7 | 0 / 4 | 0 / 0 (`ezposter-caddy:stage19`) | Stable source rebuilt with Go1.27.3/x-net0.60; runtime/HTTPS/ZAP repeat PASS |

New ClamAV production artifact also requires a complete scan before sign-off.
No findings have been classified C or D without a reachability/packaging proof.
No global vendor/severity/ignore-unfixed exclusion was introduced.

## MySQL 8.4 compatibility gate — STOPPED

Candidate `mysql:8.4.11`, separate data/socket volumes; original databases unchanged.
Fresh, 44→46 upgrade, baseline rollback and full replay passed. Full PHP suite:
2552 tests / 43,485 assertions passed, including ordinary encrypted snapshot/restore.
The checker initially mishandled two migration batches; reproduced on 8.0 and fixed
to assert baseline rollback separately before full rollback/replay.

**Functional regression:** production ops uses MariaDB 11.8.8 `mariadb-dump` with
`--single-transaction --master-data=2`. Against MySQL 8.4 it executes removed
`SHOW MASTER STATUS` and fails SQL 1064 even with the isolated root fixture.
An earlier restricted app fixture also lacked RELOAD; repeating with root separated
that fixture permission issue from the actual protocol regression. No backup
option/error was suppressed, and ordinary snapshot tests do not prove PITR.

Per owner instruction the image migration is stopped: compose production/test
baseline stays 8.0. Candidate 10k/performance/PITR promotion checks are NOT complete.
A separately agreed Oracle-compatible dump/binlog client and complete restore drill
would be required before reconsidering 8.4. No engine downgrade/reset was performed.
The candidate itself still has 1 CRITICAL / 32 HIGH; version change alone is not a
security clearance. Redis patched image and ClamAV 1.5.4 base each scanned 0/0.

## Non-promoted 8.4 candidate: each remaining finding

These are NOT accepted exceptions. Candidate migration was stopped for the documented
PITR regression. Fixed versions below are scanner-reported; no reachability/false-positive
claim is made to clear a gate.

| CVE | Severity | Package | Installed | Fixed | Disposition |
|---|---|---|---|---|---|
| CVE-2026-69247 | HIGH | cryptography | 46.0.5 | 50.0.0 | A — update/compatibility not completed; blocking |
| CVE-2026-69249 | HIGH | cryptography | 46.0.5 | 49.0.0 | A — update/compatibility not completed; blocking |
| GHSA-537c-gmf6-5ccf | HIGH | cryptography | 46.0.5 | 48.0.1 | A — update/compatibility not completed; blocking |
| CVE-2026-27459 | HIGH | pyOpenSSL | 25.3.0 | 26.0.0 | A — update/compatibility not completed; blocking |
| CVE-2026-44431 | HIGH | urllib3 | 2.6.3 | 2.7.0 | A — update/compatibility not completed; blocking |
| CVE-2026-44432 | HIGH | urllib3 | 2.6.3 | 2.7.0 | A — update/compatibility not completed; blocking |
| CVE-2026-97687 | HIGH | urllib3 | 2.6.3 | 2.8.0 | A — update/compatibility not completed; blocking |
| CVE-2026-97689 | HIGH | urllib3 | 2.6.3 | 2.8.0 | A — update/compatibility not completed; blocking |
| CVE-2025-68121 | CRITICAL | stdlib | v1.24.6 | 1.24.13, 1.25.7, 1.26.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2025-61726 | HIGH | stdlib | v1.24.6 | 1.24.12, 1.25.6 | A — update/compatibility not completed; blocking |
| CVE-2025-61729 | HIGH | stdlib | v1.24.6 | 1.24.11, 1.25.5 | A — update/compatibility not completed; blocking |
| CVE-2026-25679 | HIGH | stdlib | v1.24.6 | 1.25.8, 1.26.1 | A — update/compatibility not completed; blocking |
| CVE-2026-27145 | HIGH | stdlib | v1.24.6 | 1.25.11, 1.26.4 | A — update/compatibility not completed; blocking |
| CVE-2026-32280 | HIGH | stdlib | v1.24.6 | 1.25.9, 1.26.2 | A — update/compatibility not completed; blocking |
| CVE-2026-32281 | HIGH | stdlib | v1.24.6 | 1.25.9, 1.26.2 | A — update/compatibility not completed; blocking |
| CVE-2026-32283 | HIGH | stdlib | v1.24.6 | 1.25.9, 1.26.2 | A — update/compatibility not completed; blocking |
| CVE-2026-33811 | HIGH | stdlib | v1.24.6 | 1.25.10, 1.26.3 | A — update/compatibility not completed; blocking |
| CVE-2026-33814 | HIGH | stdlib | v1.24.6 | 1.25.10, 1.26.3 | A — update/compatibility not completed; blocking |
| CVE-2026-33818 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-39820 | HIGH | stdlib | v1.24.6 | 1.25.10, 1.26.3 | A — update/compatibility not completed; blocking |
| CVE-2026-39821 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-39822 | HIGH | stdlib | v1.24.6 | 1.25.12, 1.26.5, 1.27.0-rc.2 | A — update/compatibility not completed; blocking |
| CVE-2026-39836 | HIGH | stdlib | v1.24.6 | 1.25.10, 1.26.3 | A — update/compatibility not completed; blocking |
| CVE-2026-42499 | HIGH | stdlib | v1.24.6 | 1.25.10, 1.26.3 | A — update/compatibility not completed; blocking |
| CVE-2026-42504 | HIGH | stdlib | v1.24.6 | 1.25.11, 1.26.4 | A — update/compatibility not completed; blocking |
| CVE-2026-56853 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-56858 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-56859 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-56860 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-56862 | HIGH | stdlib | v1.24.6 | 1.25.13, 1.26.6, 1.27.0-rc.3 | A — update/compatibility not completed; blocking |
| CVE-2026-78667 | HIGH | stdlib | v1.24.6 | 1.26.9, 1.27.2 | A — update/compatibility not completed; blocking |
| CVE-2026-78669 | HIGH | stdlib | v1.24.6 | 1.26.9, 1.27.2 | A — update/compatibility not completed; blocking |
| CVE-2026-97031 | HIGH | stdlib | v1.24.6 | 1.26.9, 1.27.2 | A — update/compatibility not completed; blocking |

## Reader image discovered by final all-image scan

Current pinned Python3.13 slim/Debian reader runtime: 0 CRITICAL /49 HIGH.
These are not excluded from the gate. Runtime package inventory confirms msgpack/
urllib3 copies under pip/_vendor; pip is a build tool and can be removed fully
(including ensurepip) after dependency installation, rather than ignoring metadata.
Alpine candidate stays on Python3.13; only after reader/SQLite/session/HTTP contracts
and native Pillow checks may it replace the reader base. Owner session/env untouched.

| CVE | Package | Installed | Fixed reported | Classification / next action |
|---|---|---|---|---|
| CVE-2026-76642 | bsdutils | 1:2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | bsdutils | 1:2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | bsdutils | 1:2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | bsdutils | 1:2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-54369 | libacl1 | 2.3.2-2+b1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | libblkid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | libblkid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | libblkid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | libblkid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | liblastlog2-2 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | liblastlog2-2 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | liblastlog2-2 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | liblastlog2-2 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | libmount1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | libmount1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | libmount1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | libmount1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2025-69720 | libncursesw6 | 6.5+20250216-2 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-103111 | libpcre2-8-0 | 10.46-1~deb13u2 | 10.46-1~deb13u3 | A — applicable fixed/build-tool removal or patched runtime; validate/rescan pending |
| CVE-2026-76642 | libsmartcols1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | libsmartcols1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | libsmartcols1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | libsmartcols1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-16742 | libsystemd0 | 257.13-1~deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2025-69720 | libtinfo6 | 6.5+20250216-2 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-16742 | libudev1 | 257.13-1~deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | libuuid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | libuuid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | libuuid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | libuuid1 | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | login | 1:4.16.0-2+really2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | login | 1:4.16.0-2+really2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | login | 1:4.16.0-2+really2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | login | 1:4.16.0-2+really2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | mount | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | mount | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | mount | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | mount | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2025-69720 | ncurses-base | 6.5+20250216-2 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2025-69720 | ncurses-bin | 6.5+20250216-2 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-9538 | perl-base | 5.40.1-6+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-76642 | util-linux | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78408 | util-linux | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78409 | util-linux | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| CVE-2026-78410 | util-linux | 2.41.5-0+deb13u1 | none | B — Debian no fix reported; no exception; native replacement investigation pending |
| GHSA-6v7p-g79w-8964 | msgpack | 1.1.2 | 1.2.1 | A — applicable fixed/build-tool removal or patched runtime; validate/rescan pending |
| CVE-2025-47273 | setuptools | 70.3.0 | 78.1.1 | A — applicable fixed/build-tool removal or patched runtime; validate/rescan pending |
| CVE-2026-97687 | urllib3 | 2.7.0 | 2.8.0 | A — applicable fixed/build-tool removal or patched runtime; validate/rescan pending |
| CVE-2026-97689 | urllib3 | 2.7.0 | 2.8.0 | A — applicable fixed/build-tool removal or patched runtime; validate/rescan pending |

### Reader resolution

The native candidate passed67 tests +2 HTTP contracts +synthetic persistent-session
roundtrip both directions before promotion to the reader Dockerfile. Official
Python3.13.16/Alpine3.24, same pinned application dependencies, native Pillow wheel,
full pip/ensurepip removal (no runtime installer). Actual image scan:49 HIGH→0;
no exception/ignore and no real session used. Old Debian B/no-fix findings above are
not accepted on a production artifact: their distro components are absent in the
validated replacement. This is documented removal/replacement, not a reachability
claim or hidden metadata deletion.


## OpenBao Transit backend — individual findings and resolution

Official OpenBao2.7.1 before:0 CRITICAL/4 HIGH. The MPL2.0 API-only derived
artifact preserves the upstream Transit implementation and rebuilds pinned source
with Go1.27.3 and x/net0.60.0 (locked module checksums; ADR0025). Actual TLS/Raft,
rotation/recovery, non-exportability and verify-only PDF tests passed against this
artifact. Final scan:0 CRITICAL/0 HIGH. No exception or severity suppression.

| Finding | Package | Before | Applicable fix | Classification / verified action |
|---|---|---|---|---|
| CVE-2026-78669 | golang.org/x/net | v0.58.0 | 0.60.0 | A — rebuilt0.60.0, scan clear |
| CVE-2026-78667 | stdlib | v1.27.1 | 1.27.2 | A — rebuilt Go1.27.3, scan clear |
| CVE-2026-78669 | stdlib | v1.27.1 | 1.27.2 | A — rebuilt Go1.27.3, scan clear |
| CVE-2026-97031 | stdlib | v1.27.1 | 1.27.2 | A — rebuilt Go1.27.3, scan clear |
