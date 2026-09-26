#!/usr/bin/env bash
# Smoke E2E do identity via gateway (T1.0.10 / T1.3.8).
# Pré-requisito: `make up` e o gateway de pé em http://localhost:8080.
# Uso: make smoke   (ou: bin/smoke.sh)
set -uo pipefail
API="http://localhost:8080/api/v1/identity"
pass=0; fail=0
EMAIL="smoke.$(date +%s%N)@mordomus.test"

step() { printf '\n== %s\n' "$1"; }
ok()   { printf '  OK   %s\n' "$1"; pass=$((pass+1)); }
bad()  { printf '  FAIL %s — %s\n' "$1" "$2"; fail=$((fail+1)); }

jqr() {
    python3 -c "
import json, sys
cur = json.load(sys.stdin)
for part in sys.argv[1].split('.'):
    if isinstance(cur, dict) and part in cur:
        cur = cur[part]
        continue
    if isinstance(cur, dict) and 'data' in cur and isinstance(cur['data'], dict) and part in cur['data']:
        cur = cur['data'][part]
        continue
    raise KeyError(part)
print(cur)
" "$1" 2>/dev/null
}

# ---------------------------------------------------------------- 1. registro
step "1. register (novo usuário + residência)"
REG=$(curl -sS -w '\n%{http_code}' -X POST "$API/auth/register" \
  -H 'Content-Type: application/json' -H 'X-Request-Id: smoke-0001' \
  -d "{\"name\":\"Smoke Test\",\"email\":\"$EMAIL\",\"password\":\"senha-forte-123\",\"password_confirmation\":\"senha-forte-123\",\"home_name\":\"Casa Smoke\"}")
CODE=$(echo "$REG" | tail -1); BODY=$(echo "$REG" | sed '$d')
[ "$CODE" = "201" ] && ok "201 Created" || bad "register" "status=$CODE body=$BODY"
AT=$(echo "$BODY" | jqr "access_token")
TID=$(echo "$BODY" | jqr "active_tenant")
[ -n "$AT" ] && ok "access_token emitido" || bad "access_token" "vazio"
[ -n "$TID" ] && ok "tenant ativo $TID" || bad "active_tenant" "vazio"

RID=$(curl -sS -D- -o /dev/null "$API/me" -H "Authorization: Bearer $AT" \
  -H 'X-Request-Id: smoke-0001' \
  | tr -d '\r' | awk -F': ' 'tolower($1)=="x-request-id"{print $2}')
[ "$RID" = "smoke-0001" ] && ok "X-Request-Id propagado pelo gateway ($RID)" \
  || bad "X-Request-Id" "recebido=$RID"

# ------------------------------------------------------------------ 2. /me
step "2. GET /me"
ME=$(curl -sS -w '\n%{http_code}' "$API/me" -H "Authorization: Bearer $AT")
CODE=$(echo "$ME" | tail -1); BODY=$(echo "$ME" | sed '$d')
[ "$CODE" = "200" ] && ok "200" || bad "/me" "status=$CODE body=$BODY"
echo "$BODY" | jqr "capabilities" >/dev/null && ok "capabilities presentes" || bad "capabilities" "$BODY"

# ------------------------------------------------------- 3. header forjado
step "3. X-Tenant-ID forjado é ignorado"
FORGED=$(curl -sS -w '\n%{http_code}' "$API/me" -H "Authorization: Bearer $AT" \
  -H 'X-Tenant-ID: 00000000000000000000000000')
CODE=$(echo "$FORGED" | tail -1); BODY=$(echo "$FORGED" | sed '$d')
TEN=$(echo "$BODY" | jqr "active_tenant")
[ "$CODE" = "200" ] && [ "$TEN" = "$TID" ] && ok "escopo continua sendo o do JWT ($TEN)" \
  || bad "X-Tenant-ID" "status=$CODE tenant=$TEN"

# ------------------------------------------------------------ 4. sem token
step "4. sem token → 401"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$API/me")
[ "$CODE" = "401" ] && ok "401" || bad "sem token" "status=$CODE"

# ------------------------------------------------------------ 5. 2º tenant
step "5. criar 2ª residência"
T2=$(curl -sS -w '\n%{http_code}' -X POST "$API/tenants" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT" \
  -d '{"name":"Casa Segunda"}')
CODE=$(echo "$T2" | tail -1); BODY=$(echo "$T2" | sed '$d')
TID2=$(echo "$BODY" | jqr "id")
[ "$CODE" = "201" ] || [ "$CODE" = "200" ] && ok "2ª residência $TID2" || bad "criar tenant" "status=$CODE body=$BODY"

# --------------------------------------------------------- 6. tenant_mismatch
step "6. token do tenant A na rota do tenant B → tenant_mismatch"
CODE=$(curl -sS -o /tmp/opencode/mm.json -w '%{http_code}' \
  -X PATCH "$API/tenants/$TID2" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT" \
  -d '{"name":"Hackeada"}')
ERR=$(jqr "error.code" < /tmp/opencode/mm.json)
[ "$CODE" = "403" ] && [ "$ERR" = "tenant_mismatch" ] && ok "403 tenant_mismatch" \
  || bad "tenant_mismatch" "status=$CODE code=$ERR"

# --------------------------------------------------------- 7. switch-tenant
step "7. switch-tenant para a 2ª residência"
SW=$(curl -sS -w '\n%{http_code}' -X POST "$API/auth/switch-tenant" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT" \
  -d "{\"tenant_id\":\"$TID2\"}")
CODE=$(echo "$SW" | tail -1); BODY=$(echo "$SW" | sed '$d')
AT2=$(echo "$BODY" | jqr "access_token")
TIDN=$(echo "$BODY" | jqr "active_tenant")
[ "$CODE" = "200" ] && [ "$TIDN" = "$TID2" ] && ok "novo JWT com tid=$TIDN" \
  || bad "switch-tenant" "status=$CODE tid=$TIDN body=$BODY"

CODE=$(curl -sS -o /tmp/opencode/ok.json -w '%{http_code}' -X PATCH "$API/tenants/$TID2" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT2" \
  -d '{"name":"Casa Segunda Renomeada"}')
[ "$CODE" = "200" ] && ok "200 após trocar de residência" || bad "PATCH após switch" "status=$CODE"

# -------------------------------------------------------------- 8. convite
step "8. convite + aceite (membership novo)"
INV=$(curl -sS -w '\n%{http_code}' -X POST "$API/tenants/$TID2/invitations" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT2" \
  -d '{"email":"convidada.'$(date +%s)'@mordomus.test"}')
CODE=$(echo "$INV" | tail -1); BODY=$(echo "$INV" | sed '$d')
ITOK=$(echo "$BODY" | jqr "token")
[ "$CODE" = "201" ] || [ "$CODE" = "200" ] && ok "convite criado" || bad "convite" "status=$CODE body=$BODY"

EMAIL2="convidada.$(date +%s)@mordomus.test"
REG2=$(curl -sS -w '\n%{http_code}' -X POST "$API/auth/register" -H 'Content-Type: application/json' \
  -d "{\"name\":\"Convidada\",\"email\":\"$EMAIL2\",\"password\":\"senha-forte-123\",\"password_confirmation\":\"senha-forte-123\",\"home_name\":\"Casa Convidada\"}")
AT2B=$(echo "$REG2" | sed '$d' | jqr "access_token")
ACC=$(curl -sS -w '\n%{http_code}' -X POST "$API/invitations/$ITOK/accept" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT2B")
CODE=$(echo "$ACC" | tail -1); BODY=$(echo "$ACC" | sed '$d')
AT3=$(echo "$BODY" | jqr "access_token")
TID3=$(echo "$BODY" | jqr "active_tenant")
[ -n "$AT3" ] && [ "$TID3" = "$TID2" ] && ok "convidada entrou em $TID3" \
  || bad "aceite de convite" "status=$CODE body=$BODY"

# ----------------------------------------------------------------- 9. RBAC
step "9. member sem members.manage → 403"
CODE=$(curl -sS -o /tmp/opencode/rbac.json -w '%{http_code}' -X POST "$API/tenants/$TID2/invitations" \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AT3" \
  -d '{"email":"x@y.z"}')
ERR=$(jqr "error.code" < /tmp/opencode/rbac.json)
[ "$CODE" = "403" ] && ok "403 $ERR" || bad "RBAC" "status=$CODE code=$ERR"

# ------------------------------------------------------------ 10. rate limit
step "10. rate limit do gateway"
CODES=$(seq 1 300 | xargs -P 32 -I{} curl -sS -o /dev/null -w '%{http_code}\n' "$API/me")
N429=$(printf '%s\n' "$CODES" | grep -c '^429$' || true)
if [ "$N429" -gt 0 ]; then
  ok "429 rate limited ($N429 de 300)"
else
  bad "rate limit" "nenhum 429 em 300 requests paralelos"
fi

# ------------------------------------------------------------ 11. JWKS/health
step "11. gateway /health e JWKS"
C=$(curl -sS -o /dev/null -w '%{http_code}' http://localhost:8080/health)
[ "$C" = "200" ] && ok "/health 200" || bad "/health" "$C"
C=$(curl -sS -o /dev/null -w '%{http_code}' http://localhost:8080/.well-known/jwks.json)
[ "$C" = "200" ] && ok "jwks 200" || bad "jwks" "$C"

printf '\n==> %s ok, %s falhas\n' "$pass" "$fail"
exit "$fail"
