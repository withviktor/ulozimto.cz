#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# deploy.sh — nasazení ulozimto.cz na produkční server
#
# Předpoklady:
#   - .env.local existuje v kořeni projektu (viz níže)
#   - Docker a Docker Compose jsou nainstalované
#   - Port 8080 a 9000 jsou volné (nebo už je tento skript spouštěl)
#
# Spuštění:
#   bash bin/deploy.sh
#
# .env.local musí obsahovat:
#   APP_ENV=prod
#   APP_SECRET=<php -r "echo bin2hex(random_bytes(32));">
#   POSTGRES_PASSWORD=<silné_heslo>
#   DATABASE_URL="postgresql://app:<stejné_heslo>@postgres:5432/app?serverVersion=16&charset=utf8"
#   MINIO_KEY=<access_key>
#   MINIO_SECRET=<secret_key>
#   MINIO_PUBLIC_URL=https://cdn.ulozimto.cz
#   RESEND_API_KEY=re_...
#   RESEND_AUDIENCE_ID=...
#   MAILER_DSN=resend+api://re_...@default
#   MAILER_FROM=noreply@ulozimto.cz
#   MAILER_FROM_NAME=ulozimto.cz
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

COMPOSE="docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.local"
APP="$COMPOSE exec -T app"

# ── Barvy pro výstup ──────────────────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

step()  { echo -e "\n${GREEN}▶ $1${NC}"; }
warn()  { echo -e "${YELLOW}⚠ $1${NC}"; }
error() { echo -e "${RED}✗ $1${NC}"; exit 1; }

# ── Kontroly ──────────────────────────────────────────────────────────────────
[ -f ".env.local" ] || error ".env.local nenalezen. Vytvoř ho dle komentáře v tomto skriptu."
command -v docker &>/dev/null || error "Docker není nainstalován."

# Ověřit, že klíčové proměnné v .env.local nejsou placeholder hodnoty
check_env() {
    local val
    val=$(grep -E "^${1}=" .env.local | cut -d= -f2- | tr -d '"' | tr -d "'")
    [ -z "$val" ] || [ "$val" = "changeme" ] && error "${1} není nastaven v .env.local (aktuální hodnota: '${val}'). Nastav reálnou hodnotu."
}
check_env APP_SECRET
check_env POSTGRES_PASSWORD
check_env MINIO_KEY
check_env MINIO_SECRET
check_env RESEND_API_KEY

# ── 1. Git pull ───────────────────────────────────────────────────────────────
step "Stahování nejnovějšího kódu..."
git pull --ff-only

# ── 2. Build ──────────────────────────────────────────────────────────────────
step "Build Docker image..."
$COMPOSE build --pull

# ── 3. Spuštění služeb ────────────────────────────────────────────────────────
step "Spouštění kontejnerů..."
$COMPOSE up -d --remove-orphans

# ── 4. Čekání na PostgreSQL ───────────────────────────────────────────────────
step "Čekání na PostgreSQL..."
attempt=0
until $COMPOSE exec -T postgres pg_isready -U app -d app &>/dev/null; do
    attempt=$((attempt + 1))
    [ $attempt -ge 30 ] && error "PostgreSQL nereaguje po 30 pokusech."
    echo "  ... pokus $attempt/30"
    sleep 2
done
echo "  PostgreSQL připraven."

# ── 5. Composer install ───────────────────────────────────────────────────────
step "Composer install --no-dev..."
$APP composer install --no-dev --optimize-autoloader --no-interaction

# ── 6. Migrace DB ─────────────────────────────────────────────────────────────
step "Doctrine migrace..."
$APP php bin/console doctrine:migrations:migrate --no-interaction --env=prod

# ── 7. Cache warmup ───────────────────────────────────────────────────────────
step "Cache warmup..."
$APP php bin/console cache:warmup --env=prod

# ── 8. MinIO bucket ───────────────────────────────────────────────────────────
step "Nastavení MinIO bucketu..."
$APP php bin/console app:setup-minio --env=prod

# ── 9. Restart workerů (nový kód) ─────────────────────────────────────────────
step "Restart messenger worker..."
$COMPOSE restart worker

# ── Hotovo ────────────────────────────────────────────────────────────────────
echo -e "\n${GREEN}✓ Deployment dokončen!${NC}"
echo ""
echo "  Aplikace běží na: http://localhost:8080"
echo "  MinIO S3 API:     http://localhost:9010"
echo ""
echo "  Další kroky v Nginx Proxy Manager:"
echo "    1. ulozimto.cz     → localhost:8080  (SSL: Let's Encrypt)"
echo "    2. cdn.ulozimto.cz → localhost:9010  (SSL: Let's Encrypt)"
echo ""
echo "  MinIO konzole (admin) — přistup přes SSH tunnel:"
echo "    ssh -L 9001:localhost:9001 user@<server_ip>"
echo "    Pak otevři: http://localhost:9001"
