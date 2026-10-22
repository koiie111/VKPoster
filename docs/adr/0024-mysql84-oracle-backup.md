# ADR 0024 — MySQL 8.4 LTS and matching Oracle backup clients

Date: 2026-10-21. Accepted for local production preparation; actual server cutover is owner19.8.

## Decision and compatibility evidence

Production/test baseline is Oracle MySQL8.4.11, official image pinned by digest in
`docker/mysql/Dockerfile`. The minimal derived server removes unused `mysql-shell`
(not mysql/mysqldump/mysqld), and rebuilds the same gosu1.19 from checksum-pinned
source using Go1.27.3. This fixes the reported Go/Python findings without suppressions.
Server binary and storage engine remain Oracle's original8.4.11.

The earlier failure was the MariaDB dump client issuing removed SHOW MASTER STATUS,
not an application incompatibility. `BackupService` now requires the matching Oracle
CLI bundle and uses `--source-data=2 --single-transaction --flush-logs`, excludes GTID
purging for restricted logical restore, and never falls back to MariaDB. Credentials
are private temporary option files, absent from argv/logs. Production TCP uses
VERIFY_IDENTITY; same-host private Unix socket remains the production Compose default.

`mysql`, `mysqldump`, `mysqlbinlog`8.4.11 run as separate child processes in ops/dev,
not in the web runtime. The official signed RPM is checked with Oracle's image-supplied
public key and a pinned SHA256 for each CPU architecture. The bundle contains its
complete matching glibc loader/dependency set in /opt/mysql; its wrapper explicitly
selects that loader. Native Alpine PHP and its musl extensions remain unchanged.
No global glibc replacement, gcompat fallback or host-installed client is used.
The same bundle is independently built in the PHP Dockerfile: CI needs no mutable
local Docker tag or separately published private build dependency.

Verified on isolated fixtures: fresh schema,44→46 upgrade, supported rollback and
replay, full PHP suite, encrypted consistent snapshot/integrity verification/restricted
restore, closed binlog encryption/decryption and matching Oracle binlog row-event
replay. Cold-copy8.0.46→8.4.11 engine upgrade preserves existing schema/data, Cyrillic/
emoji and UTC connection semantics. The original cold8.0 volume remains a valid
recovery source during the test; an in-place engine dictionary downgrade is not used.
Actual production Compose readiness, workers/scheduler, and four-worker10k publication
run pass (all10000 sent; p9551.62s, p9954.36s). This is synthetic capacity evidence,
not real social-provider latency or a production deploy.

## Cutover and rollback

Do not run the new engine blindly against an owner's old volume. Stop writes,
verify encrypted backup and replay on an isolated target, preserve the old release,
client and cold source volume, perform the reviewed engine upgrade and validate
application/readiness before accepting traffic. Do not run8.0 against upgraded8.4
files. A rollback restores the untouched old volume/backup and accounts for writes
since cutover. The release runner deliberately does not automatically replace MySQL.
The existing local owner8.0 containers/volumes were not upgraded by this work.

Daily SQL snapshots and hourly rotated/archived binlogs require separately provisioned
backup/restore accounts and offsite delivery. RPO≤1hour is a target only when enabled
rotation, archive delivery and alarms are operational. With binlogs disabled, RPO is
only the last verified daily snapshot; no hourly claim is made. RTO is measured in
owner19.8 on the production-sized isolated restore, not inferred from tiny fixtures.
Keep backup key and crypto backend recovery material separately from encrypted data.

## Official references

- https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html
- https://dev.mysql.com/doc/refman/8.4/en/mysqlbinlog.html
- https://dev.mysql.com/doc/refman/8.4/en/upgrade-paths.html
- https://github.com/docker-library/mysql
- https://github.com/tianon/gosu/tree/1.19
