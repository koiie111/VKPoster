# syntax=docker/dockerfile:1
# Official server, same gosu source with a patched Go runtime; no third-party DB.
FROM golang:1.27.3-alpine@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS gosu
ENV CGO_ENABLED=0 GOTOOLCHAIN=local GOMAXPROCS=2
ADD --checksum=sha256:cd9719b775dbfedae53923c9b0dc792b66d42c51e0b36652ed6f747fbadc0164 https://codeload.github.com/tianon/gosu/tar.gz/refs/tags/1.19 /tmp/gosu.tar.gz
RUN mkdir /build && tar -xzf /tmp/gosu.tar.gz --strip-components=1 -C /build
WORKDIR /build
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build go build -mod=readonly -trimpath -ldflags="-s -w" -o /gosu .

FROM mysql:8.4.11@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364 AS server
# mysqlsh is not the mysql CLI, server, dump or binlog tool. It is unused here.
RUN microdnf remove -y mysql-shell && microdnf clean all
COPY --from=gosu /gosu /usr/local/bin/gosu
RUN gosu mysql id && mysqld --version && mysqldump --version

# A version-matched Oracle CLI bundle, never installed on the production host.
FROM server AS client-rpm
ARG TARGETARCH
ADD --checksum=sha256:cfa858fe0325311ca7c54644ec1d839498fc2ad2205dbe9ecaecfe113bf83744 https://repo.mysql.com/yum/mysql-8.4-community/el/9/aarch64/mysql-community-client-8.4.11-1.el9.aarch64.rpm /tmp/client.arm64.rpm
ADD --checksum=sha256:2f1e8317c557363dd1554b29a9f6d69403802dc623fe72d9995f20f542771bb1 https://repo.mysql.com/yum/mysql-8.4-community/el/9/x86_64/mysql-community-client-8.4.11-1.el9.x86_64.rpm /tmp/client.amd64.rpm
RUN set -eux; case "$TARGETARCH" in \
    arm64) arch=aarch64; sha=cfa858fe0325311ca7c54644ec1d839498fc2ad2205dbe9ecaecfe113bf83744 ;; \
    amd64) arch=x86_64; sha=2f1e8317c557363dd1554b29a9f6d69403802dc623fe72d9995f20f542771bb1 ;; \
    *) exit 1 ;; esac; \
    cp /tmp/client.$TARGETARCH.rpm /tmp/client.rpm; \
    echo "$sha  /tmp/client.rpm" | sha256sum -c -; \
    rpm --import /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql; \
    rpmkeys --checksig /tmp/client.rpm; \
    rpm2cpio /tmp/client.rpm > /tmp/client.cpio
FROM golang:1.27.3-alpine@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS extract
COPY --from=client-rpm /tmp/client.cpio /tmp/client.cpio
RUN mkdir /extract && cd /extract && cpio -idm < /tmp/client.cpio
FROM server AS client-build
COPY --from=extract /extract/usr/bin/mysqlbinlog /usr/bin/mysqlbinlog
RUN set -eux; mkdir -p /opt/mysql/bin /opt/mysql/lib; \
    for binary in mysql mysqldump mysqlbinlog; do \
      cp /usr/bin/$binary /opt/mysql/bin/; \
      ldd /usr/bin/$binary | awk '/=> \// {print $3} /^[[:space:]]*\// {print $1}' | while read lib; do cp -L "$lib" /opt/mysql/lib/; done; \
    done; \
    cp -L /lib/ld-linux* /opt/mysql/lib/; \
    printf '%s\n' '#!/bin/sh' 'exec /opt/mysql/lib/ld-linux*.so.* --library-path /opt/mysql/lib /opt/mysql/bin/"$(basename "$0")" "$@"' > /opt/mysql/client; \
    chmod 0755 /opt/mysql/client
FROM server AS clients
COPY --from=client-build /opt/mysql /opt/mysql
COPY --from=client-build /usr/bin/mysqlbinlog /usr/bin/mysqlbinlog
RUN mysqlbinlog --version
