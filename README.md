# MOST Connect Cockpit

Eigenes, schnelles Tool für die MOST Connect KG: Aufgaben entstehen aus eingehenden E-Mails
(KI-Triage), werden zugewiesen und direkt aus der Aufgabe beantwortet. Ersetzt EspoCRM + FreeScout.

Läuft (geplant) unter **https://crm.most-connect.com** auf dem Server `quigon`.

## Stack
- **Backend:** Symfony 7 · API Platform · Doctrine ORM · PostgreSQL 16 · LexikJWT · Messenger ·
  Symfony Mailer (SMTP) · `webklex/php-imap` (IMAP) · Claude PHP-SDK (`anthropic-ai/sdk`)
- **Frontend:** Vue 3 + Vite + TypeScript + Tailwind (SPA)
- **Suche:** Meilisearch
- **Mail:** direkt per IMAP/SMTP (kein FreeScout) — Postfächer office@/support@hdv-stojakovic.at (World4You)

## Lokal entwickeln

```bash
cd docker-dev
docker compose up -d --build
# Backend/API:   http://localhost:8090/api
# Postgres:      localhost:55432 (cockpit/cockpit)
# Meilisearch:   http://localhost:7700  (Key: devmasterkey)
```

Composer/Console im Container:
```bash
docker compose exec php composer <...>
docker compose exec php php bin/console <...>
```

## Status
P1 (MVP) im Aufbau: E-Mail → Aufgabe (KI-Triage) + Antwort aus der Aufgabe.
Plan: siehe `~/.claude/plans/golden-chasing-adleman.md`.
