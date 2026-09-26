# syntax=docker/dockerfile:1
# Mordomus — imagem do monólito modular (contexto = raiz do repositório)
ARG BASE_IMAGE=mordomus-php:8.3
FROM ${BASE_IMAGE}

# pacote compartilhado: repositório Composer path em `../packages/php-common`
# resolve para /var/www/packages no container (composer.json fica em
# /var/www/html) — mesma profundidade de `<repo>/api`, de modo que o caminho
# relativo vale no build, no runtime e no host.
COPY packages/ /var/www/packages/

# dependências (cacheável por mudança de composer.json/lock)
COPY api/composer.json api/composer.lock /var/www/html/
WORKDIR /var/www/html
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && composer dump-autoload --optimize --no-scripts

# código da aplicação
COPY api/ /var/www/html/
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
               storage/framework/views storage/logs storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
