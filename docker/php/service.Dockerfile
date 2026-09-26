# syntax=docker/dockerfile:1
# Mordomus — imagem de um serviço Laravel (contexto = raiz do repositório)
ARG BASE_IMAGE=mordomus-php:8.3
FROM ${BASE_IMAGE}

ARG SERVICE
ENV SERVICE_NAME=${SERVICE}

# dependências (cacheável por mudança de composer.json/lock)
COPY services/${SERVICE}/composer.json services/${SERVICE}/composer.lock /tmp/app/
WORKDIR /tmp/app
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && composer dump-autoload --optimize --no-scripts

# código da aplicação
COPY services/${SERVICE}/ /var/www/html/
WORKDIR /var/www/html
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
               storage/framework/views storage/logs storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
