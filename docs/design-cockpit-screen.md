# Cockpit — All-in-one-Screen (Design-Plan, Layout A)

Ein Screen, alles sichtbar: **Posteingang (Konversationen) links · Aufgaben-Kanban rechts**,
Detail (Thread + KI-Antwort) als Panel rechts eingeblendet.

## Annotierter Mockup
```
┌ MOST Connect Cockpit ───────────────  [🔍 Suche]   Gruppierung:[Person▾]  ⟳  Aleks ▾ ┐
├──────────────────────────┬────────────────────────────────────────────────────────────┤
│ POSTEINGANG              │ AUFGABEN — Kanban                                          │
│ [Alle][Ungelesen][o.Aufg]│ ┌Unzugew.┐ ┌Aleksandar┐ ┌Mirjana ┐ ┌Ljubisa ┐            │
│                          │ │□ Muster │ │□ QM-Prüf │ │□ Etikett│ │□ Widerspr│           │
│ ● Hofer SI        2 ✉    │ │  Ketchup│ │ [Labor]  │ │[Etikett]│ │ [ASN]   │           │
│   Mehraufwand…  →Aufg.#11│ │         │ │ ⏱12.08  │ │         │ │         │            │
│ ● Aldi Süd        1 ✉    │ │         │ │          │ │         │ │         │            │
│   Tray-Etikett   [→Aufg.]│ │         │ │          │ │         │ │         │            │
│   Mirjana                │ └─────────┘ └──────────┘ └─────────┘ └─────────┘            │
│   PPWR Bakery     2 ✉    │                                                            │
│   →Aufg.#2               │ Karte/Mail anklicken → Detail-Panel (rechts):              │
│   Rechnung Claud  1 ✉    │  • Thread (ganze Konversation)                            │
│                          │  • Zuweisen · Status · Tags · Kommentare                  │
│                          │  • Antwort: ✨KI-Entwurf → prüfen → Senden                 │
└──────────────────────────┴────────────────────────────────────────────────────────────┘
```

## Regionen
1. **Topbar:** Logo · globale Suche (Meilisearch, Phase 2) · Gruppierungs-Umschalter (Person/Status) ·
   „⟳ Abrufen" (manueller Mail-Fetch) · Benutzermenü.
2. **Posteingang (links, ~360 px, resizable):** Liste von **Konversationen** (nicht Einzelmails).
   - Zeile: Kunde/Absender · Betreff (gekürzt) · letzte Zeit · ✉-Anzahl (Thread) · ungelesen-Punkt ·
     Status-Chip: **„Neu"** / **„→ Aufgabe #12"** (umgewandelt, verlinkt) / **„erledigt"**.
   - Filter-Tabs: **Alle · Ungelesen · ohne Aufgabe** (Letzteres = der Stapel, der nie liegenbleiben darf).
   - Hover-Aktion: **„→ Aufgabe"** (1-Klick-Umwandeln) · Klick öffnet Thread im Detail-Panel.
   - Umgewandelte Konversationen klar markiert + zeigen Zuständigen.
3. **Kanban (rechts):** Default **nach Person** (Unzugewiesen · je User), Umschalter → nach Status.
   - Karte: Titel · Typ/Tags-Badges · Priorität · Frist · Absender. Zuweisen per Dropdown (später Drag).
4. **Detail-Panel (rechts eingeblendet, ~560 px):** für Konversation ODER Aufgabe.
   - Thread (Mails chronologisch) · bei Aufgabe zusätzlich: Zuweisen/Status/**Tags**/**Kommentare** ·
     **Antwort** (KI-Entwurf → senden, threaded). Von Aufgabe ↔ Thread immer verlinkt.

## Schlüssel-Interaktionen
- **Neue Mail** (Auto-Triage) → erscheint links; falls Aufgabe erzeugt → Karte im Kanban + Zeile „→ Aufgabe #".
- **Mail → Aufgabe:** Button → KI-Titel/Beschreibung → Karte in „Unzugewiesen" (oder vorgeschlagene Person) →
  Konversationszeile markiert.
- **Zuweisen:** Dropdown (Phase später: Drag in Personen-Spalte).
- **Antworten:** aus Thread/Aufgabe (KI-Entwurf + Senden) — gebaut.
- **Erledigt:** Aufgabe → raus aus dem Board, bleibt durchsuchbar (Wissensspeicher).

## Zustände (nicht vergessen)
Leerer Posteingang · Laden · keine Aufgaben in Spalte · Konversation mit 1 vs. vielen Mails ·
langer Thread (scrollbar) · Senden ok/Fehler · unzugewiesen-Badge (Zähler) als Alarm.

## Responsive
Schmaler Screen: Posteingang einklappbar (Toggle), Detail-Panel als Vollbild-Overlay.

## Visueller Stil (Default-Vorschlag)
Ruhig, dicht, „Werkzeug nicht Marketing": Slate/Indigo (wie aktuell), klare Badges je Typ/Status,
viel Weißraum in Karten, Monospace nur für IDs/Zahlen. Polish via `design-shotgun`, falls gewünscht.
