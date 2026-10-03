# syntax=docker/dockerfile:1
# Stages: base (runtime) -> dev (pcov, composer, tools) -> vendor (prod deps) -> prod (opcache, no dev deps).
FROM php:8.3-fpm-bookworm AS base

ARG TAILWIND_VERSION=3.4.17
ARG TAILWIND_SHA256_AMD64=7d24f7fa191d2193b78cd5f5a42a6093e14409521908529f42d80b11fde1f1d4
ARG TAILWIND_SHA256_ARM64=69b1378b8133192d7d2feb12a116fa12d035594f58db3eff215879e4ad8cf39b
ARG TARGETARCH

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
        libmagickwand-dev libsodium-dev unzip git curl ca-certificates \
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
RUN pecl install pcov && docker-php-ext-enable pcov
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/50-app.ini
COPY docker/php/php-dev.ini /usr/local/etc/php/conf.d/60-app-dev.ini
USER app

# ---- vendor (prod dependencies only) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---- prod ----
FROM base AS prod
COPY docker/php/php.ini /usr/local/etc/php/conf.d/50-app.ini
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/60-app-prod.ini
COPY --chown=app:www-data . /var/www/html
COPY --from=vendor --chown=app:www-data /app/vendor /var/www/html/vendor
USER app
