# Veröffentlichung im Chrome Web Store (unlisted)

Ziel: Kollegen installieren per Link (Ein-Klick) und bekommen automatische Updates.
„Unlisted" = nur über den Link auffindbar, nicht öffentlich gelistet.

## 0. Paket bauen
```
bash tools/manhattan-lpn-extension/build.sh
```
→ erzeugt `tools/manhattan-lpn-extension/dist/lpn-helfer.zip` (das wird hochgeladen).

## 1. Entwicklerkonto (einmalig)
1. https://chrome.google.com/webstore/devconsole mit einem **Firmen-Google-Konto** öffnen.
2. Einmalige Registrierungsgebühr **5 USD** zahlen.
   - Falls ihr **Google Workspace** habt: Konto eures Workspace nehmen → dann ist auch
     „Privat (nur eure Organisation)" als Sichtbarkeit möglich (noch geschlossener als unlisted).

## 2. Hochladen
1. „Neues Element" → `dist/lpn-helfer.zip` hochladen.
2. Name/Beschreibung/Icon kommen aus dem Manifest.

## 3. Store-Eintrag ausfüllen
- **Kategorie:** Produktivität / Workflow & Planung
- **Sprache:** Deutsch
- **Symbol 128px:** ist im Paket enthalten.
- **Screenshot (Pflicht, mind. 1, 1280×800 oder 640×400):** einen Screenshot vom
  Panel auf der Manhattan-Seite machen (Autopilot-Panel sichtbar) und hochladen.
- **Kurzbeschreibung:** „Autopilot für die LPN-Erstellung im Manhattan-Lagerportal –
  füllt die Maske, stoppt vor dem Speichern."

## 4. Datenschutz-Tab (wichtig, sonst keine Freigabe)
- **Einziger Zweck:** „Automatisiert das Anlegen von LPNs im Manhattan-Lagerportal
  (ALDI/Hofer) für interne Nutzung."
- **Berechtigungen begründen:**
  - `storage`: speichert die Portal-Zugangsdaten **lokal** im Browser des Nutzers.
  - Host `*.aldi-sued.com`: das Skript muss auf den Portalseiten laufen, um Felder zu füllen.
- **Datennutzung:** „Erhebt Authentifizierungsdaten (Portal-Login). Diese werden
  **ausschließlich lokal** gespeichert (`chrome.storage`), **nicht übertragen**, **nicht
  verkauft**, **nicht an Dritte weitergegeben**." Alle „Verkaufen/Weitergeben"-Felder = NEIN.
- **Datenschutzerklärung-URL:** Falls verlangt, den Text aus `PRIVACY.md` an einer
  erreichbaren URL hosten (z. B. im Cockpit oder als GitHub-Seite) und hier eintragen.

## 5. Sichtbarkeit + Einreichen
- **Sichtbarkeit:** **Nicht gelistet (unlisted)** wählen.
- „Zur Überprüfung einreichen". Review für unlisted dauert meist Stunden bis 1–2 Tage.
- Nach Freigabe: den **Item-Link** aus der Console an die Kollegen schicken → sie klicken
  „Hinzufügen". Fertig.

## 6. Updates ausrollen
1. In `manifest.json` die `version` erhöhen (z. B. 0.24 → 0.25).
2. `bash build.sh` → neues `dist/lpn-helfer.zip`.
3. In der Developer Console „Paket hochladen" → einreichen.
4. Nach Freigabe **aktualisiert Chrome alle Kollegen automatisch** (meist binnen Stunden).

## Hinweis
Die Erweiterung lädt keinen Remote-Code, ist nicht obfuskiert und hat eine eng
begrenzte Host-Berechtigung – das ist review-freundlich. Falls Google rückfragt:
ehrlich angeben (interne Automatisierung des eigenen Portal-Logins, keine Datenweitergabe).
