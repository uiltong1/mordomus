#!/bin/sh
set -e

log() { echo "[mordomus] $*"; }

if [ "${AUTO_COMPOSER:-1}" = "1" ] && [ -f /var/www/html/composer.json ]; then
    if [ ! -f /var/www/html/vendor/autoload.php ] \
        || [ /var/www/html/composer.lock -nt /var/www/html/vendor/autoload.php ] \
        || [ /var/www/html/composer.json -nt /var/www/html/vendor/autoload.php ]; then
        log "composer install..."
        composer install --no-interaction --prefer-dist
    fi
fi

if [ "${WAIT_FOR_DB:-0}" = "1" ]; then
    log "aguardando PostgreSQL em ${DB_HOST:-postgres}:${DB_PORT:-5432}/${DB_DATABASE:-?}..."
    tries=0
    until php -r '
        try {
            new PDO(
                sprintf(
                    "pgsql:host=%s;port=%s;dbname=%s",
                    getenv("DB_HOST") ?: "postgres",
                    getenv("DB_PORT") ?: "5432",
                    getenv("DB_DATABASE") ?: "postgres"
                ),
                getenv("DB_USERNAME") ?: "mordomus",
                getenv("DB_PASSWORD") ?: "",
                [PDO::ATTR_TIMEOUT => 2]
            );
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    '; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            log "ERRO: banco indisponível após 60 tentativas"
            exit 1
        fi
        sleep 1
    done
    log "banco disponível"
fi

for key in rsa_private rsa_public vapid_private vapid_public; do
    [ -f "/run/secrets/$key.pem" ] || continue

    mkdir -p /run/mordomus
    if [ "$key" = "${key%_private}" ]; then
        install -m 640 -o root -g www-data "/run/secrets/$key.pem" "/run/mordomus/$key.pem"
    else
        install -m 644 "/run/secrets/$key.pem" "/run/mordomus/$key.pem"
    fi
done

for dir in storage bootstrap/cache; do
    if [ -d "/var/www/html/$dir" ]; then
        chown -R www-data:www-data "/var/www/html/$dir" 2>/dev/null || true
    fi
done

if [ "$#" -gt 0 ]; then
    log "executando: $*"
    exec "$@"
fi

if [ "${RUN_ROLE:-http}" = "worker" ]; then
    log "iniciando queue worker"
    exec php artisan queue:work "${QUEUE_CONNECTION:-redis}" \
        --queue="${QUEUE_WORKER_QUEUES:-default,mordomus:scheduling:occurrences,mordomus:notification:events}" \
        --sleep=1 --tries=3 --max-time=3600 --memory=256
fi

if [ "${RUN_ROLE:-http}" = "scheduler" ]; then
    log "iniciando scheduler (schedule:work)"
    exec php artisan schedule:work
fi

log "iniciando nginx (8080) e php-fpm (9000)"
nginx -g 'daemon on;'
exec php-fpm -F
