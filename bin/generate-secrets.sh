#!/usr/bin/env bash
# Mordomus — gera segredos do ambiente local
#   .secrets/rsa_private.pem   chave RS256 privada (assina o JWT de usuário)
#   .secrets/rsa_public.pem    chave pública
#   gateway/jwks/jwks.json     JWKS servido pelo gateway em /.well-known/jwks.json
#   .env                       APP_KEY, segredo JWT e credenciais do Postgres
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS="$ROOT/.secrets"
JWKS_DIR="$ROOT/gateway/jwks"
mkdir -p "$SECRETS" "$JWKS_DIR"
chmod 700 "$SECRETS"

# ---------------------------------------------------------------- .env --------
ENV_FILE="$ROOT/.env"
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

# ----------------------------------------------------------------- RSA --------
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

# ----------------------------------------------------------------- JWKS -------
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

# thumbprint JWK (RFC 7638) -> kid determinístico
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

# grava/atualiza JWT_KID no .env (o compose usa para assinar tokens do identity)
KID="$(tr -d '[:space:]' < "$SECRETS/rsa_kid.txt")"
if grep -q '^JWT_KID=' "$ENV_FILE" 2>/dev/null; then
    sed -i.bak "s/^JWT_KID=.*/JWT_KID=$KID/" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
else
    printf 'JWT_KID=%s\n' "$KID" >> "$ENV_FILE"
fi

echo "[secrets] OK — JWT RS256 + JWKS locais prontos (kid=$KID)"
