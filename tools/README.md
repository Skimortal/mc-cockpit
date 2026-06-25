# Manhattan LPN-Helfer (Browser-Autopilot)

Userscript, das die LPN-Erstellung im Manhattan-Lagerportal (ALDI/Hofer,
`warehouse-portal-eu.aldi-sued.com`) automatisiert. Gegenstück zu Baustein 1 im
Cockpit (Mail → „LPN vorbereiten" → Manhattan-Code).

**Läuft in der eigenen Browser-Session** (kein Server, keine Zugangsdaten im Repo).
Es **füllt** die Maske und **stoppt vor dem Speichern/Drucken** – der Mensch prüft und
löst aus (PROD-Sicherheit, physische Etiketten).

## Installation
1. Tampermonkey (oder Violentmonkey) in Chrome installieren.
2. `chrome://extensions` → Entwicklermodus an → bei Tampermonkey „Details" →
   **„Benutzerskripts zulassen"** aktivieren (nötig ab Chrome ~120).
3. Tampermonkey → Neues Userscript → Inhalt von `manhattan-lpn-helfer.user.js`
   einfügen → speichern.

## Bedienung
1. Im Cockpit bei einer Radenko-Mail „LPN vorbereiten" → „Manhattan-Code kopieren".
2. Im Portal-Panel (oben rechts) Login einmalig speichern, Code einfügen → „Code laden".
3. „▶ Autopilot starten" – fährt Login → PO-Liste → Build LPN → Mengen-Maske und
   **stoppt mit gefüllter Maske**. Prüf-Hinweise (PO-Abgleich, MHD-Plausibilität,
   Mengen-Konsistenz) erscheinen im Status. Danach **selbst** speichern/drucken.

## Hinweise
- „▶︎ 1 Schritt jetzt" + „🔬 Diagnose" sind Diagnose-/Test-Hilfen.
- Technische Details (Feld-IDs, ExtJS-Auswahl, iFrame-Logik) stehen im Skript-Kopf
  bzw. im Projekt-Memory `lpn-assistent`.
- Offen: Mehr-MHD-Runde-2 (ASN-Feld), optional PDF automatisch an die Cockpit-Aufgabe.
