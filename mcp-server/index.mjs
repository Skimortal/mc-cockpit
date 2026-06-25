#!/usr/bin/env node
// MCP-Server für das MOST Connect Cockpit.
// Verbindet sich (als angemeldeter User) auf die Cockpit-REST-API und stellt Werkzeuge
// für Claude Desktop bereit. Sichtbarkeitsregeln (globale/persönliche Postfächer) gelten,
// weil der Server sich als ein konkreter Benutzer anmeldet.
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js'
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js'
import { z } from 'zod'

const BASE = (process.env.COCKPIT_URL || 'http://localhost:8090').replace(/\/$/, '')
const EMAIL = process.env.COCKPIT_EMAIL
const PASSWORD = process.env.COCKPIT_PASSWORD

let token = null

async function login() {
  if (!EMAIL || !PASSWORD) throw new Error('COCKPIT_EMAIL / COCKPIT_PASSWORD nicht gesetzt.')
  const res = await fetch(`${BASE}/api/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
  })
  if (!res.ok) throw new Error(`Login fehlgeschlagen (HTTP ${res.status}). URL/Zugangsdaten prüfen.`)
  token = (await res.json()).token
}

// Lädt eine Datei binär (mit Login/Token-Refresh wie api()), gibt Content-Type + Buffer zurück.
async function fetchBinary(path) {
  if (!token) await login()
  const build = () => ({ method: 'GET', headers: { Authorization: `Bearer ${token}` } })
  let res = await fetch(`${BASE}${path}`, build())
  if (res.status === 401) {
    await login()
    res = await fetch(`${BASE}${path}`, build())
  }
  if (!res.ok) {
    const t = await res.text().catch(() => '')
    throw new Error(`GET ${path} → HTTP ${res.status}: ${t}`)
  }
  const contentType = (res.headers.get('content-type') || 'application/octet-stream').split(';')[0].trim()
  const buf = Buffer.from(await res.arrayBuffer())
  return { contentType, buf }
}

async function api(path, method = 'GET', body) {
  if (!token) await login()
  const build = () => {
    const o = { method, headers: { Authorization: `Bearer ${token}` } }
    if (body !== undefined) {
      o.headers['Content-Type'] = 'application/json'
      o.body = JSON.stringify(body)
    }
    return o
  }
  let res = await fetch(`${BASE}${path}`, build())
  if (res.status === 401) {
    await login()
    res = await fetch(`${BASE}${path}`, build())
  }
  const text = await res.text()
  let data
  try { data = JSON.parse(text) } catch { data = text }
  if (!res.ok) throw new Error(`${method} ${path} → HTTP ${res.status}: ${typeof data === 'string' ? data : JSON.stringify(data)}`)
  return data
}

const server = new McpServer({ name: 'most-cockpit', version: '1.0.0' })

function tool(name, description, shape, fn) {
  server.tool(name, description, shape, async (args) => {
    const data = await fn(args || {})
    return { content: [{ type: 'text', text: typeof data === 'string' ? data : JSON.stringify(data, null, 2) }] }
  })
}

// ---- Suche & Posteingang ----
tool('cockpit_search', 'Globale Suche über Aufgaben, Mails (Konversationen) und Kunden.', { query: z.string() },
  ({ query }) => api(`/api/search?q=${encodeURIComponent(query)}`))

tool('cockpit_mailboxes', 'Liste der für dich sichtbaren Postfächer (global + eigene persönliche).', {},
  () => api('/api/mailboxes'))

tool('cockpit_inbox', 'Posteingang als Konversationsliste. Optional ein Postfach (mailboxId) oder "global"/"mine".',
  { mailbox: z.string().optional() },
  ({ mailbox }) => api('/api/inbox' + (mailbox ? `?mailbox=${encodeURIComponent(mailbox)}` : '')))

tool('cockpit_conversation', 'Eine Konversation (kompletter Mail-Thread) anhand ihrer ID.', { id: z.number() },
  ({ id }) => api(`/api/conversations/${id}`))

tool('cockpit_mail_to_task', 'Wandelt eine Konversation in eine Aufgabe um (KI-Zusammenfassung). Gibt die Aufgaben-ID zurück.',
  { conversationId: z.number() },
  ({ conversationId }) => api(`/api/conversations/${conversationId}/to-task`, 'POST'))

// ---- Aufgaben ----
tool('cockpit_tasks', 'Alle Aufgaben (Board) mit Status, Zuständigem, Tags, Kommentaren.', {},
  () => api('/api/board'))

tool('cockpit_team', 'Team-Mitglieder (für Zuweisungen).', {}, () => api('/api/team'))

tool('cockpit_assign_task', 'Weist eine Aufgabe einem Benutzer zu (userId) oder entfernt die Zuweisung (userId weglassen/null).',
  { taskId: z.number(), userId: z.number().nullable().optional() },
  ({ taskId, userId }) => api(`/api/tasks/${taskId}/assign`, 'POST', { userId: userId ?? null }))

tool('cockpit_set_task_status', 'Setzt den Status einer Aufgabe.',
  { taskId: z.number(), status: z.enum(['open', 'in_progress', 'waiting', 'done']) },
  ({ taskId, status }) => api(`/api/tasks/${taskId}/status`, 'POST', { status }))

tool('cockpit_set_task_tags', 'Setzt die Tags einer Aufgabe (ersetzt die bestehenden).',
  { taskId: z.number(), tags: z.array(z.string()) },
  ({ taskId, tags }) => api(`/api/tasks/${taskId}/tags`, 'POST', { tags }))

tool('cockpit_add_task_comment', 'Fügt einer Aufgabe einen Kommentar hinzu.',
  { taskId: z.number(), body: z.string() },
  ({ taskId, body }) => api(`/api/tasks/${taskId}/comments`, 'POST', { body }))

// ---- Antworten ----
tool('cockpit_draft_reply', 'Erzeugt einen KI-Antwortentwurf für die Aufgabe (sendet NICHT).', { taskId: z.number() },
  ({ taskId }) => api(`/api/tasks/${taskId}/draft-reply`, 'POST'))

tool('cockpit_send_reply', 'Sendet eine Antwort aus der Aufgabe (threaded) über das Postfach-SMTP.',
  { taskId: z.number(), body: z.string() },
  ({ taskId, body }) => api(`/api/tasks/${taskId}/reply`, 'POST', { body }))

// ---- Kunden ----
tool('cockpit_companies', 'Liste der Kunden (Hersteller).', {}, () => api('/api/companies'))

tool('cockpit_company', 'Ein Kunde mit Stammdaten, Ansprechpartnern und Dokumenten.', { id: z.number() },
  ({ id }) => api(`/api/companies/${id}`))

tool('cockpit_create_company', 'Legt einen neuen Kunden an.',
  { name: z.string(), subtitle: z.string().optional() },
  ({ name, subtitle }) => api('/api/companies', 'POST', { name, kind: 'hersteller', subtitle }))

tool('cockpit_update_company', 'Aktualisiert Stammfelder eines Kunden (name, subtitle, note, tags).',
  { id: z.number(), name: z.string().optional(), subtitle: z.string().optional(), note: z.string().optional(), tags: z.array(z.string()).optional() },
  ({ id, ...rest }) => api(`/api/companies/${id}`, 'PATCH', rest))

tool('cockpit_add_company_field', 'Fügt einem Kunden ein frei definierbares Stammdaten-Feld hinzu (z. B. Steuernummer).',
  { id: z.number(), label: z.string(), value: z.string() },
  async ({ id, label, value }) => {
    const c = await api(`/api/companies/${id}`)
    return api(`/api/companies/${id}`, 'PATCH', { customFields: [...(c.fields || []), { label, value }] })
  })

tool('cockpit_add_company_contact', 'Fügt einem Kunden einen Ansprechpartner hinzu.',
  { id: z.number(), department: z.string(), name: z.string(), email: z.string().optional(), phone: z.string().optional() },
  ({ id, department, name, email, phone }) => api(`/api/companies/${id}/contacts`, 'POST', { department, name, email, phone }))

// ---- Dokumente herunterladen ----
// Über dieses Limit (Rohgröße) wird die Datei nicht eingebettet – sonst sprengt sie den Kontext.
const MAX_DOC_BYTES = 12 * 1024 * 1024

server.tool(
  'cockpit_get_document',
  'Liest den Inhalt einer im Cockpit hinterlegten Datei (PDF, Word, Excel, Text …) anhand ihrer Dokument-ID und gibt ihn Claude zum Lesen & Zusammenfassen zurück – z. B. ein PDF-Datenblatt oder Angebot. Die ID stammt aus cockpit_company (Feld documents[].id).',
  { id: z.number() },
  async ({ id }) => {
    // 1) Zuerst den per Tika extrahierten Text holen – funktioniert zuverlässig für PDFs/Office-Dokumente.
    const meta = await api(`/api/documents/${id}/text`)
    const text = typeof meta?.text === 'string' ? meta.text.trim() : ''
    const usable = text && !['[leer]', '[nicht verfügbar]', '[fehler]'].includes(text)
    if (usable) {
      return { content: [{ type: 'text', text: `# ${meta.name}\n\n${text}` }] }
    }

    // 2) Kein extrahierbarer Text? Bei echten Bildern das Bild selbst übergeben (gültiger Media-Type).
    const ct = String(meta?.contentType || '')
    if (ct.startsWith('image/')) {
      const { contentType, buf } = await fetchBinary(`/api/documents/${id}/download`)
      if (buf.length <= MAX_DOC_BYTES) {
        return { content: [{ type: 'image', data: buf.toString('base64'), mimeType: contentType }] }
      }
    }

    return { content: [{ type: 'text', text: `Dokument #${id} (${meta?.name ?? ''}) enthält keinen extrahierbaren Text – vermutlich ein gescanntes/bildbasiertes PDF. Bitte direkt im Cockpit öffnen.` }] }
  },
)

// Hängt eine sinnvolle Dateiendung an, falls der Dokumentname keine hat (für Anhang-Dateinamen).
function withExt(name, type, contentType) {
  if (/\.[a-z0-9]{2,5}$/i.test(name)) return name
  const map = { 'application/pdf': 'pdf', 'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif' }
  const ext = map[contentType] || (type ? String(type).toLowerCase() : '')
  return ext ? `${name}.${ext}` : name
}

tool(
  'cockpit_get_document_file',
  'Gibt eine im Cockpit hinterlegte Datei als Base64-String zurück (Felder: filename, contentType, size, base64) – zum Weiterverwenden als E-Mail-Anhang (z. B. imap_save_draft). Zum reinen Lesen/Zusammenfassen besser cockpit_get_document verwenden. ID aus cockpit_company (documents[].id).',
  { id: z.number() },
  async ({ id }) => {
    const meta = await api(`/api/documents/${id}/text`).catch(() => ({}))
    const { contentType, buf } = await fetchBinary(`/api/documents/${id}/download`)
    if (buf.length > MAX_DOC_BYTES) {
      const mb = (n) => (n / 1024 / 1024).toFixed(1)
      throw new Error(`Datei #${id} ist ${mb(buf.length)} MB groß – zu groß für die Base64-Übergabe (Limit ${mb(MAX_DOC_BYTES)} MB).`)
    }
    return {
      id,
      filename: withExt(String(meta?.name || `dokument-${id}`), meta?.type, contentType),
      contentType,
      size: buf.length,
      base64: buf.toString('base64'),
    }
  },
)

const transport = new StdioServerTransport()
await server.connect(transport)
