FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -ri 's/^expose_php.*/expose_php = Off/; s/^display_errors.*/display_errors = Off/; s/^;?session.cookie_httponly.*/session.cookie_httponly = 1/' "$PHP_INI_DIR/php.ini" \
    && printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' > /etc/apache2/conf-available/zz-hardening.conf \
    && a2enconf zz-hardening

WORKDIR /var/www/html
COPY --chown=root:root . /var/www/html

EXPOSE 80
