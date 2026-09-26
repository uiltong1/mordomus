#!/usr/bin/env bash
# Mordomus — saúde do ambiente (gateway, api, postgres, redis)
set -uo pipefail

fail=0
BODY="$(mktemp)"

check_http() {
    local name="$1" url="$2"
    local code
    code="$(curl -s -o "$BODY" -w '%{http_code}' --max-time 5 "$url" 2>/dev/null || echo 000)"
    if [ "$code" = "200" ]; then
        printf '  OK   %-9s %s  %s\n' "$name" "$code" "$(tr -d '\n' < "$BODY" | head -c 160)"
    else
        printf ' FAIL  %-9s %s\n' "$name" "$code"
        fail=1
    fi
}

# o `api` não publica porta: checa de dentro do container (rede `backend`)
check_api() {
    local body
    body="$(docker compose exec -T api curl -fsS --max-time 5 http://127.0.0.1:8080/health 2>/dev/null || true)"
    if [ -n "$body" ]; then
        printf '  OK   %-9s %s  %s\n' "api" "200" "$(printf '%s' "$body" | tr -d '\n' | head -c 160)"
    else
        printf ' FAIL  %-9s 000\n' "api"
        fail=1
    fi
}

check_cmd() {
    local name="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        printf '  OK   %-9s  %s\n' "$name" "$*"
    else
        printf ' FAIL  %-9s  %s\n' "$name" "$*"
        fail=1
    fi
}

check_http "gateway" "http://localhost:8080/health"
check_api
check_cmd  "postgres" docker compose exec -T postgres pg_isready -U "${POSTGRES_USER:-mordomus}" -d mordomus
check_cmd  "redis"    docker compose exec -T redis redis-cli ping

rm -f "$BODY"
[ "$fail" = "0" ] && echo "==> ambiente saudável" || echo "==> FALHA em pelo menos um componente"
exit "$fail"
