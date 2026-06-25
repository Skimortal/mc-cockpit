# Manhattan LPN-Helfer – Chrome-Extension

Browser-Autopilot für die LPN-Erstellung im Manhattan-Lagerportal (ALDI/Hofer).
Gleiche Logik wie das Userscript (`../manhattan-lpn-helfer.user.js`), aber als
unpacked Chrome-Extension: kein Einfügen/Update-Tanz, zuverlässige iFrame-Injection
(`all_frames`), Login lokal in `chrome.storage`.

**Läuft in deiner Browser-Session, füllt die Maske und stoppt vor dem Speichern/Drucken**
(PROD-Kontrollpunkt). Prüf-Checks gegen Radenkos Daten: 🛑 PO-Abgleich (falsche/alte PO),
⚠️ MHD-Plausibilität, Mengen-Konsistenz.

## Installation (einmalig)
1. `chrome://extensions` öffnen → oben rechts **Entwicklermodus** an.
2. **„Entpackte Erweiterung laden"** → diesen Ordner (`tools/manhattan-lpn-extension`) wählen.
3. **Wichtig:** das alte **Tampermonkey-Userscript deaktivieren**, sonst läuft beides doppelt.
4. Manhattan-Tab neu laden → Panel **„🏷️ LPN-Helfer v0.23 · Extension"** oben rechts.

## Bedienung
1. Im Cockpit bei einer Radenko-Mail „LPN vorbereiten" → „Manhattan-Code kopieren".
2. Im Panel: Login einmalig speichern (🔑), Code einfügen → „Code laden".
3. **„▶ Autopilot starten"** → Login → PO-Liste → Build LPN → Mengen-Maske, **stoppt mit
   gefüllter Maske**. Warnungen im Status prüfen, dann **selbst** speichern/drucken.
4. „▶︎ 1 Schritt jetzt" + „🔬 Diagnose" sind Test-/Diagnosehilfen.

## Aufbau
- `manifest.json` – MV3, zwei Content-Scripts (`all_frames`).
- `src/content.js` – gesamte Logik (Panel, Orchestrator, Engine, Checks). Isolierte Welt,
  Speicher über `chrome.storage.local`.
- `src/page-bridge.js` – läuft in der **MAIN world** und spricht `window.Ext` (ExtJS-Grid)
  an; vom Content-Script per `window.postMessage` aufgerufen.

## Updaten
`content.js`/`page-bridge.js` bearbeiten → in `chrome://extensions` bei der Extension auf
**↻ (neu laden)** → Tab neu laden. (Kein Copy-Paste mehr.)

## Offen / Roadmap
- Mehr-MHD-Runde-2 (ASN-Feld der 2. Runde befüllen).
- Optional: fertiges Label-PDF automatisch an die Cockpit-Aufgabe hängen.
- Quelle bleibt mit dem Userscript synchron; Details siehe Projekt-Memory `lpn-assistent`.
