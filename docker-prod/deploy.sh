#!/bin/bash
# Robustes Prod-Deploy für mc-cockpit (auf quigon ausführen).
# git pull -> Frontend bauen -> Container neu -> Composer -> Migration -> Cache -> Healthcheck.
set -euo pipefail

ROOT=/srv/most-connect.com/mc-cockpit
cd "$ROOT"

echo "== git pull =="
git pull --ff-only

echo "== Frontend bauen =="
docker run --rm -v "$ROOT/frontend":/app -w /app node:22-alpine sh -c "npm ci && npm run build" >/tmp/fe-build.log 2>&1 || { tail -20 /tmp/fe-build.log; exit 1; }
tail -3 /tmp/fe-build.log

cd "$ROOT/docker-prod"
echo "== Container neu bauen/starten =="
docker compose up -d --build php web mailsync

echo "== Composer (nur Prod-Deps) =="
docker compose exec -T php composer install --no-dev --optimize-autoloader --no-interaction >/tmp/composer.log 2>&1 || { tail -20 /tmp/composer.log; exit 1; }

echo "== Migration =="
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction

echo "== Cache leeren =="
docker compose exec -T php php bin/console cache:clear

echo "== Healthcheck =="
code=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: crm.most-connect.com" https://localhost --insecure || echo "000")
echo "crm.most-connect.com -> HTTP $code"
[ "$code" = "200" ] || { echo "WARNUNG: Healthcheck != 200"; exit 1; }
echo "== Deploy OK =="
