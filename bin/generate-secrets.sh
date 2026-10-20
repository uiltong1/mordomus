#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS="$ROOT/.secrets"
JWKS_DIR="$ROOT/gateway/jwks"
ENV_FILE="$ROOT/.env"
mkdir -p "$SECRETS" "$JWKS_DIR"
chmod 700 "$SECRETS"

set_env() {
    if grep -q "^$1=" "$ENV_FILE" 2>/dev/null; then
        sed -i.bak "s|^$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
}

if [ ! -f "$ENV_FILE" ]; then
    cat > "$ENV_FILE" <<EOF
# Mordomus — ambiente local (gerado por bin/generate-secrets.sh; NÃO commitar)
POSTGRES_USER=mordomus
POSTGRES_PASSWORD=$(openssl rand -hex 16)
APP_KEY=base64:$(openssl rand -base64 32 | tr -d '\n')
JWT_USER_SECRET=$(openssl rand -hex 48)
EOF
    chmod 600 "$ENV_FILE"
    echo "[secrets] .env criado"
else
    echo "[secrets] .env já existe — mantido"
fi

PRIV="$SECRETS/rsa_private.pem"
PUB="$SECRETS/rsa_public.pem"
if [ ! -f "$PRIV" ]; then
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$PRIV" 2>/dev/null
    openssl pkey -in "$PRIV" -pubout -out "$PUB"
    chmod 600 "$PRIV"
    chmod 644 "$PUB"
    echo "[secrets] par de chaves RS256 gerado"
else
    echo "[secrets] par de chaves RS256 já existe — mantido"
fi

MODULUS_HEX="$(openssl rsa -in "$PRIV" -noout -modulus | cut -d= -f2)"
python3 - "$MODULUS_HEX" "$JWKS_DIR/jwks.json" "$SECRETS/rsa_kid.txt" <<'PY'
import sys, base64, json, hashlib

mod_hex, jwks_path, kid_path = sys.argv[1], sys.argv[2], sys.argv[3]

def b64u(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode()

n = int(mod_hex, 16)
n_bytes = n.to_bytes((n.bit_length() + 7) // 8, "big")
e = 65537
e_bytes = e.to_bytes((e.bit_length() + 7) // 8, "big")

canonical = json.dumps({"e": b64u(e_bytes), "kty": "RSA", "n": b64u(n_bytes)},
                       separators=(",", ":"), sort_keys=True)
kid = b64u(hashlib.sha256(canonical.encode()).digest())

jwks = {"keys": [{"kty": "RSA", "use": "sig", "alg": "RS256", "kid": kid,
                  "n": b64u(n_bytes), "e": b64u(e_bytes)}]}
with open(jwks_path, "w") as fh:
    json.dump(jwks, fh, indent=2)
    fh.write("\n")
with open(kid_path, "w") as fh:
    fh.write(kid + "\n")

print(f"[secrets] JWKS gerado -> {jwks_path}")
print(f"[secrets] kid = {kid}")
PY

KID="$(tr -d '[:space:]' < "$SECRETS/rsa_kid.txt")"
set_env JWT_KID "$KID"

VAPID_PRIV="$SECRETS/vapid_private.pem"
VAPID_PUB="$SECRETS/vapid_public.pem"
if [ ! -f "$VAPID_PRIV" ]; then
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$VAPID_PRIV" 2>/dev/null
    openssl pkey -in "$VAPID_PRIV" -pubout -out "$VAPID_PUB"
    chmod 600 "$VAPID_PRIV"
    chmod 644 "$VAPID_PUB"
    echo "[secrets] par de chaves VAPID P-256 gerado"
else
    echo "[secrets] par de chaves VAPID P-256 já existe — mantido"
fi

VAPID_POINT="$(python3 - "$VAPID_PUB" <<'PY'
import base64
import re
import sys

pem = open(sys.argv[1]).read()
der = base64.b64decode(re.sub(r"-----[^-]+-----|\s", "", pem))

point = der[-65:]

if len(point) != 65 or point[0] != 0x04:
    raise SystemExit("A chave pública VAPID não é um ponto P-256 não comprimido.")

print(base64.urlsafe_b64encode(point).rstrip(b"=").decode())
PY
)"
set_env VITE_VAPID_PUBLIC_KEY "$VAPID_POINT"

echo "[secrets] OK — JWT RS256 + JWKS + VAPID P-256 locais prontos (kid=$KID)"
