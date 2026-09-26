# syntax=docker/dockerfile:1
# Mordomus — imagem base PHP 8.3 + FPM + nginx embutido (dev/infra)
FROM php:8.3-fpm-alpine

# sqlite3/pdo_sqlite já vêm embutidos na imagem oficial (fonte usa config0.m4,
# incompatível com phpize) — por isso fora da lista.
ARG PHP_EXTS="pdo_pgsql pgsql zip intl bcmath opcache pcntl exif"

RUN apk add --no-cache \
        bash curl git unzip zip tar shadow \
        icu-dev libzip-dev libpng-dev libxml2-dev oniguruma-dev sqlite-dev postgresql-dev \
        linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" ${PHP_EXTS} \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-network $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/pear /var/cache/apk/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/var/www/html/vendor/bin:${PATH}"

# PHP ini
RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'max_execution_time=120'; \
        echo 'date.timezone=America/Sao_Paulo'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/99-mordomus.ini \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.validate_timestamps=1'; \
    } > /usr/local/etc/php/conf.d/10-opcache.ini

# PHP-FPM pool padrão
RUN sed -i 's/^;clear_env = no/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/www.conf

# nginx embutido (HTTP na 8080 -> php-fpm 127.0.0.1:9000)
RUN apk add --no-cache nginx \
    && rm -rf /var/cache/nginx/* /etc/nginx/http.d/default.conf \
    && mkdir -p /run/nginx /var/www/html/public

COPY docker/nginx/service.conf /etc/nginx/http.d/mordomus.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
