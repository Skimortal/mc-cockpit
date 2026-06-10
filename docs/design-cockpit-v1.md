# MOST Connect Cockpit — Design v1 (Office Hours, 2026-06-10)

## Kern-Schmerz (verifiziert, ihr lebt ihn täglich)
3 Personen, EIN geteiltes Postfach. Niemand weiß, ob eine Mail schon von jemandem
**übernommen** wurde; Mails **fallen durch** → Aufgaben werden vergessen.
→ Das ist ein **Ownership- + „Nichts-fällt-durch"-Problem**, KEIN CRM-Problem.

## Leitprinzip
> Jede eingehende Mail hat sichtbar einen Zustand: **noch niemand · übernommen von X · erledigt.**
> Nichts kann still im Posteingang verschwinden.

## Entscheidungen (Office Hours)
- **KI-Architektur: Hybrid.** Automatische Triage + Antwort-Entwürfe laufen **eingebaut** (Claude-API,
  serverseitig/automatisch) — das sichert „nichts fällt durch". Cockpit ist **zusätzlich MCP-Server**
  für Ad-hoc-Arbeiten aus Claude Desktop (Suche/Auswertungen/„entwirf Mail an X") ohne dass wir jede
  Funktion selbst-UI bauen.
- **v1-Zuschnitt: voller Team-Workflow** (Posteingang → Aufgabe → besprechen → antworten → erledigt).
  CRM / Anhang-Suche / MCP = **Phase 2**.
- **Board: umschaltbar**, Standard **nach Person** (Unzugewiesen · Aleksandar · Mirjana · Ljubisa),
  Status als Badge; ein Klick → Gruppierung **nach Status**. „Unzugewiesen" = der Stapel, der nie still wachsen darf.

## v1 — was wir bauen
1. **Posteingang-Ansicht**: Liste der Mails, als **Konversationen/Threads** gruppiert; Mails, die schon
   eine Aufgabe sind, klar markiert („→ Aufgabe #123").
2. **1-Klick „Mail → Aufgabe"**: KI erzeugt **Titel + kurze, verständliche Beschreibung** (läuft schon).
   Mail wird als „ist Aufgabe" gekennzeichnet.
3. **Board umschaltbar** (Person/Status), Default Person.
4. **Aufgabe = GitLab-artiges Issue**: Owner zuweisen, Status, **Tags**, **Kommentare** (Verlauf/Diskussion).
5. **Thread-Ansicht an der Aufgabe**: ganzer Mailverlauf der Konversation.
6. **Antwort aus der Aufgabe**: KI-Entwurf → prüfen → senden (SMTP, threaded); ausgehende Mail landet im Thread.
7. **Erledigte Aufgaben**: ausgeblendet, aber **durchsuchbar/auffindbar** (Wissensspeicher).

Stand: 1–3/5 (Foundation, Modell, IMAP, Triage, Board-nach-Status) sind gebaut. v1-Rest:
Board-Umschaltung + „schon Aufgabe"-Markierung + explizite Umwandeln-Aktion + Thread-Ansicht +
Tags/Kommentare + Antwort-aus-der-Aufgabe.

## Phase 2 (nach v1, sauber abgetrennt)
- **MCP-Server**: Cockpit exponiert Tools (search_tasks, search_mail, get_customer, create_task,
  draft_reply, …) → in Claude Desktop einbinden. Power-Use ohne laufende API-Kosten.
- **Kundendaten (CRM-lite)**: dynamische Felder je Firma/Kontakt (z. B. Steuernummer Mladegs),
  gut durchsuchbar; KI-Suche via MCP („Steuernummer von Mladegs").
- **Volltext inkl. Anhängen**: **Meilisearch** (leicht, tippfehlertolerant) + **Apache Tika** für
  Anhang-Text-Extraktion (PDF/Office) → Anhänge durchsuchbar. ES nur, falls Skalierung/Relevanz es verlangt.

## Architektur-Notizen
- **Auto-Triage** via Messenger-Consumer (fetch-mail → triage) = der technische Garant für „nichts fällt durch".
- **Mensch-im-Loop** bei Antworten (immer prüfen vor Senden).
- **Suche:** Meilisearch + Tika (Phase 2). **MCP:** eigener kleiner MCP-Server, der Cockpit-API/DB nutzt.

## Risiken
- KI-Kosten gering (Sonnet 4.6 + Caching) — Budget/Limit setzen.
- KI-Antwortqualität — Mensch-im-Loop.
- Anhang-Extraktion (Tika) = eigener Dienst — Phase 2, nicht v1.
- Bus-Faktor (ein Entwickler) — README/Recovery, später Vaultwarden für Secrets.

## The Assignment (konkret, vor dem Weiterbauen)
**Schreib mir die echte Taxonomie auf** — aus eurer realen Arbeit, nicht geraten:
1. Die **5–8 Tags/Kategorien**, die ihr wirklich verwendet (z. B. Ausschreibung, Muster, Reklamation,
   Labor, Logistik, Rechnung, …).
2. Die **Status**, die ihr wirklich braucht (reichen Offen / In Arbeit / Wartet / Erledigt? Fehlt was?).
3. 1 Satz: Wann gilt eine Aufgabe als „erledigt, aber wichtig zum Nachschlagen"?

Damit baue ich Aufgaben + Board **passgenau** statt nach Annahme.
