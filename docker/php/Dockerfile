# syntax=docker/dockerfile:1
# Debian/glibc build + dev -> vendor/app-build -> Alpine prod/web/ops (ADR 0021).
FROM mysql:8.4.11@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364 AS client-rpm
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
FROM mysql:8.4.11@sha256:0f51e63e537a1f176423fa03ef559aabe6fa4372a53160a44bc7e75da7d15364 AS client-build
COPY --from=extract /extract/usr/bin/mysqlbinlog /usr/bin/mysqlbinlog
RUN set -eux; mkdir -p /opt/mysql/bin /opt/mysql/lib; \
    for binary in mysql mysqldump mysqlbinlog; do \
      cp /usr/bin/$binary /opt/mysql/bin/; \
      ldd /usr/bin/$binary | awk '/=> \// {print $3} /^[[:space:]]*\// {print $1}' | while read lib; do cp -L "$lib" /opt/mysql/lib/; done; \
    done; \
    printf '%s\n' '#!/bin/sh' 'exec /opt/mysql/lib/ld-linux*.so.* --library-path /opt/mysql/lib /opt/mysql/bin/"$(basename "$0")" "$@"' > /opt/mysql/client; \
    chmod 0755 /opt/mysql/client; \
    for binary in mysql mysqldump mysqlbinlog; do \
      /opt/mysql/lib/ld-linux*.so.* --library-path /opt/mysql/lib /opt/mysql/bin/$binary --version; \
    done

FROM php:8.3-fpm-trixie@sha256:ed67b5fcd7600614dd651621a04214fbe16d373262cb31eb50bc224c18cf0e88 AS base

ARG TAILWIND_VERSION=3.4.17
ARG TAILWIND_SHA256_AMD64=7d24f7fa191d2193b78cd5f5a42a6093e14409521908529f42d80b11fde1f1d4
ARG TAILWIND_SHA256_ARM64=69b1378b8133192d7d2feb12a116fa12d035594f58db3eff215879e4ad8cf39b
ARG TARGETARCH

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
        libmagickwand-dev libsodium-dev ffmpeg fonts-dejavu-core unzip git curl ca-certificates \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl gd exif zip opcache sodium pcntl \
    && pecl install redis imagick \
    && docker-php-ext-enable redis imagick \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Tailwind standalone CLI (no Node). The glibc build is why the image is Debian, not Alpine (see docs/adr/0002).
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        arm64) arch=arm64; sha="$TAILWIND_SHA256_ARM64" ;; \
        *) arch=x64; sha="$TAILWIND_SHA256_AMD64" ;; \
    esac; \
    curl -fsSL -o /usr/local/bin/tailwindcss \
        "https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/tailwindcss-linux-${arch}"; \
    echo "${sha}  /usr/local/bin/tailwindcss" | sha256sum -c -; \
    chmod +x /usr/local/bin/tailwindcss

RUN groupmod -g 1000 www-data 2>/dev/null; \
    useradd -u 1000 -g www-data -m -s /bin/bash app 2>/dev/null || true; \
    mkdir -p /var/www/html && chown app:www-data /var/www/html
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
WORKDIR /var/www/html

# ---- dev ----
FROM base AS dev
COPY --from=client-build /opt/mysql /opt/mysql
RUN for binary in mysql mysqldump mysqlbinlog; do ln -s /opt/mysql/client /opt/mysql/$binary; done \
    && mkdir -p /etc/pki/tls/certs \
    && ln -s /etc/ssl/certs/ca-certificates.crt /etc/pki/tls/certs/ca-bundle.crt
RUN pecl install pcov && docker-php-ext-enable pcov
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/50-app.ini
COPY docker/php/php-dev.ini /usr/local/etc/php/conf.d/60-app-dev.ini
USER app

# ---- vendor (prod dependencies only) ----
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---- prod ----
FROM base AS app-build
COPY docker/php/php.ini /usr/local/etc/php/conf.d/50-app.ini
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/60-app-prod.ini
COPY --chown=app:www-data composer.json composer.lock tailwind.config.js /var/www/html/
COPY --chown=app:www-data bin /var/www/html/bin
COPY --chown=app:www-data config /var/www/html/config
COPY --chown=app:www-data database /var/www/html/database
COPY --chown=app:www-data public /var/www/html/public
COPY --chown=app:www-data resources /var/www/html/resources
COPY --chown=app:www-data scripts /var/www/html/scripts
COPY --chown=app:www-data src /var/www/html/src
COPY --chown=app:www-data templates /var/www/html/templates
COPY --from=vendor --chown=app:www-data /app/vendor /var/www/html/vendor
RUN sh scripts/build-css.sh \
    && mkdir -p storage/logs storage/cache/twig storage/tmp storage/media \
    && chown -R app:www-data storage
USER app

# ---- minimal serving runtime: no Tailwind/build toolchain (ADR 0021) ----
FROM php:8.3-fpm-alpine3.24@sha256:454b11c8907e32878ce92e87b13c14e1bbe6e1b10f4f96d4a19c9b62ec675d41 AS prod
RUN apk add --no-cache ca-certificates ffmpeg font-dejavu \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS pax-utils icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev imagemagick-dev libsodium-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl gd exif zip opcache sodium pcntl \
    && pecl install redis imagick \
    && docker-php-ext-enable redis imagick \
    && runDeps="$(scanelf --needed --nobanner --recursive /usr/local/lib/php/extensions | awk '{ n = split($2, a, ","); for (i=1; i<=n; i++) print "so:" a[i] }' | sort -u)" \
    && apk add --no-cache --virtual .runtime-deps $runDeps \
    && apk del .build-deps \
    && rm -rf /tmp/pear \
    && adduser -D -u 1000 -G www-data app
RUN apk add --no-cache imagemagick \
    && mkdir -p /usr/share/fonts/truetype \
    && ln -s /usr/share/fonts/dejavu /usr/share/fonts/truetype/dejavu \
    && test -f /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
COPY --from=app-build --chown=app:www-data /var/www/html /var/www/html
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/50-app.ini
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/60-app-prod.ini
WORKDIR /var/www/html
RUN php -r 'foreach (["pdo_mysql", "intl", "gd", "imagick", "redis", "sodium", "zip", "exif", "pcntl", "Zend OPcache"] as $e) { if (!extension_loaded($e)) exit(1); }'
RUN php -r '$image = new Imagick(); $image->newImage(2, 2, "white"); foreach (["PNG", "JPEG", "WEBP", "GIF"] as $format) { $image->setImageFormat($format); if ($image->getImageBlob() === "") exit(1); }'
USER app

# ---- web (nginx with the public files baked in; PHP runs in the `app` service) ----
FROM nginx:stable-alpine@sha256:970364228b1e4f932abc22d321932816782cca8a20b85a77552a7cfe9acfb3b5 AS web
# Apply supported Alpine security fixes absent from the pinned upstream base.
RUN apk upgrade --no-cache
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public

# Optional operational image; database clients are absent from the web runtime.
FROM prod AS ops
USER root
COPY --from=client-build /opt/mysql /opt/mysql
RUN for binary in mysql mysqldump mysqlbinlog; do ln -s /opt/mysql/client /opt/mysql/$binary; done \
    && mkdir -p /etc/pki/tls/certs \
    && ln -s /etc/ssl/certs/ca-certificates.crt /etc/pki/tls/certs/ca-bundle.crt \
    && /opt/mysql/mysql --version && /opt/mysql/mysqldump --version && /opt/mysql/mysqlbinlog --version
USER app
