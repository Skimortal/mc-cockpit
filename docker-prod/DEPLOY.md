# Deploy: crm.most-connect.com (quigon)

Stack hinter dem zentralen `nginx_proxy` am Netz `webnet`. Web-Container `mc_cockpit_web`
liefert das gebaute Vue-SPA aus und reicht `/api` an PHP-FPM (Symfony) weiter.

## Erstinstallation
```bash
cd /srv/most-connect.com/mc-cockpit/docker-prod
cp .env.example .env   # Secrets setzen (POSTGRES_PASSWORD, MEILI_KEY, APP_SECRET, JWT_PASSPHRASE, ANTHROPIC_API_KEY)

# Frontend bauen
docker run --rm -v "$(cd .. && pwd)/frontend":/app -w /app node:22-alpine sh -c "npm ci && npm run build"

# Stack starten
docker compose up -d --build

# Backend einrichten
docker compose exec -u root php sh -lc 'mkdir -p var && chown -R www-data:www-data var'
docker compose exec php composer install --no-dev --optimize-autoloader
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console lexik:jwt:generate-keypair --no-interaction --overwrite
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console app:search-reindex
# Benutzer anlegen
docker compose exec php php bin/console app:create-user aleks@mindstream.at '<PW>' Aleksandar Stojakovic --admin

# Proxy auf das Cockpit umhängen
cp ../proxy/crm.most-connect.conf /proxy/nginx/conf.d/crm.most-connect.conf
docker exec nginx_proxy nginx -t && docker exec nginx_proxy nginx -s reload
```

## Update (späteres Deploy)
```bash
cd /srv/most-connect.com/mc-cockpit && git pull
docker run --rm -v "$(pwd)/frontend":/app -w /app node:22-alpine sh -c "npm ci && npm run build"
cd docker-prod && docker compose up -d --build
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console cache:clear
```
