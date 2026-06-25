# MOST Connect Cockpit — MCP-Server

Verbindet **Claude Desktop** (oder einen anderen MCP-Client) mit dem Cockpit.
Der Server meldet sich **als ein konkreter Benutzer** an der Cockpit-API an — damit gelten
dieselben Sichtbarkeitsregeln wie in der App (globale Postfächer für alle, persönliche nur
für den Besitzer; Aufgaben für alle).

## Einrichtung

1. Abhängigkeiten installieren (einmalig):
   ```bash
   cd ~/wwwroot/mc-cockpit/mcp-server && npm install
   ```
2. In Claude Desktop eintragen — Datei
   `~/Library/Application Support/Claude/claude_desktop_config.json`
   (anlegen, falls nicht vorhanden):
   ```json
   {
     "mcpServers": {
       "most-cockpit": {
         "command": "node",
         "args": ["/Users/aleksmindstream/wwwroot/mc-cockpit/mcp-server/index.mjs"],
         "env": {
           "COCKPIT_URL": "http://localhost:8090",
           "COCKPIT_EMAIL": "aleks@mindstream.at",
           "COCKPIT_PASSWORD": "DEIN-PASSWORT"
         }
       }
     }
   }
   ```
   - **Lokal** (Docker läuft): `COCKPIT_URL` = `http://localhost:8090`
   - **Produktiv** (nach Go-Live): `COCKPIT_URL` = `https://crm.most-connect.com`
3. **Claude Desktop neu starten.** Danach taucht „most-cockpit" unter den Werkzeugen auf.

## Werkzeuge

| Werkzeug | Zweck |
|---|---|
| `cockpit_search` | Globale Suche (Aufgaben, Mails, Kunden) |
| `cockpit_mailboxes` | Sichtbare Postfächer |
| `cockpit_inbox` | Posteingang (Konversationen), optional je Postfach |
| `cockpit_conversation` | Kompletter Mail-Thread einer Konversation |
| `cockpit_mail_to_task` | Konversation → Aufgabe (KI-Zusammenfassung) |
| `cockpit_tasks` | Alle Aufgaben (Board) |
| `cockpit_team` | Team-Mitglieder |
| `cockpit_assign_task` | Aufgabe zuweisen / Zuweisung entfernen |
| `cockpit_set_task_status` | Status setzen (open/in_progress/waiting/done) |
| `cockpit_set_task_tags` | Tags setzen |
| `cockpit_add_task_comment` | Kommentar hinzufügen |
| `cockpit_draft_reply` | KI-Antwortentwurf (sendet nicht) |
| `cockpit_send_reply` | Antwort senden (threaded, SMTP) |
| `cockpit_companies` / `cockpit_company` | Kunden lesen |
| `cockpit_create_company` / `cockpit_update_company` | Kunde anlegen / aktualisieren |
| `cockpit_add_company_field` | Stammdaten-Feld hinzufügen (z. B. Steuernummer) |
| `cockpit_add_company_contact` | Ansprechpartner hinzufügen |
| `cockpit_get_document` | Datei-Inhalt (PDF/Office) als Text zum Lesen/Zusammenfassen; Bilder als Bild |
| `cockpit_get_document_file` | Datei für E-Mail-Anhang: klein → base64, mit `savePath` (oder >0,7 MB) → lokal speichern, gibt `path` zurück |

## Beispiele (in Claude Desktop)
- „Such mir alle Mails zu Hofer und zeig die offenen Aufgaben dazu."
- „Mach aus Konversation 12 eine Aufgabe und weise sie Mirjana zu."
- „Entwirf eine Antwort auf Aufgabe 8 und zeig sie mir, bevor wir senden."
- „Trag bei Mladegs die Steuernummer ATU… als Feld ein."
- „Hol das englische Datenblatt-PDF von Kunde X und fass es zusammen."

## Sicherheit
Das Passwort steht aktuell in der lokalen Claude-Desktop-Konfiguration (nur auf deinem Rechner).
Später ersetzen wir das durch ein **persönliches API-Token** statt des Passworts.
