#!/usr/bin/env bash
# Mordomus — verifica GET /health em todos os serviços (critério de aceite T1.1.3)
set -uo pipefail

declare -A TARGETS=(
    [gateway]="http://localhost:8080/health"
    [identity]="http://localhost:8001/health"
    [maintenance]="http://localhost:8002/health"
    [financial]="http://localhost:8003/health"
    [scheduling]="http://localhost:8004/health"
    [notification]="http://localhost:8005/health"
)

ORDER=(gateway identity maintenance financial scheduling notification)

BODY="$(mktemp)"
fail=0

for name in "${ORDER[@]}"; do
    url="${TARGETS[$name]}"
    code="$(curl -s -o "$BODY" -w '%{http_code}' --max-time 5 "$url" 2>/dev/null || echo 000)"
    if [ "$code" = "200" ]; then
        printf '  OK   %-13s %s  %s\n' "$name" "$code" "$(tr -d '\n' < "$BODY" | head -c 160)"
    else
        printf ' FAIL  %-13s %s\n' "$name" "$code"
        fail=1
    fi
done

rm -f "$BODY"
[ "$fail" = "0" ] && echo "==> todos os serviços responderam 200" || echo "==> FALHA em pelo menos um serviço"
exit "$fail"
