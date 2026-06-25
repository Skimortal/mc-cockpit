(function () {
  'use strict';

  /* ===== chrome.storage-Cache + GM-Shims (ersetzt GM_* aus dem Userscript) ===== */
  const KEYS = ['lpn_user', 'lpn_pass', 'lpn_job', 'lpn_gi', 'lpn_active', 'lpn_status', 'lpn_lock', 'lpn_logintries', 'lpn_lastlogin'];
  let cache = {};
  function loadState() { return new Promise(function (res) { try { chrome.storage.local.get(KEYS, function (d) { cache = d || {}; res(); }); } catch (e) { res(); } }); }
  try { chrome.storage.onChanged.addListener(function (ch, area) { if (area === 'local') { for (var k in ch) cache[k] = ch[k].newValue; } }); } catch (e) {}
  function GM_getValue(k, def) { return (k in cache && cache[k] !== undefined) ? cache[k] : def; }
  function GM_setValue(k, v) { cache[k] = v; try { var o = {}; o[k] = v; chrome.storage.local.set(o); } catch (e) {} }

  /* ===== ExtJS-Bridge zur MAIN-world ===== */
  let bridgeSeq = 0, extOk = false;
  window.addEventListener('message', function (ev) { var d = ev.data; if (d && d.__lpnBridge === 'resp' && d.action === 'extReachable') extOk = !!d.ok; });
  function extSelectFire(po) { try { window.postMessage({ __lpnBridge: 'req', id: ++bridgeSeq, action: 'extSelect', po: String(po) }, '*'); } catch (e) {} }
  function pingExt() { try { window.postMessage({ __lpnBridge: 'req', id: ++bridgeSeq, action: 'extReachable' }, '*'); } catch (e) {} }

  loadState().then(function () { main(); pingExt(); setInterval(pingExt, 4000); });

  function main() {
  const TAG = '__lpnHelper';
  let tickN = 0; // Herzschlag des Orchestrators (nur Top-Frame)
  let poSelectedFor = null; // welche PO-Zeile in der Liste bereits ausgewählt wurde

  /* ================= geteilte Helfer (laufen in JEDEM Frame) ================= */
  const SEL = {
    lieferort: '[id$=":Case_LPN_With_PO_DeliveryFacility_Menu"]',
    termin:    '[id$=":Case_LPN_With_DO_newASNEstimatedDelivery"]',
    menge:     '[id$=":Case_LPN_With_PO_LPN_Total_Selected_imput_Mask"]',
    anzahl:    '[id$=":numOfLPNsMask"]',
    verfallsd: '[id$=":Case_LPN_With_PO_LPN_Exp_Date_calendar"]',
    charge:    '[id$=":Case_LPN_With_PO_LPN_Lot_FacetHdr_output_param"]',
    checkbox:  '[id^="checkAll_c0_"]',
    gesamtInp: '[id$="Case_LPN_With_DO_OrderQtyDisplay"]',
    asn:       '[id$=":Case_LPN_With_PO_ASN"]',
  };
  const gel = (k) => document.querySelector(SEL[k]);
  const mengeInput = () => gel('menge');
  const hasForm = () => !!mengeInput();
  const txt = (el) => (el && el.textContent || '').replace(/\s+/g, ' ').trim();
  const visible = (el) => !!(el && el.offsetParent !== null);

  function rowInputs() {
    const m = mengeInput(); if (!m) return [];
    const tr = m.closest('tr'); return tr ? [...tr.querySelectorAll('input,select')] : [];
  }
  function readZiel() {
    const m = (document.body && document.body.innerText || '').match(/Ziel:\s*([A-Z]{2}\d{2})/);
    return m ? m[1] : '';
  }
  function readGesamt() {
    const m = mengeInput();
    if (m) { const tr = m.closest('tr'); if (tr) for (const td of tr.querySelectorAll('td')) {
      const mm = txt(td).match(/^([\d.]+)\s*CT$/); if (mm) return mm[1].replace(/\./g, ''); } }
    const inp = gel('gesamtInp'); if (inp && inp.value) { const d = inp.value.replace(/[^\d]/g, ''); if (d) return d; }
    return '';
  }
  // Liest die in Manhattan tatsächlich offene PO (für den Abgleich gegen Radenkos Angabe).
  function readManhattanPo() {
    const body = (document.body && document.body.innerText) || '';
    const m = body.match(/Auftragsnr\.?\s*:?\s*(\d{6,})/i) || body.match(/f[üu]r PO\s*(\d{6,})/i) || body.match(/\b(45\d{8})\b/);
    return m ? m[1] : null;
  }
  // Warnt bei unplausiblem MHD (Vergangenheit / sehr weit weg). iso = YYYY-MM-DD.
  function mhdWarn(iso) {
    try {
      const d = new Date(iso + 'T00:00:00'); if (isNaN(d.getTime())) return 'MHD unlesbar';
      const now = new Date(); now.setHours(0, 0, 0, 0);
      if (d < now) return 'MHD liegt in der VERGANGENHEIT';
      if ((d - now) / (365.25 * 864e5) > 5) return 'MHD >5 Jahre in der Zukunft – plausibel?';
      return null;
    } catch (e) { return null; }
  }
  function setVal(el, val) {
    if (!el) return false;
    const proto = el.tagName === 'SELECT' ? HTMLSelectElement.prototype
      : el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
    try { el.focus(); } catch (e) {}
    setter.call(el, String(val));
    ['keydown', 'input', 'change', 'keyup', 'blur'].forEach((t) => el.dispatchEvent(new Event(t, { bubbles: true })));
    return true;
  }
  function put(el, val, label, filled, missing) {
    if (el && val !== undefined && val !== null && val !== '') { setVal(el, val); filled.push(label); }
    else if (val !== undefined && val !== '') missing.push(label);
  }
  function listFields() {
    const role = (el, r) => ({ role: r, id: el ? el.id : '(nicht gefunden)' });
    const out = [
      role(gel('lieferort'), 'ASN Lieferort'), role(gel('termin'), 'Liefertermin'),
      role(gel('checkbox'), 'Zeilen-Checkbox'), role(gel('menge'), 'Menge/LPN'),
      role(gel('anzahl'), 'Anzahl LPNs'), role(gel('verfallsd'), 'Verfallsd.'), role(gel('charge'), 'Charge'),
    ];
    out.push({ role: '— alle Zeilen-Inputs —', id: rowInputs().map((e) => e.id).join('  |  ') });
    return out;
  }
  function doFill(p) {
    const filled = [], missing = [];
    if (p.check) { const cb = gel('checkbox'); if (cb) { if (!cb.checked) cb.click(); filled.push('Checkbox'); } else missing.push('Checkbox'); }
    put(gel('lieferort'), p.lieferort, 'ASN Lieferort', filled, missing);
    put(gel('termin'), p.termin, 'Liefertermin', filled, missing);
    put(gel('menge'), p.menge, 'Menge/LPN', filled, missing);
    put(gel('anzahl'), p.anzahl, 'Anzahl LPNs', filled, missing);
    put(gel('verfallsd'), p.verfallsd, 'Verfallsd.', filled, missing);
    put(gel('charge'), p.charge, 'Charge', filled, missing);
    if (p.asn) put(gel('asn'), p.asn, 'ASN', filled, missing);
    return { ok: true, filled, missing };
  }

  // Datum/Format
  const pad = (n) => String(n).padStart(2, '0');
  const nowStr = () => { const d = new Date(); return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${String(d.getFullYear()).slice(2)} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
  const verfalls = (iso) => { const a = iso.split('-'); return `${a[2]}.${a[1]}.${a[0].slice(2)}`; };
  const chargeOf = (iso) => { const a = iso.split('-'); return `${a[2]}${a[1]}${a[0]}`; };
  const totalPallets = (job) => (job && job.groups || []).reduce((s, g) => s + (g.pallets || 0), 0);

  // sichtbares Element per Textinhalt (kürzester Treffer = spezifischster)
  function findByText(rx, sel = 'a,button,span,div,td,label') {
    let best = null, len = 1e9;
    for (const el of document.querySelectorAll(sel)) {
      if (!visible(el)) continue;
      let t = txt(el);
      if (!t && el.tagName === 'INPUT') t = (el.value || el.getAttribute('alt') || '').trim(); // Input-Buttons: Text steckt im value
      if (t && rx.test(t) && t.length < len) { best = el; len = t.length; }
    }
    return best;
  }
  // klickbares Eltern-Element (Kacheln/Buttons reagieren oft nicht auf den inneren Text-Knoten)
  function clickableAncestor(el) {
    let n = el;
    for (let i = 0; i < 6 && n; i++) {
      if (n.tagName === 'A' || n.tagName === 'BUTTON' || n.onclick || n.ondblclick ||
        (n.getAttribute && (n.getAttribute('onclick') || n.getAttribute('ondblclick') || n.getAttribute('role') === 'button'))) return n;
      n = n.parentElement;
    }
    return el;
  }
  function fireMouse(t, types) { types.forEach((type) => { try { t.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true })); } catch (e) {} }); }
  function realClick(el) {            // EINFACHklick (Buttons) – genau ein Klick
    if (!el) return false;
    const t = clickableAncestor(el);
    fireMouse(t, ['mouseover', 'mousedown', 'mouseup']);
    try { t.click(); } catch (e) { fireMouse(t, ['click']); }
    return true;
  }
  function dblClick(el) {             // Doppelklick (Kacheln öffnen sich nur so)
    if (!el) return false;
    const t = clickableAncestor(el);
    fireMouse(t, ['mouseover', 'mousedown', 'mouseup', 'click', 'mousedown', 'mouseup', 'click', 'dblclick']);
    try { t.click(); } catch (e) {}
    return true;
  }
  // Vollständige Pointer+Maus-Sequenz – ExtJS-Grid selektiert sonst nicht.
  function richClick(el) {
    if (!el) return false;
    const base = { bubbles: true, cancelable: true, view: undefined, button: 0 };
    const seq = ['pointerover', 'mouseover', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'];
    for (const type of seq) {
      let ev = null;
      try {
        if (type.indexOf('pointer') === 0) ev = new PointerEvent(type, Object.assign({ pointerId: 1, pointerType: 'mouse', isPrimary: true, buttons: type === 'pointerdown' ? 1 : 0 }, base));
        else ev = new MouseEvent(type, Object.assign({ buttons: type === 'mousedown' ? 1 : 0 }, base));
      } catch (e) { try { ev = new MouseEvent(type.replace('pointer', 'mouse'), base); } catch (e2) { ev = null; } }
      if (ev) { try { el.dispatchEvent(ev); } catch (e) {} }
    }
    try { el.click(); } catch (e) {}
    return true;
  }
  // PO-Zelle in der Liste finden (exakter Text, sonst enthält).
  function findPoCell(po) {
    po = String(po);
    let partial = null;
    for (const el of document.querySelectorAll('td,a,span')) {
      if (!visible(el)) continue;
      const t = txt(el);
      if (t === po) return el;
      if (!partial && t.length < 25 && t.indexOf(po) !== -1) partial = el;
    }
    return partial;
  }
  // Status der PO-Zeile lesen (Erstellt/in Arbeit ok; Storniert/Versendet/Abgeschlossen/Teilweise = untypisch).
  function readPoStatus(cell) {
    const row = cell.closest('tr, .x-grid-row, [class*="x-grid-row"]');
    if (!row) return null;
    const m = txt(row).match(/Teilweise\s+vers\w+|Storniert|Versendet|Abgeschlossen|in Arbeit|Erstellt/i);
    return m ? m[0] : null;
  }
  // Elemente LINKS der PO-Zelle in derselben Bildschirm-Zeile (für Auswahl-Element / Diagnose).
  function rowLeftElements(cell) {
    const r = cell.getBoundingClientRect();
    const yc = r.top + r.height / 2;
    const out = [];
    for (const el of document.querySelectorAll('img,input,a,span,div,td')) {
      if (!visible(el)) continue;
      const b = el.getBoundingClientRect();
      if (b.width === 0 || b.height === 0) continue;
      if (yc < b.top || yc > b.bottom) continue;     // gleiche Bildschirm-Zeile
      if (b.left >= r.left) continue;                  // links der PO
      out.push({ el, x: b.left, w: b.width });
    }
    out.sort((a, b) => a.x - b.x);                      // links zuerst
    return out;
  }
  // ExtJS-Auswahl via MAIN-world-Bridge (Content-Script ist isoliert, sieht window.Ext nicht).
  function extReachable() { return extOk; }
  function extSelectRow(po) { extSelectFire(po); return { ok: true, why: 'Ext-Bridge' }; }

  // Auswahl-Element der Zeile. ExtJS-Grid: div.x-grid-row-checker. Sonst Fallback per Position.
  function rowSelectTarget(cell) {
    const tr = cell.closest('tr, .x-grid-row, [class*="x-grid-row"]');
    if (tr) { const ck = tr.querySelector('.x-grid-row-checker') || tr.querySelector('.x-grid-cell-row-checker'); if (ck && visible(ck)) return ck; }
    // ExtJS-Checker (inneres Div bevorzugt) irgendwo auf derselben Bildschirm-Zeile
    const r = cell.getBoundingClientRect(); const yc = r.top + r.height / 2;
    for (const el of document.querySelectorAll('.x-grid-row-checker')) {
      if (!visible(el)) continue; const b = el.getBoundingClientRect();
      if (yc >= b.top && yc <= b.bottom) return el;
    }
    // generischer Fallback: Bild/kleine Zelle links
    const left = rowLeftElements(cell);
    const img = left.find((e) => e.el.tagName === 'IMG');
    if (img) return img.el;
    const small = left.find((e) => e.w < 70);
    return (small || left[0] || {}).el || null;
  }
  // Listet alle iFrames mit Quelle, ob vom Top-Fenster erreichbar (gleiche Herkunft/about:blank) und Inhalt.
  function iframeInfo() {
    const out = [];
    for (const f of document.querySelectorAll('iframe,frame')) {
      let acc = false, has = '';
      try {
        const d = f.contentDocument;
        if (d) {
          acc = true;
          if (d.querySelector('#singleCustomRadio, input[name="caseLPNRadioGroup"]')) has += 'RADIO ';
          if (d.querySelector('[id$=":Case_LPN_With_PO_LPN_Total_Selected_imput_Mask"]')) has += 'FORM ';
          if (/^Erstelle LPN/i.test((d.body && d.body.innerText || '').trim())) has += '';
        }
      } catch (e) { acc = false; }
      const src = (f.src || f.getAttribute('src') || '(kein src)');
      out.push({ src: src.length > 55 ? '…' + src.slice(-55) : src, acc, has: has.trim() });
    }
    return out;
  }
  // Diagnose: was sieht DIESER Frame? (Top + jeder iFrame meldet das zurück)
  function diagFrame() {
    const pw = document.querySelector('input[type=password]');
    const tile = findByText(/Erstelle/i);
    const texts = [];
    for (const el of document.querySelectorAll('a,button,div,td,span,label')) {
      if (!visible(el)) continue;
      const t = txt(el);
      if (t && t.length > 1 && t.length < 40 && !texts.includes(t)) texts.push(t);
      if (texts.length >= 45) break;
    }
    let gmOk = false; try { gmOk = typeof GM_getValue === 'function'; } catch (e) {}
    const S = (fn) => { try { return fn(); } catch (e) { return '?'; } };
    // PO-/Checkbox-Diagnose (für die Liste)
    let poInfo = '';
    const po = S(() => (G.job() || {}).po);
    if (po) {
      const cell = findPoCell(po);
      if (!cell) poInfo = 'PO ' + po + ' Zelle NICHT gefunden';
      else {
        const t = rowSelectTarget(cell);
        const desc = (el) => el ? (el.tagName + '.' + ((el.className || '') + '').split(' ').slice(0, 2).join('.')) : 'KEINS';
        poInfo = 'PO ' + po + ' Zelle=' + cell.tagName + ' · Auswahl-Target=' + desc(t)
          + ' · links: ' + rowLeftElements(cell).slice(0, 5).map((e) => desc(e.el) + '(' + Math.round(e.w) + 'px)').join(' ');
      }
    }
    return {
      url: (location.href || '').slice(0, 95), screen: detectScreen(), gm: gmOk,
      pw: !!(pw && visible(pw)), form: hasForm(),
      erstelleLpn: !!findByText(/Erstelle LPN/i, 'button,a,span,div,input'),
      weiter: !!findByText(/^Weiter/i, 'button,a,input,span,div'),
      tileText: tile ? txt(tile).slice(0, 40) : null,
      active: S(() => G.active()), locked: S(() => G.locked()), tries: S(() => G.tries()), tickN: tickN,
      ext: S(() => extReachable()), iframes: S(() => iframeInfo()), poInfo, texts,
    };
  }

  /* ================= GM-State (über Seitenwechsel hinweg) ================= */
  const G = {
    user: () => GM_getValue('lpn_user', ''), pass: () => GM_getValue('lpn_pass', ''),
    setCreds: (u, p) => { GM_setValue('lpn_user', u); GM_setValue('lpn_pass', p); },
    job: () => { try { return JSON.parse(GM_getValue('lpn_job', 'null')); } catch (e) { return null; } },
    setJob: (j) => GM_setValue('lpn_job', JSON.stringify(j)),
    gi: () => GM_getValue('lpn_gi', 0), setGi: (n) => GM_setValue('lpn_gi', n),
    active: () => GM_getValue('lpn_active', false), setActive: (b) => GM_setValue('lpn_active', b),
    status: () => GM_getValue('lpn_status', ''), setStatus: (s) => GM_setValue('lpn_status', s || ''),
    locked: () => Date.now() < GM_getValue('lpn_lock', 0), lock: (ms) => GM_setValue('lpn_lock', Date.now() + (ms || 1800)),
    tries: () => GM_getValue('lpn_logintries', 0), setTries: (n) => GM_setValue('lpn_logintries', n),
    lastTry: () => GM_getValue('lpn_lastlogin', 0), setLastTry: (t) => GM_setValue('lpn_lastlogin', t),
  };

  /* ================= Autopilot-Engine (läuft in JEDEM Frame) ================= */
  function detectScreen() {
    // App-Screens ZUERST – eine evtl. (versteckte) Passwort-Eingabe auf der Portalseite
    // darf nicht fälschlich als Login gelten.
    if (hasForm()) return 'form';
    // step1 eindeutig am Radio erkennen (PO-Liste liegt noch dahinter im DOM!).
    const r1 = document.querySelector('#singleCustomRadio') || document.querySelector('input[name="caseLPNRadioGroup"]');
    if ((r1 && visible(r1)) || (findByText(/Erstelle Einzelposition LPNs/i) && findByText(/^Weiter/i))) return 'step1';
    if (findByText(/^Erstelle LPN\b/i, 'button,a,span,div,input')) return 'polist';
    if (findByText(/PO\s*[-–—]?\s*Erstelle/i)) return 'home';
    const pw = document.querySelector('input[type=password]');
    if (pw && visible(pw)) return 'login';   // nur echtes, sichtbares Login-Feld
    return 'unknown';
  }
  function prevTextInput(passEl) {
    const ins = [...document.querySelectorAll('input')]; const i = ins.indexOf(passEl);
    for (let k = i - 1; k >= 0; k--) { const t = (ins[k].type || 'text').toLowerCase(); if (t === 'text' || t === '') return ins[k]; }
    return document.querySelector('input[type=text]');
  }
  function findUserField(pass) {
    const c = document.querySelector('input[name*=user i],input[id*=user i],input[name*=login i],input[id*=login i]');
    if (c && (c.type || 'text').toLowerCase() !== 'password' && visible(c)) return c;
    return prevTextInput(pass);
  }
  function submitLogin(pass) {
    ['keydown', 'keypress', 'keyup'].forEach((t) => pass.dispatchEvent(new KeyboardEvent(t, { bubbles: true, key: 'Enter', code: 'Enter', keyCode: 13, which: 13 })));
    const btn = document.querySelector('input[type=image]') || document.querySelector('button[type=submit],input[type=submit]') || findByText(/anmelden|sign ?in|log ?in|einloggen/i, 'button,a,input');
    if (btn) { try { btn.click(); return; } catch (e) {} }
    if (pass.form) { try { pass.form.submit(); } catch (e) {} }
  }
  function fillAndSubmitLogin(creds) {
    const pass = document.querySelector('input[type=password]'); if (!pass) return false;
    setVal(findUserField(pass), creds.u); setVal(pass, creds.p);
    setTimeout(() => submitLogin(pass), 350);
    return true;
  }
  function radioLabel(r) {
    if (r.id) { const l = document.querySelector('label[for="' + CSS.escape(r.id) + '"]'); if (l) return txt(l); }
    const p = r.closest('td,div,label,tr'); return p ? txt(p) : '';
  }
  // Reine Schritt-Logik für DAS aktuelle Dokument (Top ODER iFrame). Kein GM-Zugriff – Daten via ctx.
  function engineActOn(ctx) {
    const sc = detectScreen();
    if (sc === 'form') {
      const job = ctx.job; if (!job || !job.groups) return { screen: sc, acted: false };
      const g = job.groups[ctx.gi] || job.groups[0];
      const tp = totalPallets(job), ges = parseInt(readGesamt() || '0', 10), ziel = readZiel();
      if (!ges || !tp) return { screen: sc, acted: true, done: true, status: '⚠️ Maske erkannt, aber „Gesamt bestellt"/Paletten fehlen – bitte prüfen' };
      const menge = Math.round(ges / tp);
      const r = doFill({ check: true, lieferort: ziel, termin: nowStr(), menge: String(menge), anzahl: String(g.pallets), verfallsd: verfalls(g.mhd), charge: chargeOf(g.mhd) });
      // Kontrolle gegen Manhattan (Radenko schickt manchmal falsche PO/MHD).
      const crit = [], warn = [];
      const mPo = readManhattanPo();
      if (mPo && String(mPo) !== String(job.po)) crit.push('PO-ABGLEICH: Manhattan ' + mPo + ' ≠ Radenko ' + job.po);
      const mw = mhdWarn(g.mhd); if (mw) warn.push(mw + ' (' + verfalls(g.mhd) + ')');
      if (menge * tp !== ges) warn.push('Menge ' + menge + '×' + tp + '=' + (menge * tp) + ' ≠ Gesamt ' + ges + ' (Palettenzahl prüfen)');
      let status = '✓ Maske gefüllt (' + r.filled.join(', ') + (r.missing.length ? ' · fehlt: ' + r.missing.join(', ') : '') + ') – prüfen & SELBST speichern';
      if (crit.length) status = '🛑 ' + crit.join(' · ') + ' — NICHT speichern, prüfen!';
      else if (warn.length) status = '⚠️ ' + warn.join(' · ') + ' — gefüllt, aber prüfen!';
      return { screen: sc, acted: true, done: true, status };
    }
    if (sc === 'step1') {
      const radios = [...document.querySelectorAll('input[type=radio]')];
      const r = radios.find((x) => x.id === 'singleCustomRadio' || /SINGLE.*CUST|CUST.*QTY/i.test(x.value || '') || /Einzelposition/i.test(radioLabel(x)));
      if (r && !r.checked) {
        r.click();
        try { r.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
        return { screen: sc, acted: true, lock: 800, status: 'Radio „Einzelposition" gewählt – weiter…' };
      }
      const w = document.querySelector('[id$=":Case_LPN_Sel_saveButton"]') || findByText(/^Weiter/i, 'button,a,input,span,div');
      if (w) { realClick(w); return { screen: sc, acted: true, lock: 2400, status: 'Weiter zur Mengen-Maske…' }; }
      return { screen: sc, acted: true, status: r ? '„Weiter"-Button nicht gefunden' : 'Radio „Einzelposition" nicht gefunden' };
    }
    if (sc === 'polist') {
      const job = ctx.job; if (!job) return { screen: sc, acted: true, done: true, status: 'Kein PO-Code geladen' };
      const cell = findPoCell(job.po);
      if (!cell) return { screen: sc, acted: true, lock: 1500, status: '⚠️ PO ' + job.po + ' NICHT in der Liste – falsche/alte PO? (sonst filtern/scrollen)' };
      // Schritt 1: Zeile auswählen – zuerst über die Ext-API, sonst Klick-Fallback.
      if (poSelectedFor !== job.po) {
        const status = readPoStatus(cell);
        const bad = status && /Storniert|Versendet|Abgeschlossen|Teilweise/i.test(status);
        const ext = extSelectRow(job.po);
        if (!ext.ok) { const t = rowSelectTarget(cell) || cell; richClick(t); }
        poSelectedFor = job.po;
        const sel = ext.ok ? 'ausgewählt (Ext) ✓' : 'Klick-Fallback – ' + ext.why;
        let msg = 'PO ' + job.po + ': ' + sel + (status ? ' · Status: ' + status : '') + ' – nochmal für „Erstelle LPN"';
        if (bad) msg = '🛑 PO ' + job.po + ' Status „' + status + '" – untypisch, PRÜFEN! (' + sel + ') – nochmal für „Erstelle LPN"';
        return { screen: sc, acted: true, lock: 1000, status: msg };
      }
      // Schritt 2: „Erstelle LPN"
      const btn = findByText(/^Erstelle LPN\b/i, 'button,a,span,div,input');
      if (btn) { realClick(btn); poSelectedFor = null; return { screen: sc, acted: true, lock: 2400, status: 'PO ' + job.po + ' → „Erstelle LPN" geklickt' }; }
      return { screen: sc, acted: true, status: '„Erstelle LPN"-Button nicht gefunden' };
    }
    if (sc === 'home') {
      const t = findByText(/PO\s*[-–—]?\s*Erstelle/i); if (t) { dblClick(t); return { screen: sc, acted: true, lock: 2200, status: 'Öffne „PO – Erstelle LPNs" (Doppelklick)…' }; }
      return { screen: sc, acted: false };
    }
    return { screen: sc, acted: false };
  }

  /* ============ Messaging: Top steuert, jeder Frame führt aus ============ */
  const pending = {}; let seq = 0; let diagBucket = null; let detectBucket = null;
  window.addEventListener('message', (ev) => {
    const d = ev.data; if (!d || d[TAG] !== true) return;
    if (d.kind === 'resp') {
      if (d.result && d.result.__diag) { if (diagBucket) diagBucket.push(d.result); return; }
      if (d.result && d.result.__detect) { if (detectBucket) detectBucket.push(d.result); return; }
      const cb = pending[d.id]; if (cb) { delete pending[d.id]; cb(d.result); } return;
    }
    if (d.kind === 'req') {
      let result = null;
      if (d.action === 'read') { if (hasForm()) result = { ok: true, ziel: readZiel(), gesamt: readGesamt(), fields: listFields() }; }
      else if (d.action === 'fill') { if (hasForm()) result = doFill(d.payload); }
      else if (d.action === 'detect') { result = { __detect: true, screen: detectScreen() }; } // immer antworten
      else if (d.action === 'step') { if (!d.targetScreen || detectScreen() === d.targetScreen) { const r = engineActOn(d.ctx); if (r.screen !== 'unknown') result = r; } }
      else if (d.action === 'diag') { result = Object.assign({ __diag: true }, diagFrame()); } // immer antworten
      if (result && ev.source) ev.source.postMessage({ [TAG]: true, kind: 'resp', id: d.id, result }, '*');
    }
  });
  function allFrames(w) { const out = []; (function walk(win) { for (let i = 0; i < win.frames.length; i++) { const f = win.frames[i]; out.push(f); try { walk(f); } catch (e) {} } })(w); return out; }
  function broadcast(action, payload) {
    return new Promise((res) => {
      const id = ++seq; pending[id] = res;
      if (hasForm()) { const r = action === 'read' ? { ok: true, ziel: readZiel(), gesamt: readGesamt(), fields: listFields() } : doFill(payload); delete pending[id]; return res(r); }
      for (const f of allFrames(window)) { try { f.postMessage({ [TAG]: true, kind: 'req', id, action, payload }, '*'); } catch (e) {} }
      setTimeout(() => { if (pending[id]) { delete pending[id]; res(null); } }, 1500);
    });
  }
  function stepFrames(ctx) {
    return new Promise((res) => {
      const id = ++seq; let done = false;
      pending[id] = (result) => { if (!done) { done = true; res(result); } };
      for (const f of allFrames(window)) { try { f.postMessage({ [TAG]: true, kind: 'req', action: 'step', id, ctx }, '*'); } catch (e) {} }
      setTimeout(() => { if (!done) { done = true; delete pending[id]; res(null); } }, 1200);
    });
  }
  // Screen aller (mit Script bestückten) Kind-Frames einsammeln.
  function detectFrames() {
    return new Promise((res) => {
      detectBucket = [];
      const id = ++seq;
      for (const f of allFrames(window)) { try { f.postMessage({ [TAG]: true, kind: 'req', action: 'detect', id }, '*'); } catch (e) {} }
      setTimeout(() => { const b = detectBucket; detectBucket = null; res(b || []); }, 450);
    });
  }
  // Im Frame mit genau diesem Screen handeln.
  function actFrame(ctx, targetScreen) {
    return new Promise((res) => {
      const id = ++seq; let done = false;
      pending[id] = (r) => { if (!done) { done = true; res(r); } };
      for (const f of allFrames(window)) { try { f.postMessage({ [TAG]: true, kind: 'req', action: 'step', id, ctx, targetScreen }, '*'); } catch (e) {} }
      setTimeout(() => { if (!done) { done = true; delete pending[id]; res(null); } }, 1200);
    });
  }
  // höchstwertigen Screen über Top + Frames bestimmen und dort handeln.
  const SCREEN_PRIO = { form: 5, step1: 4, polist: 3, home: 2, login: 1, unknown: 0 };
  async function bestScreen() {
    const topScreen = detectScreen();
    const frames = await detectFrames();
    let best = { where: 'top', screen: topScreen, p: SCREEN_PRIO[topScreen] || 0 };
    for (const f of frames) { const p = SCREEN_PRIO[f.screen] || 0; if (p > best.p) best = { where: 'frame', screen: f.screen, p }; }
    return best;
  }

  /* ================= Panel (nur Top-Frame) ================= */
  if (window.top !== window) return;

  let job = null, gi = 0, gesamt = '', ziel = '';

  const css = document.createElement('style');
  css.textContent = `
    #lpnh{position:fixed;bottom:16px;right:16px;z-index:2147483647;width:312px;background:#fff;border:1px solid #d8cdc8;border-radius:12px;
      box-shadow:0 10px 30px rgba(0,0,0,.25);font:12px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#2a2320}
    #lpnh .hd{display:flex;align-items:center;gap:6px;padding:8px 10px;background:#414c65;color:#fff;border-radius:12px 12px 0 0;cursor:move}
    #lpnh .bd{padding:10px;display:flex;flex-direction:column;gap:8px;max-height:78vh;overflow:auto}
    #lpnh textarea{width:100%;height:42px;border:1px solid #e0d2cd;border-radius:8px;padding:6px;font:11px monospace;resize:vertical;box-sizing:border-box}
    #lpnh input.f{width:100%;border:1px solid #e0d2cd;border-radius:8px;padding:5px 7px;box-sizing:border-box}
    #lpnh .row{display:flex;gap:6px;align-items:center}
    #lpnh .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#9a8d87}
    #lpnh button{border:0;border-radius:8px;padding:7px 9px;font-weight:600;cursor:pointer}
    #lpnh .pri{background:#eb5d4f;color:#fff;flex:1}
    #lpnh .go{background:#1c7c3a;color:#fff;flex:1}
    #lpnh .stop{background:#c1352b;color:#fff}
    #lpnh .sec{background:#eef0f4;color:#414c65}
    #lpnh .grp{border:1px solid #e6dad6;border-radius:8px;padding:7px}
    #lpnh .nav{display:flex;align-items:center;gap:6px;justify-content:space-between}
    #lpnh details{border:1px solid #eee;border-radius:8px;padding:4px 8px;background:#faf6f4}
    #lpnh summary{cursor:pointer;font-size:11px;color:#414c65}
    #lpnh .st{font-size:11px;min-height:14px}
    #lpnh .ok{color:#1c7c3a}#lpnh .warn{color:#b45309}#lpnh .err{color:#c1352b}
    #lpnh .x{margin-left:auto;cursor:pointer;font-size:16px;opacity:.85}
    #lpnh code{background:#f4eeeb;border-radius:4px;padding:1px 4px}
    #lpnh .dbg{font:10px monospace;white-space:pre-wrap;background:#faf6f4;border:1px solid #eee;border-radius:6px;padding:6px;max-height:140px;overflow:auto}
    #lpnh hr{border:0;border-top:1px solid #efe4df;margin:2px 0}`;
  document.documentElement.appendChild(css);

  const box = document.createElement('div');
  box.id = 'lpnh';
  box.innerHTML = `
    <div class="hd"><b>🏷️ LPN-Helfer</b><span style="font-size:10px;opacity:.7">v0.24 · Extension</span><span class="x" id="lpnh-x">×</span></div>
    <div class="bd">
      <details id="lpnh-set"><summary>🔑 Login (lokal gespeichert)</summary>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px">
          <input class="f" id="lpnh-user" placeholder="Benutzername" autocomplete="off">
          <input class="f" id="lpnh-pass" type="password" placeholder="Passwort" autocomplete="new-password">
          <div class="row"><button class="sec" id="lpnh-save" style="flex:1">Speichern</button><button class="sec" id="lpnh-clear">Löschen</button></div>
          <div style="font-size:10px;color:#9a8d87">Nur in deinem Browser (Tampermonkey), nie woanders.</div>
        </div>
      </details>
      <div class="lbl">Manhattan-Code aus dem Cockpit</div>
      <textarea id="lpnh-code" placeholder='{"v":1,"po":"…","groups":[…]}'></textarea>
      <button class="sec" id="lpnh-load">Code laden</button>
      <div id="lpnh-main" style="display:none;flex-direction:column;gap:8px">
        <div class="grp">
          <div class="row"><b id="lpnh-po"></b><span id="lpnh-pal" style="margin-left:auto;color:#9a8d87"></span></div>
          <div class="nav" id="lpnh-gnav" style="margin-top:5px">
            <button class="sec" id="lpnh-prev">◀</button>
            <div style="text-align:center"><div id="lpnh-gmhd" style="font-weight:600"></div><div id="lpnh-gpal" style="font-size:10px;color:#9a8d87"></div></div>
            <button class="sec" id="lpnh-next">▶</button>
          </div>
        </div>
        <div class="row"><button class="go" id="lpnh-auto">▶ Autopilot starten</button><button class="stop" id="lpnh-stop">⏹</button></div>
        <hr>
        <details><summary>Manuell / Diagnose</summary>
          <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px">
            <div class="row"><div style="flex:1"><div class="lbl">Ziel</div><input class="f" id="lpnh-ziel" placeholder="AD16"></div>
              <div style="flex:1"><div class="lbl">Gesamt bestellt</div><input class="f" id="lpnh-ges" placeholder="3520"></div></div>
            <div id="lpnh-calc" style="background:#f4eeeb;border-radius:8px;padding:7px"></div>
            <div><div class="lbl">ASN (ab 2. MHD-Runde)</div><input class="f" id="lpnh-asn" placeholder="0001046753"></div>
            <div class="row"><button class="sec" id="lpnh-read" style="flex:1">Seite lesen</button><button class="pri" id="lpnh-fill">Maske ausfüllen</button><button class="sec" id="lpnh-fields">🔍</button></div>
            <div class="row"><button class="sec" id="lpnh-scr" style="flex:1">Screen erkennen</button><button class="sec" id="lpnh-step1x" style="flex:1">▶︎ 1 Schritt jetzt</button></div>
            <button class="go" id="lpnh-diag">🔬 Diagnose → kopieren</button>
            <div class="dbg" id="lpnh-dbg" style="display:none"></div>
          </div>
        </details>
        <div class="st" id="lpnh-st"></div>
        <div style="font-size:10px;color:#9a8d87">Speichern &amp; Drucken machst du selbst – nach Kontrolle.</div>
      </div>
    </div>`;
  document.body.appendChild(box);

  const $ = (s) => box.querySelector(s);
  const st = (msg, cls) => { const e = $('#lpnh-st'); e.className = 'st ' + (cls || ''); e.textContent = msg || ''; };

  // Drag
  (function () { const hd = box.querySelector('.hd'); let dx = 0, dy = 0, on = false;
    hd.addEventListener('mousedown', (e) => { if (e.target.id === 'lpnh-x') return; on = true; dx = e.clientX - box.offsetLeft; dy = e.clientY - box.offsetTop; e.preventDefault(); });
    window.addEventListener('mousemove', (e) => { if (!on) return; box.style.left = (e.clientX - dx) + 'px'; box.style.top = (e.clientY - dy) + 'px'; box.style.right = 'auto'; });
    window.addEventListener('mouseup', () => { on = false; }); })();
  $('#lpnh-x').onclick = () => box.remove();

  // Login-Settings
  if (G.user()) $('#lpnh-user').value = G.user();
  if (G.pass()) $('#lpnh-pass').value = '••••••••';
  $('#lpnh-save').onclick = () => {
    const u = $('#lpnh-user').value.trim(); let p = $('#lpnh-pass').value;
    if (p === '••••••••') p = G.pass();
    G.setCreds(u, p); $('#lpnh-pass').value = p ? '••••••••' : ''; st('🔑 Login gespeichert (lokal)', 'ok');
  };
  $('#lpnh-clear').onclick = () => { G.setCreds('', ''); $('#lpnh-user').value = ''; $('#lpnh-pass').value = ''; st('Login gelöscht', 'ok'); };

  function renderGroup() {
    const g = job.groups[gi];
    $('#lpnh-gmhd').textContent = 'MHD ' + verfalls(g.mhd) + '  (Charge ' + chargeOf(g.mhd) + ')';
    $('#lpnh-gpal').textContent = g.pallets + ' Palette(n) · Gruppe ' + (gi + 1) + '/' + job.groups.length;
    $('#lpnh-gnav').style.display = job.groups.length > 1 ? 'flex' : 'none';
    G.setGi(gi); renderCalc();
  }
  function renderCalc() {
    const g = job.groups[gi]; const tp = totalPallets(job);
    const ges = parseInt($('#lpnh-ges').value || gesamt || '0', 10);
    let html;
    if (ges > 0 && tp > 0) {
      const menge = Math.round(ges / tp), sum = menge * tp, ok = sum === ges;
      html = `Menge/LPN = <code>${menge}</code> (${ges} ÷ ${tp})<br>Anzahl LPNs = <code>${g.pallets}</code> · Verfallsd. <code>${verfalls(g.mhd)}</code> · Charge <code>${chargeOf(g.mhd)}</code><br>` +
        (ok ? `<span class="ok">✓ ${menge}×${tp}=${ges}</span>` : `<span class="warn">⚠ ${menge}×${tp}=${sum} ≠ ${ges} (Rest im Portal)</span>`);
    } else html = '<span class="warn">Ziel/Gesamt fehlen – „Seite lesen" oder oben eintragen.</span>';
    $('#lpnh-calc').innerHTML = html;
  }
  $('#lpnh-ges').addEventListener('input', renderCalc);

  $('#lpnh-load').onclick = () => {
    try { job = JSON.parse($('#lpnh-code').value.trim()); if (!job.po || !Array.isArray(job.groups) || !job.groups.length) throw 0; }
    catch (e) { st('⚠️ Code nicht lesbar', 'err'); return; }
    gi = 0; G.setJob(job); G.setGi(0);
    $('#lpnh-po').textContent = 'PO ' + job.po;
    $('#lpnh-pal').textContent = totalPallets(job) + ' Paletten gesamt';
    $('#lpnh-main').style.display = 'flex';
    renderGroup(); st('Geladen. „▶ Autopilot starten" oder manuell.', 'ok');
  };
  $('#lpnh-prev').onclick = () => { gi = (gi - 1 + job.groups.length) % job.groups.length; renderGroup(); };
  $('#lpnh-next').onclick = () => { gi = (gi + 1) % job.groups.length; renderGroup(); };

  $('#lpnh-auto').onclick = () => {
    if (!job) { st('⚠️ Erst Code laden', 'err'); return; }
    G.setJob(job); G.setGi(gi); G.setTries(0); G.setLastTry(0); G.lock(0); G.setActive(true);
    st('▶ Autopilot läuft…', 'ok');
  };
  $('#lpnh-stop').onclick = () => { G.setActive(false); st('⏹ Autopilot gestoppt', 'warn'); };

  $('#lpnh-read').onclick = async () => {
    st('Lese Seite…'); const r = await broadcast('read');
    if (!r || !r.ok) { st('⚠️ Kein LPN-Formular gefunden (bist du auf der Mengen-Maske?)', 'err'); return; }
    ziel = r.ziel || ''; gesamt = r.gesamt || '';
    if (ziel) $('#lpnh-ziel').value = ziel; if (gesamt) $('#lpnh-ges').value = gesamt; renderCalc();
    st(`Gelesen: Ziel ${ziel || '—'}, Gesamt ${gesamt || '—'}`, 'ok');
  };
  $('#lpnh-fields').onclick = async () => {
    const r = await broadcast('read'); const dbg = $('#lpnh-dbg');
    if (!r || !r.fields) { dbg.style.display = 'block'; dbg.textContent = 'Kein Formular gefunden.'; return; }
    dbg.style.display = dbg.style.display === 'none' ? 'block' : 'none';
    dbg.textContent = r.fields.map((f) => f.role + ': ' + f.id).join('\n');
  };
  $('#lpnh-scr').onclick = () => {
    const sc = detectScreen();
    const cand = { login: 'input[type=password]', home: 'PO - Erstelle', polist: 'Erstelle LPN', step1: 'Einzelposition/Weiter', form: 'Mengen-Maske' }[sc] || '—';
    const dbg = $('#lpnh-dbg'); dbg.style.display = 'block';
    dbg.textContent = 'Erkannter Screen (Top-Frame): ' + sc + '\nZiel-Element: ' + cand + '\n(Hinweis: liegt das Formular in einem iFrame, erkennt der Autopilot es trotzdem – diese Anzeige nur fürs Top-Fenster.)';
  };
  $('#lpnh-step1x').onclick = async () => {
    const ctx = { job: G.job(), gi: G.gi(), creds: { u: G.user(), p: G.pass() } };
    let r, best;
    try {
      best = await bestScreen();
      if (best.where === 'top') r = engineActOn(ctx);
      else r = await actFrame(ctx, best.screen);
    } catch (e) { st('⚠️ Fehler: ' + e.message, 'err'); return; }
    const dbg = $('#lpnh-dbg'); dbg.style.display = 'block';
    dbg.textContent = '1 Schritt → bester Screen=' + best.screen + ' (' + best.where + ') · acted=' + (r && r.acted) + ' · status=' + ((r && r.status) || '—');
    if (r && r.status) G.setStatus(r.status);
    st('1 Schritt: ' + best.screen + ' acted=' + (r && r.acted), (r && r.acted) ? 'ok' : 'warn');
  };
  $('#lpnh-diag').onclick = async () => {
    st('🔬 Sammle Diagnose aus allen Frames…');
    diagBucket = [];
    const frames = allFrames(window);
    for (const f of frames) { try { f.postMessage({ [TAG]: true, kind: 'req', action: 'diag', id: ++seq }, '*'); } catch (e) {} }
    await new Promise((r) => setTimeout(r, 1300));
    const all = [Object.assign({ where: 'TOP' }, diagFrame())].concat((diagBucket || []).map((d, i) => Object.assign({ where: 'F' + (i + 1) }, d)));
    diagBucket = null;
    const lines = ['=== LPN-Diagnose v0.23 ===',
      'Frames injiziert (erwartet): ' + (frames.length + 1) + ' · geantwortet: ' + all.length +
      (all.length < frames.length + 1 ? '  ⚠️ ein Frame ohne Script (kein Antwort)!' : '')];
    for (const f of all) {
      lines.push('');
      lines.push('[' + f.where + '] ' + f.url);
      lines.push('  screen=' + f.screen + ' gm=' + f.gm + ' pw=' + f.pw + ' form=' + f.form + ' erstelleLPN=' + f.erstelleLpn + ' weiter=' + f.weiter + ' tile="' + (f.tileText || '') + '"');
      lines.push('  AUTOPILOT: active=' + f.active + ' locked=' + f.locked + ' tries=' + f.tries + ' tickN=' + f.tickN + ' extJS=' + f.ext);
      if (f.poInfo) lines.push('  PO/CB: ' + f.poInfo);
      if (f.iframes && f.iframes.length) { lines.push('  IFRAMES (' + f.iframes.length + '):'); f.iframes.forEach((ifr, i) => lines.push('    #' + i + ' erreichbar=' + ifr.acc + ' inhalt="' + (ifr.has || '') + '" src=' + ifr.src)); }
      lines.push('  texte: ' + (f.texts || []).join(' | '));
    }
    const out = lines.join('\n');
    const dbg = $('#lpnh-dbg'); dbg.style.display = 'block'; dbg.textContent = out;
    try { await navigator.clipboard.writeText(out); st('🔬 Diagnose in Zwischenablage – an Aleks schicken', 'ok'); }
    catch (e) { st('🔬 Diagnose steht unten – bitte markieren & kopieren', 'warn'); }
  };
  $('#lpnh-fill').onclick = async () => {
    const g = job.groups[gi], tp = totalPallets(job);
    const ges = parseInt($('#lpnh-ges').value || gesamt || '0', 10), zielV = $('#lpnh-ziel').value.trim() || ziel;
    if (!ges || !tp) { st('⚠️ Gesamt bestellt fehlt – erst „Seite lesen"', 'err'); return; }
    const menge = Math.round(ges / tp);
    const r = await broadcast('fill', { check: true, lieferort: zielV, termin: nowStr(), menge: String(menge), anzahl: String(g.pallets), verfallsd: verfalls(g.mhd), charge: chargeOf(g.mhd), asn: $('#lpnh-asn').value.trim() });
    if (!r || !r.ok) { st('⚠️ Kein Formular gefunden', 'err'); return; }
    st('✓ Ausgefüllt: ' + (r.filled.join(', ') || '–') + (r.missing && r.missing.length ? ' · fehlt: ' + r.missing.join(', ') : ''), r.missing && r.missing.length ? 'warn' : 'ok');
  };

  // Status vom Autopilot (GM) spiegeln
  setInterval(() => { const s = G.status(); if (s) { const cls = s.startsWith('🛑') ? 'err' : s.startsWith('⚠') ? 'warn' : s.startsWith('✓') ? 'ok' : ''; st(s, cls); } }, 600);

  // Falls Autopilot nach Login-Redirect noch aktiv ist: Code wieder in die UI holen
  (function resume() { const j = G.job(); if (j && G.active()) { job = j; gi = G.gi() || 0;
    $('#lpnh-po').textContent = 'PO ' + job.po; $('#lpnh-pal').textContent = totalPallets(job) + ' Paletten gesamt';
    $('#lpnh-main').style.display = 'flex'; if (job.groups && job.groups[gi]) renderGroup(); st('▶ Autopilot läuft…', 'ok'); } })();

  /* ============ Autopilot-Orchestrator (nur Top-Frame) ============ */
  function applyResult(r) {
    if (r.done) G.setActive(false);
    G.lock(typeof r.lock === 'number' ? r.lock : 900);
    if (r.status) G.setStatus(r.status);
  }
  function handleLoginTop() {
    if (Date.now() - G.lastTry() < 6000) return;                 // auf Redirect warten
    if (!G.user() || !G.pass()) { G.setStatus('🔑 Login nötig – Benutzer/Passwort oben speichern, dann läuft es weiter'); G.setLastTry(Date.now()); return; }
    if (G.tries() >= 4) { G.setStatus('🔑 Auto-Login klappt nicht – bitte SELBST einloggen, ich mache danach weiter'); return; }
    G.setTries(G.tries() + 1); G.setLastTry(Date.now()); G.lock(1500);
    G.setStatus('Logge ein… (Versuch ' + G.tries() + ')');
    fillAndSubmitLogin({ u: G.user(), p: G.pass() });
  }
  let tickBusy = false;
  async function tick() {
    tickN++;
    if (!G.active() || G.locked() || tickBusy) return;
    tickBusy = true;
    try {
      const ctx = { job: G.job(), gi: G.gi(), creds: { u: G.user(), p: G.pass() } };
      if (detectScreen() === 'login') { handleLoginTop(); return; }   // Login ist Top-Ebene
      if (G.tries() !== 0) G.setTries(0);
      const best = await bestScreen();             // höchstwertiger Screen über Top + Frames
      if (best.p === 0) return;                     // nichts Aktionierbares
      let r;
      if (best.where === 'top') { r = engineActOn(ctx); }
      else { r = await actFrame(ctx, best.screen); } // im richtigen iFrame handeln
      if (r && r.acted) applyResult(r);
      else if (r && r.status) { G.setStatus(r.status); G.lock(900); }
    } catch (e) {} finally { tickBusy = false; }
  }
  setInterval(tick, 800);

  } // main
})();
