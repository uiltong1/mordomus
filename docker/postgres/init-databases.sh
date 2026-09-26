#!/bin/sh
# Mordomus — cria um database por serviço (ADR-004: isolamento por serviço)
set -eu

DATABASES="
mordomus_identity
mordomus_maintenance
mordomus_scheduling
mordomus_financial
mordomus_notification
"

for db in $DATABASES; do
    exists=$(psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
                --tuples-only --no-align \
                --command "SELECT 1 FROM pg_database WHERE datname = '${db}'")
    if [ "$exists" != "1" ]; then
        psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
             --command "CREATE DATABASE \"${db}\""
        echo "[mordomus] database criado: ${db}"
    else
        echo "[mordomus] database já existe: ${db}"
    fi
done
