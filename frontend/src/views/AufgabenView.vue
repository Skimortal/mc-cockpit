<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useAuth } from '../stores/auth'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'
import { confirmDialog, promptDialog } from '../composables/dialog'
import { mdHtml } from '../composables/markdown'

const auth = useAuth()
const route = useRoute()
const router = useRouter()

interface Mailbox { id: number; name: string; email: string; scope: string; mine: boolean }
interface Conv { id: number; from: string; email: string; subject: string; lastMessageAt: string; messageCount: number; state: string; taskId: number | null; owner: string | null; mailboxName: string; mailboxScope: string }
interface Person { id: number; name: string }
interface Comment { author: string; body: string; createdAt: string }
interface TaskFile { id: number; name: string; size: number; ext: string; uploadedBy: string | null; date: string; preview: boolean }
interface Task {
  id: number; title: string; type: string; status: string; priority: string; dueDate: string | null; overdue?: boolean
  aiSummary: string | null; description: string | null; suggestedAssignee: string | null; assignee: Person | null
  conversationId: number | null; companyId: number | null; companyName: string | null; tags: string[]; comments: Comment[]; files: TaskFile[]
}
interface Att { id: number; name: string; size: number; type: string | null; pruned?: boolean }
interface Msg { dir: string; who: string; to: string; time: string; body: string; attachments?: Att[] }
interface ConvDetail { id: number; subject: string; customerName: string; customerEmail: string; taskId: number | null; messages: Msg[] }

const TAGS = ['Ausschreibung', 'Muster', 'Reklamation', 'Labor', 'Logistik', 'Rechnung', 'Etikett', 'ASN', 'Allgemein']
const STATUS: Record<string, string> = { open: 'Offen', in_progress: 'In Arbeit', waiting: 'Wartet', done: 'Erledigt' }
const STATUS_KEYS = ['open', 'in_progress', 'waiting', 'done']
const PRIO: Record<string, string> = { high: 'Hoch', normal: 'Normal', low: 'Niedrig' }
const PRIO_KEYS = ['high', 'normal', 'low']
function prioStyle(p: string) { return p === 'high' ? 'background:#eb5d4f' : p === 'low' ? 'background:#9aa7b8' : 'background:#414c65' }

const mailboxes = ref<Mailbox[]>([])
const mailboxFilter = ref<string>('') // '' = alle | mailbox id
const inboxFilter = ref<'alle' | 'neu'>('alle')
const convs = ref<Conv[]>([])
const tasks = ref<Task[]>([])
const team = ref<Person[]>([])
const companies = ref<{ id: number; name: string }[]>([])
const showDone = ref(false)
const loading = ref(true)

const selConvId = ref<number | null>(null)
const detail = ref<ConvDetail | null>(null)
const openMsgs = ref<Set<number>>(new Set())
const replyText = ref('')
const replyMsg = ref('')
const replyBusy = ref(false)
const commentText = ref('')
const converting = ref(false)
const dragId = ref<number | null>(null)

const selTaskId = ref<number | null>(null) // für manuelle Aufgaben ohne Konversation
const selTask = computed(() =>
  (selConvId.value ? tasks.value.find((t) => t.conversationId === selConvId.value) : tasks.value.find((t) => t.id === selTaskId.value)) || null,
)
// Konversation neueste zuerst (Original-Index für Auf-/Zuklappen beibehalten)
const orderedMsgs = computed(() => (detail.value?.messages || []).map((m, i) => ({ m, i })).reverse())

function fmtDate(s: string): string {
  // "2026-06-08 14:20" -> "08.06. 14:20"
  if (!s || s.length < 16) return s
  return `${s.slice(8, 10)}.${s.slice(5, 7)}. ${s.slice(11, 16)}`
}
function badgeStyle(tag: string): string {
  const m: Record<string, string> = {
    Etikett: 'background:#eb5d4f;color:#fff', Labor: 'background:#9d9c87;color:#fff',
    ASN: 'background:#414c65;color:#fff', Muster: 'background:#d0cc5a;color:#191118',
    Rechnung: 'background:#4e4d4c;color:#fff', Ausschreibung: 'background:#eb5d4f;color:#fff',
    Reklamation: 'background:#eb5d4f22;color:#b23b2e', Logistik: 'background:#414c6522;color:#414c65',
    Allgemein: 'background:#e7ddd9;color:#6b5f5a',
  }
  return m[tag] || m.Allgemein
}

const visibleConvs = computed(() => {
  let cs = convs.value
  if (inboxFilter.value === 'neu') cs = cs.filter((c) => c.state === 'neu')
  return cs
})

// Status-Spalten; innerhalb jeder Spalte nach Person gruppiert (ich zuerst, dann Team, dann Unzugewiesen).
function statusColumns() {
  const active = tasks.value.filter((t) => t.status !== 'done' || showDone.value)
  const meId = auth.me?.id ?? null
  const people: { id: number | null; name: string; mine: boolean }[] = [
    ...(meId ? [{ id: meId, name: 'Meine Aufgaben', mine: true }] : []),
    ...team.value.filter((p) => p.id !== meId).map((p) => ({ id: p.id, name: p.name, mine: false })),
    { id: null, name: 'Unzugewiesen', mine: false },
  ]
  return STATUS_KEYS.filter((s) => s !== 'done' || showDone.value).map((s) => {
    const colTasks = active.filter((t) => t.status === s)
    const groups = people
      .map((p) => ({
        key: `${s}:${p.id ?? 'none'}`,
        name: p.name,
        mine: p.mine,
        status: s,
        assigneeId: p.id,
        items: colTasks.filter((t) => (p.id === null ? !t.assignee : t.assignee?.id === p.id)),
      }))
      .filter((g) => g.items.length > 0)
    return { status: s, name: STATUS[s], items: colTasks, groups }
  })
}

async function loadInbox() {
  const q = mailboxFilter.value ? `?mailbox=${mailboxFilter.value}` : ''
  convs.value = (await api.get('/api/inbox' + q)).data
}
async function loadBoard() {
  tasks.value = (await api.get('/api/board')).data
}
function selectMailbox(id: string) {
  if (mailboxFilter.value === id) return
  mailboxFilter.value = id
  selConvId.value = null
  detail.value = null
  loadInbox()
}
async function loadAll() {
  loading.value = true
  const [mb, tm, co] = await Promise.all([api.get('/api/mailboxes'), api.get('/api/team'), api.get('/api/companies')])
  mailboxes.value = mb.data
  team.value = tm.data
  companies.value = co.data
  // Immer genau ein Postfach gewählt (kein „Alle") – Standard: Team-Postfach, sonst erstes.
  if (!mailboxes.value.find((m) => String(m.id) === mailboxFilter.value)) {
    const def = mailboxes.value[0]
    mailboxFilter.value = def ? String(def.id) : ''
  }
  await Promise.all([loadInbox(), loadBoard()])
  loading.value = false
}
function fmtSize(n: number) {
  return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'
}
async function downloadAttachment(a: Att) {
  const res = await api.get(`/api/attachments/${a.id}/download`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  const link = document.createElement('a')
  link.href = url
  link.download = a.name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
function canPreview(a: Att) {
  const t = (a.type || '').toLowerCase()
  const n = a.name.toLowerCase()
  return t.startsWith('image/') || t.includes('pdf')
    || t.includes('word') || t.includes('sheet') || t.includes('excel')
    || t.includes('presentation') || t.includes('powerpoint') || t.includes('opendocument')
    || /\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|rtf)$/.test(n)
}
const previewOpen = ref(false)
const previewUrl = ref('')
const previewName = ref('')
const previewIsImage = ref(false)
const previewLoading = ref(false)
const previewAtt = ref<Att | null>(null)
async function openPreview(a: Att) {
  if (!canPreview(a)) {
    await downloadAttachment(a)
    return
  }
  previewAtt.value = a
  previewName.value = a.name
  previewIsImage.value = (a.type || '').toLowerCase().startsWith('image/')
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  previewLoading.value = true
  previewOpen.value = true
  try {
    const res = await api.get(`/api/attachments/${a.id}/preview`, { responseType: 'blob' })
    previewUrl.value = URL.createObjectURL(res.data as Blob)
  } catch {
    previewUrl.value = ''
  } finally {
    previewLoading.value = false
  }
}
function closePreview() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = ''
  previewOpen.value = false
  previewAtt.value = null
}
function previewDownload() {
  if (previewAtt.value) downloadAttachment(previewAtt.value)
}
const fetching = ref(false)
const fetchMsg = ref('')
async function manualFetch() {
  fetching.value = true
  fetchMsg.value = ''
  try {
    const { data } = await api.post('/api/inbox/fetch', mailboxFilter.value ? { mailbox: Number(mailboxFilter.value) } : {})
    await Promise.all([loadInbox(), loadBoard()])
    fetchMsg.value = data.errors?.length ? `Fehler: ${data.errors.join(', ')}` : `${data.new} neu`
  } catch {
    fetchMsg.value = 'Abruf fehlgeschlagen'
  } finally {
    fetching.value = false
    setTimeout(() => (fetchMsg.value = ''), 5000)
  }
}

const lastAtt = ref<number | null>(null)
// Manuelle Aufgabe (ohne Konversation) im Detail anzeigen.
function selectTask(t: Task) {
  selConvId.value = null
  detail.value = null
  selTaskId.value = t.id
  commentText.value = ''; notifyMsg.value = ''
}
async function selectConv(id: number) {
  selTaskId.value = null
  selConvId.value = id
  replyText.value = ''; replyMsg.value = ''; commentText.value = ''; explanation.value = ''; notifyMsg.value = ''
  const { data } = await api.get(`/api/conversations/${id}`)
  detail.value = data
  // Postfach der Konversation aktiv schalten, damit sie links erscheint/markiert ist.
  if (data.mailboxId && mailboxFilter.value !== String(data.mailboxId) && mailboxes.value.find((m) => m.id === data.mailboxId)) {
    mailboxFilter.value = String(data.mailboxId)
    loadInbox()
  }
  openMsgs.value = new Set([(detail.value?.messages.length ?? 1) - 1])
  openMatchedAtt()
}
// Wenn die Suche einen Anhang-Treffer übergibt (?att=ID), Vorschau direkt öffnen.
function openMatchedAtt() {
  const attId = Number(route.query.att) || null
  if (!attId || attId === lastAtt.value || !detail.value) return
  lastAtt.value = attId
  for (const m of detail.value.messages) {
    const a = (m.attachments || []).find((x) => x.id === attId)
    if (a) { openPreview(a); return }
  }
}
function closeDetail() {
  detail.value = null
  selConvId.value = null
  selTaskId.value = null
  lastAtt.value = null
  if (route.query.conv) router.replace({ path: '/aufgaben' })
}
function toggleMsg(i: number) {
  openMsgs.value.has(i) ? openMsgs.value.delete(i) : openMsgs.value.add(i)
  openMsgs.value = new Set(openMsgs.value)
}

async function convertToTask() {
  if (!selConvId.value) return
  converting.value = true
  try {
    await api.post(`/api/conversations/${selConvId.value}/to-task`)
    await Promise.all([loadBoard(), loadInbox()])
  } catch (e: any) {
    alert(e?.response?.data?.error || 'Umwandeln in Aufgabe fehlgeschlagen.')
  } finally { converting.value = false }
}
// --- Datei-Anhänge an der Aufgabe ---
const taskFileInput = ref<HTMLInputElement | null>(null)
const taskUploading = ref(false)
const taskDragOver = ref(false)
function triggerTaskUpload() {
  if (taskFileInput.value) taskFileInput.value.value = ''
  taskFileInput.value?.click()
}
function onTaskFilePicked(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files?.length) uploadTaskFiles(Array.from(files))
}
function onTaskDrop(e: DragEvent) {
  taskDragOver.value = false
  const files = e.dataTransfer?.files
  if (files?.length) uploadTaskFiles(Array.from(files))
}
async function uploadTaskFiles(files: File[]) {
  if (!selTask.value) return
  const tid = selTask.value.id
  taskUploading.value = true
  try {
    for (const f of files) {
      const fd = new FormData()
      fd.append('file', f)
      await api.post(`/api/tasks/${tid}/files`, fd)
    }
    await loadBoard()
  } finally { taskUploading.value = false }
}
async function downloadTaskFile(f: TaskFile) {
  const win = f.preview ? window.open('', '_blank') : null
  try {
    const r = await api.get(`/api/files/${f.id}/${f.preview ? 'preview' : 'download'}`, { responseType: 'blob' })
    const url = URL.createObjectURL(r.data as Blob)
    if (f.preview && win) { win.location.href = url; setTimeout(() => URL.revokeObjectURL(url), 60000) }
    else { const a = document.createElement('a'); a.href = url; a.download = f.name; a.click(); URL.revokeObjectURL(url) }
  } catch { if (win) win.close() }
}
async function delTaskFile(f: TaskFile) {
  if (!(await confirmDialog(`Datei „${f.name}" löschen?`, { title: 'Datei löschen', danger: true, okText: 'Löschen' }))) return
  await api.delete(`/api/files/${f.id}`)
  await loadBoard()
}
function fmtFileSize(n: number): string {
  if (!n) return ''
  if (n >= 1024 * 1024) return (n / 1024 / 1024).toFixed(1) + ' MB'
  if (n >= 1024) return Math.round(n / 1024) + ' KB'
  return n + ' B'
}

const explaining = ref(false)
const explanation = ref('')
async function explainConv() {
  if (!selConvId.value) return
  explaining.value = true
  explanation.value = ''
  try {
    const { data } = await api.post(`/api/conversations/${selConvId.value}/explain`)
    explanation.value = data.explanation || ''
  } catch {
    explanation.value = '⚠️ KI-Erklärung fehlgeschlagen.'
  } finally { explaining.value = false }
}
const notifyMsg = ref('')
async function assign(task: Task, userId: number | '') {
  notifyMsg.value = ''
  const { data } = await api.post(`/api/tasks/${task.id}/assign`, { userId: userId === '' ? null : userId })
  if (data?.emailed) notifyMsg.value = '✓ Zugewiesen & per E-Mail benachrichtigt'
  await Promise.all([loadBoard(), loadInbox()])
}
const notifying = ref(false)
async function notifyAssignee(task: Task) {
  const r = await promptDialog(
    [{ key: 'message', label: 'Optionale Nachricht (zusätzlich zu den Kommentaren) — leer lassen für nur Kommentare/Link', type: 'textarea', value: '' }],
    { title: '📧 Zuständigen benachrichtigen', okText: 'Senden' },
  )
  if (r === null) return
  notifying.value = true
  notifyMsg.value = ''
  try {
    const { data } = await api.post(`/api/tasks/${task.id}/notify-assignee`, { message: r.message || '' })
    notifyMsg.value = '✓ E-Mail gesendet an ' + (data.to || 'Zuständigen')
  } catch (e: any) {
    notifyMsg.value = '⚠️ ' + (e?.response?.data?.error || 'E-Mail fehlgeschlagen')
  } finally { notifying.value = false }
}
async function setPriority(task: Task, p: string) {
  await api.post(`/api/tasks/${task.id}/priority`, { priority: p })
  await loadBoard()
}
async function setStatus(task: Task, status: string) {
  await api.post(`/api/tasks/${task.id}/status`, { status })
  await Promise.all([loadBoard(), loadInbox()])
}
async function setDue(task: Task, dueDate: string | null) {
  await api.post(`/api/tasks/${task.id}/due`, { dueDate })
  await loadBoard()
}
function snooze(task: Task, days: number) {
  const d = new Date()
  d.setDate(d.getDate() + days)
  setDue(task, d.toISOString().slice(0, 10))
}
async function setCompany(task: Task, companyId: number | '') {
  await api.post(`/api/tasks/${task.id}/company`, { companyId: companyId === '' ? null : companyId })
  await loadBoard()
}
async function toggleTag(task: Task, tag: string) {
  const next = task.tags.includes(tag) ? task.tags.filter((t) => t !== tag) : [...task.tags, tag]
  await api.post(`/api/tasks/${task.id}/tags`, { tags: next })
  await loadBoard()
}
async function addComment(task: Task) {
  if (!commentText.value.trim()) return
  await api.post(`/api/tasks/${task.id}/comments`, { body: commentText.value })
  commentText.value = ''
  await loadBoard()
}
// --- Antwort-Modal ---
const replyOpen = ref(false)
const replyTo = ref('')
const replyCc = ref('')
const replySubject = ref('')
const replySignature = ref('')
const replySignatures = ref<{ id: number; name: string; html: string }[]>([])
const activeSigId = ref<number | null>(null)
const editSig = ref(false)
const replyFrom = ref('')
const replyTab = ref<'schreiben' | 'vorschau'>('schreiben')
const showCc = ref(false)
function pickSig(s: { id: number; html: string }) {
  activeSigId.value = s.id; replySignature.value = s.html; editSig.value = false
}
function pickNoSig() {
  activeSigId.value = null; replySignature.value = ''; editSig.value = false
}
// Anhänge im Antwort-Modal (Aufgaben-Dateien auswählen oder neue hochladen)
const replyFileIds = ref<number[]>([])
const replyFileInput = ref<HTMLInputElement | null>(null)
const replyTaskId = ref<number | null>(null)
const replyFiles = ref<{ id: number; name: string; size: number; ext: string }[]>([])
function toggleReplyFile(id: number) {
  const i = replyFileIds.value.indexOf(id)
  if (i >= 0) replyFileIds.value.splice(i, 1); else replyFileIds.value.push(id)
}
function triggerReplyUpload() {
  if (replyFileInput.value) replyFileInput.value.value = ''
  replyFileInput.value?.click()
}
async function onReplyFilePick(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (!files?.length || !selConvId.value) return
  taskUploading.value = true
  try {
    // ohne Aufgabe: leise eine (KI-freie) Aufgabe anlegen, damit die Datei gespeichert werden kann
    if (!replyTaskId.value) {
      const r = await api.post(`/api/conversations/${selConvId.value}/to-task`, { simple: true })
      replyTaskId.value = r.data.taskId
    }
    for (const f of Array.from(files)) {
      const fd = new FormData()
      fd.append('file', f)
      await api.post(`/api/tasks/${replyTaskId.value}/files`, fd)
    }
    const { data } = await api.get(`/api/conversations/${selConvId.value}/reply-context`)
    replyFiles.value = data.files || []
    replyFileIds.value = replyFiles.value.map((f) => f.id) // hochgeladene direkt anhängen
    await loadBoard()
  } finally { taskUploading.value = false }
}
const previewHtml = computed(() => {
  const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  let h = `<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.5">${esc(replyText.value).replace(/\n/g, '<br>')}</div>`
  if (replySignature.value.trim()) h += '<br>' + replySignature.value
  return h
})
async function openReply() {
  if (!selConvId.value) return
  replyMsg.value = ''; replyText.value = ''; replyTab.value = 'schreiben'; showCc.value = false
  replyTaskId.value = null; replyFiles.value = []; replyFileIds.value = []
  try {
    const { data } = await api.get(`/api/conversations/${selConvId.value}/reply-context`)
    replyTo.value = data.to || ''
    replyCc.value = data.cc || ''
    replySubject.value = data.subject || ''
    replySignatures.value = data.signatures || []
    replySignature.value = data.signature || ''
    activeSigId.value = data.defaultSignatureId ?? null
    editSig.value = false
    replyTaskId.value = data.taskId ?? null
    replyFiles.value = data.files || []
    replyFrom.value = data.fromName ? `${data.fromName} <${data.fromEmail}>` : (data.fromEmail || '')
    if (replyCc.value) showCc.value = true
  } catch {
    replyMsg.value = 'Konnte Antwort-Daten nicht laden.'
  }
  replyOpen.value = true
}
async function draftReply() {
  if (!selConvId.value) return
  replyBusy.value = true; replyMsg.value = ''
  try {
    replyText.value = (await api.post(`/api/conversations/${selConvId.value}/draft-reply`)).data.draft
    replyTab.value = 'schreiben'
  } catch (e: any) { replyMsg.value = '⚠️ ' + (e?.response?.data?.error || 'KI-Entwurf fehlgeschlagen.') } finally { replyBusy.value = false }
}
async function sendReply() {
  if (!selConvId.value || !replyText.value.trim() || !replyTo.value.trim()) return
  replyBusy.value = true; replyMsg.value = ''
  try {
    const { data } = await api.post(`/api/conversations/${selConvId.value}/reply`, {
      body: replyText.value, to: replyTo.value, cc: replyCc.value, subject: replySubject.value, signature: replySignature.value, fileIds: replyFileIds.value,
    })
    replyOpen.value = false
    notifyMsg.value = `✓ Antwort gesendet an ${data.to}`
    replyText.value = ''
    if (selConvId.value) await selectConv(selConvId.value)
    await loadBoard()
  } catch (e: any) {
    replyMsg.value = 'Senden fehlgeschlagen: ' + (e?.response?.data?.error ?? 'unbekannt')
  } finally { replyBusy.value = false }
}

// Ablegen auf eine Spalte ändert den Status; Ablegen auf eine Personengruppe zusätzlich den Zuständigen.
async function onDropTo(status: string, assigneeId?: number | null) {
  const t = tasks.value.find((x) => x.id === dragId.value)
  dragId.value = null
  if (!t) return
  const calls: Promise<unknown>[] = []
  if (t.status !== status) calls.push(api.post(`/api/tasks/${t.id}/status`, { status }))
  if (assigneeId !== undefined && (t.assignee?.id ?? null) !== assigneeId) {
    calls.push(api.post(`/api/tasks/${t.id}/assign`, { userId: assigneeId }))
  }
  if (calls.length) {
    await Promise.all(calls)
    await Promise.all([loadBoard(), loadInbox()])
  }
}

// --- Neue Aufgabe (volles Formular, ohne Mail-Bezug) ---
const newTaskOpen = ref(false)
const ntSaving = ref(false)
const nt = ref({ title: '', description: '', status: 'open', priority: 'normal', dueDate: '', assigneeId: '' as number | '', companyId: '' as number | '', tags: [] as string[], notify: true })
function openNewTask(status = 'open', assigneeId: number | null = null) {
  nt.value = { title: '', description: '', status, priority: 'normal', dueDate: '', assigneeId: assigneeId ?? '', companyId: '', tags: [], notify: true }
  newTaskOpen.value = true
}
function ntToggleTag(tag: string) {
  nt.value.tags = nt.value.tags.includes(tag) ? nt.value.tags.filter((t) => t !== tag) : [...nt.value.tags, tag]
}
async function saveNewTask() {
  if (!nt.value.title.trim() || ntSaving.value) return
  ntSaving.value = true
  try {
    await api.post('/api/board/tasks', {
      title: nt.value.title.trim(),
      description: nt.value.description.trim(),
      status: nt.value.status,
      priority: nt.value.priority,
      dueDate: nt.value.dueDate || null,
      assigneeId: nt.value.assigneeId === '' ? null : Number(nt.value.assigneeId),
      companyId: nt.value.companyId === '' ? null : Number(nt.value.companyId),
      tags: nt.value.tags,
      notify: nt.value.notify,
    })
    newTaskOpen.value = false
    await loadBoard()
  } catch (e: any) {
    alert(e?.response?.data?.error || 'Aufgabe konnte nicht angelegt werden.')
  } finally { ntSaving.value = false }
}

watch(() => [route.query.conv, route.query.att], (v) => { if (v[0]) selectConv(Number(v[0])) })
let pollTimer: ReturnType<typeof setInterval> | undefined
onMounted(async () => {
  if (!auth.me) await auth.fetchMe().catch(() => {})
  await loadAll()
  if (route.query.conv) selectConv(Number(route.query.conv))
  pollTimer = setInterval(() => loadInbox(), 60000) // Posteingang automatisch frisch halten
})
onBeforeUnmount(() => clearInterval(pollTimer))
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar />

    <div class="flex-1 flex min-h-0">
      <!-- Posteingang -->
      <div class="w-[330px] shrink-0 bg-beige-soft border-r border-[#e6dad6] flex flex-col">
        <!-- Postfach-Switcher -->
        <div class="px-2.5 pt-2.5 pb-1.5 flex flex-wrap gap-1 border-b border-[#ece1dc]">
          <button v-for="m in mailboxes" :key="m.id" @click="selectMailbox(String(m.id))"
            class="text-[11px] px-2 py-1 rounded flex items-center gap-1"
            :class="mailboxFilter === String(m.id) ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'"
            :title="m.email">
            <span class="w-1.5 h-1.5 rounded-full" :style="m.scope === 'global' ? 'background:#414c65' : 'background:#eb5d4f'"></span>
            {{ m.name }}
          </button>
        </div>
        <div class="px-3 pt-2 pb-1 flex items-center gap-1 text-[11px]">
          <button @click="inboxFilter = 'alle'" class="px-2 py-1 rounded" :class="inboxFilter === 'alle' ? 'bg-coral text-white' : 'text-neutral-500 hover:bg-beige'">Alle</button>
          <button @click="inboxFilter = 'neu'" class="px-2 py-1 rounded" :class="inboxFilter === 'neu' ? 'bg-coral text-white' : 'text-neutral-500 hover:bg-beige'">ohne Aufgabe</button>
          <div class="ml-auto flex items-center gap-1.5">
            <span v-if="fetchMsg" class="text-[10px] text-neutral-400">{{ fetchMsg }}</span>
            <button @click="manualFetch" :disabled="fetching" title="Mails jetzt abrufen" class="flex items-center gap-1 text-navy hover:text-coral disabled:opacity-50">
              <Icon name="refresh" class="w-4 h-4" :class="fetching ? 'animate-spin' : ''" />
              <span class="text-[11px]">{{ fetching ? 'Abrufen…' : 'Abrufen' }}</span>
            </button>
          </div>
        </div>
        <div class="overflow-y-auto flex-1 px-2.5 pb-2.5">
          <div v-if="loading" class="text-xs text-neutral-400 p-3">Lädt…</div>
          <div v-else-if="!visibleConvs.length" class="text-xs text-neutral-400 p-3">Keine Mails.</div>
          <div v-for="c in visibleConvs" :key="c.id" class="relative" :class="c.messageCount > 1 ? 'mb-4' : 'mb-2.5'">
            <div v-if="c.messageCount > 2" class="absolute left-3 right-3 top-2 bottom-2 translate-y-[12px] rounded-xl bg-[#ecdfd9] border border-[#e0d2cd]"></div>
            <div v-if="c.messageCount > 1" class="absolute left-1.5 right-1.5 top-1 bottom-1 translate-y-[6px] rounded-xl bg-[#f4ebe7] border border-[#e6dad6]"></div>
            <div @click="selectConv(c.id)"
              class="relative bg-white border rounded-xl p-3 shadow-sm hover:shadow-md cursor-pointer"
              :class="selConvId === c.id ? 'border-coral ring-2 ring-coral/30' : 'border-[#e6dad6]'">
              <div class="flex items-center gap-2">
                <span class="text-[13px] text-navy font-semibold truncate">{{ c.from }}</span>
                <span class="ml-auto text-[10px] text-neutral-400 shrink-0">{{ fmtDate(c.lastMessageAt) }}</span>
              </div>
              <div class="text-[12px] text-neutral-600 truncate mt-0.5">{{ c.subject }}</div>
              <div class="mt-1.5 flex items-center gap-1.5 flex-wrap">
                <span v-if="c.state === 'neu'" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f;color:#fff">Neu</span>
                <span v-else-if="c.state === 'aufgabe'" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#414c6522;color:#414c65">→ Aufgabe #{{ c.taskId }}</span>
                <span v-else class="text-[10px] px-1.5 py-0.5 rounded" style="background:#e7ddd9;color:#9a8f8a;text-decoration:line-through">erledigt</span>
                <span v-if="c.messageCount > 1" class="text-[10px] px-1.5 py-0.5 rounded-full" style="background:#414c6518;color:#414c65">▤ {{ c.messageCount }}</span>
                <span v-if="c.owner" class="ml-auto text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f22;color:#b23b2e">{{ c.owner }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Board -->
      <div class="flex-1 min-w-0 flex flex-col">
        <div class="px-5 pt-3 pb-2 flex items-center gap-2">
          <span class="text-[10px] uppercase tracking-wider text-neutral-400 mr-1">Aufgaben</span>
          <button @click="openNewTask()" class="text-[11px] px-2.5 py-1 rounded-lg bg-coral text-white font-medium hover:bg-coral-dark flex items-center gap-1"><Icon name="plus" class="w-3.5 h-3.5" /> Neue Aufgabe</button>
          <button @click="showDone = !showDone" class="text-[11px] px-2 py-0.5 rounded" :class="showDone ? 'bg-navy text-white' : 'bg-white text-neutral-500 border border-[#e6dad6]'">{{ showDone ? 'erledigte ausblenden' : 'erledigte zeigen' }}</button>
          <button @click="loadAll" title="Aktualisieren" class="ml-auto w-7 h-7 grid place-items-center rounded-lg text-neutral-500 hover:bg-beige"><Icon name="refresh" class="w-4 h-4" /></button>
        </div>
        <div class="flex-1 overflow-x-auto px-5 pb-4">
          <div class="flex gap-4 h-full">
            <div v-for="col in statusColumns()" :key="col.status" class="dropcol w-60 shrink-0 rounded-xl transition flex flex-col"
              @dragover.prevent="($event.currentTarget as HTMLElement).classList.add('over')"
              @dragleave="($event.currentTarget as HTMLElement).classList.remove('over')"
              @drop="($event.currentTarget as HTMLElement).classList.remove('over'); onDropTo(col.status)">
              <div class="flex items-center justify-between mb-2 px-1">
                <span class="text-[12px] font-semibold text-navy">{{ col.name }}</span>
                <div class="flex items-center gap-1">
                  <span class="text-[10px] px-1.5 rounded-full bg-coral/15 text-coral">{{ col.items.length }}</span>
                  <button @click="openNewTask(col.status)" title="Aufgabe in dieser Spalte anlegen" class="w-5 h-5 grid place-items-center rounded text-neutral-400 hover:bg-coral/10 hover:text-coral text-[15px] leading-none">+</button>
                </div>
              </div>
              <div class="space-y-3 min-h-[64px] overflow-y-auto pr-0.5">
                <div v-for="g in col.groups" :key="g.key" class="dropgrp transition"
                  :class="g.mine ? 'rounded-lg bg-coral/5 ring-1 ring-coral/15 p-1' : ''"
                  @dragover.stop.prevent="($event.currentTarget as HTMLElement).classList.add('overg')"
                  @dragleave="($event.currentTarget as HTMLElement).classList.remove('overg')"
                  @drop.stop="($event.currentTarget as HTMLElement).classList.remove('overg'); onDropTo(g.status, g.assigneeId)">
                  <div class="flex items-center justify-between px-1 mb-1">
                    <span class="text-[10px] font-semibold uppercase tracking-wide" :class="g.mine ? 'text-coral' : 'text-neutral-400'">{{ g.mine ? '📌 ' : '' }}{{ g.name }} <span class="text-neutral-300">·{{ g.items.length }}</span></span>
                    <button @click="openNewTask(g.status, g.assigneeId)" :title="`Aufgabe für ${g.name} anlegen`" class="w-4 h-4 grid place-items-center rounded text-neutral-300 hover:text-coral text-[14px] leading-none">+</button>
                  </div>
                  <div class="space-y-2">
                    <article v-for="t in g.items" :key="t.id" draggable="true"
                      @dragstart="dragId = t.id" @dragend="dragId = null"
                      @click="t.conversationId ? selectConv(t.conversationId) : selectTask(t)"
                      class="relative bg-white border border-[#e6dad6] rounded-xl p-2.5 shadow-sm hover:shadow-md cursor-pointer">
                      <button @click.stop="setStatus(t, 'done')" title="Als erledigt markieren"
                        class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-beige-soft hover:bg-[#3f9d6b] hover:text-white text-neutral-400 text-[11px] leading-none border border-[#e6dad6] flex items-center justify-center">✓</button>
                      <div class="flex items-center gap-1.5 mb-1.5 pr-6 flex-wrap">
                        <span v-for="tag in t.tags.slice(0, 2)" :key="tag" class="text-[10px] px-1.5 py-0.5 rounded" :style="badgeStyle(tag)">{{ tag }}</span>
                        <span v-if="t.priority === 'high'" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f;color:#fff">Hoch</span>
                        <span v-if="!t.conversationId" class="text-[10px] px-1.5 py-0.5 rounded bg-navy/10 text-navy">manuell</span>
                        <span v-if="t.dueDate" class="ml-auto text-[10px]" :class="t.overdue ? 'text-coral font-semibold' : 'text-neutral-400'">⏱ {{ t.dueDate.slice(5) }}</span>
                      </div>
                      <div class="text-[12.5px] leading-snug text-ebony">{{ t.title }}</div>
                    </article>
                  </div>
                </div>
                <div v-if="!col.items.length" class="text-[11px] text-neutral-300 px-1 py-5 text-center border-2 border-dashed border-[#e6dad6] rounded-lg">leer · hierher ziehen</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Detail -->
      <div class="w-[380px] shrink-0 bg-white border-l border-[#e6dad6] flex flex-col">
        <div v-if="!detail && !selTask" class="h-full flex items-center justify-center text-center text-neutral-400 text-sm px-6">Wähle links eine Konversation<br>oder eine Aufgabe.</div>
        <template v-else>
          <div class="px-4 py-3 border-b border-[#e6dad6] flex items-start gap-2">
            <div class="min-w-0">
              <div class="text-[14px] font-semibold text-navy leading-tight truncate">{{ detail ? detail.subject : (selTask?.title || 'Aufgabe') }}</div>
              <div class="text-[11px] text-neutral-400 mt-0.5">{{ detail ? (detail.customerName || detail.customerEmail) : 'Manuelle Aufgabe' }}</div>
            </div>
            <button @click="closeDetail" class="ml-auto text-neutral-400 hover:text-neutral-700 text-xl leading-none">×</button>
          </div>

          <div class="px-4 py-3 overflow-y-auto flex-1">
            <!-- Aufgabe + KI-Zusammenfassung ganz oben -->
            <div v-if="selTask" class="p-3 rounded-xl bg-beige-soft border border-[#e6dad6]">
              <div class="text-[13px] font-semibold text-ebony">{{ selTask.title }}</div>
              <div v-if="selTask.aiSummary" class="mt-1 p-2 rounded-lg bg-coral/10 text-[12px] text-[#8a3328]"><b>KI:</b> {{ selTask.aiSummary }}</div>
              <div v-if="selTask.description" class="mt-1 text-[12px] text-neutral-600 whitespace-pre-wrap">{{ selTask.description }}</div>

              <!-- Status + Priorität (Schnellzustand) -->
              <div class="mt-2 flex items-center gap-1 flex-wrap">
                <button v-for="s in STATUS_KEYS" :key="s" @click="setStatus(selTask, s)" class="text-[10px] px-2 py-1 rounded-lg" :class="selTask.status === s ? 'bg-coral text-white' : 'bg-white text-neutral-600 border border-[#e6dad6]'">{{ STATUS[s] }}</button>
              </div>
              <div class="mt-1.5 flex items-center gap-1 flex-wrap text-[11px]">
                <span class="text-neutral-500 mr-0.5">Priorität:</span>
                <button v-for="p in PRIO_KEYS" :key="p" @click="setPriority(selTask, p)" class="text-[10px] px-2 py-1 rounded-lg" :class="selTask.priority === p ? 'text-white' : 'bg-white text-neutral-600 border border-[#e6dad6]'" :style="selTask.priority === p ? prioStyle(p) : ''">{{ PRIO[p] }}</button>
              </div>

              <!-- Zuständig + Benachrichtigen -->
              <div class="mt-2 flex items-center gap-2 text-[11px]">
                <span class="text-neutral-500">Zuständig:</span>
                <select :value="selTask.assignee?.id ?? ''" @change="assign(selTask, ($event.target as HTMLSelectElement).value === '' ? '' : Number(($event.target as HTMLSelectElement).value))" class="border border-[#e0d2cd] rounded-lg px-2 py-1 text-[11px] bg-white">
                  <option value="">— Unzugewiesen —</option>
                  <option v-for="p in team" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <button v-if="selTask.assignee" @click="notifyAssignee(selTask)" :disabled="notifying" title="E-Mail an den Zuständigen – inkl. Kommentare, optionaler Nachricht, Datei-Hinweis und Link" class="ml-auto text-[11px] px-2 py-1 rounded-lg bg-navy/10 text-navy hover:bg-navy/20 disabled:opacity-50 whitespace-nowrap">{{ notifying ? '…' : '📧 Benachrichtigen' }}</button>
              </div>
              <div v-if="notifyMsg" class="mt-1 text-[11px]" :class="notifyMsg.startsWith('⚠️') ? 'text-red-600' : 'text-green-700'">{{ notifyMsg }}</div>

              <!-- Kommentare / Anweisungen -->
              <div class="mt-3"><div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1">Kommentare / Anweisungen</div>
                <div v-for="(k, i) in selTask.comments" :key="i" class="text-[11px] mb-1">
                  <span class="font-semibold text-navy">{{ k.author }}</span> <span class="text-neutral-400">{{ k.createdAt.slice(5, 16) }}</span><br>
                  <span class="text-neutral-600 whitespace-pre-wrap">{{ k.body }}</span>
                </div>
                <div class="flex gap-1 mt-1">
                  <input v-model="commentText" placeholder="Kommentar / Anweisung…" @keyup.enter="addComment(selTask)" class="flex-1 border border-[#e0d2cd] rounded-lg px-2 py-1 text-[11px]" />
                  <button @click="addComment(selTask)" class="text-[11px] px-2 py-1 rounded-lg bg-navy text-white">+</button>
                </div>
              </div>
              <!-- Datei-Anhänge an der Aufgabe -->
              <div class="mt-3"
                @dragover.prevent="taskDragOver = true" @dragenter.prevent="taskDragOver = true"
                @dragleave.prevent="taskDragOver = false" @drop.prevent="onTaskDrop">
                <div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1">Dateien</div>
                <div v-for="f in selTask.files" :key="f.id" class="flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-2 py-1.5 mb-1">
                  <span class="w-7 h-7 rounded bg-navy/10 text-navy text-[9px] grid place-items-center font-semibold uppercase shrink-0">{{ f.ext }}</span>
                  <button @click="downloadTaskFile(f)" class="text-[12px] text-ebony text-left hover:text-coral truncate min-w-0 flex-1" :title="f.preview ? 'Öffnen' : 'Herunterladen'">{{ f.name }}</button>
                  <span class="text-[10px] text-neutral-300 shrink-0">{{ fmtFileSize(f.size) }}</span>
                  <button @click="downloadTaskFile(f)" :title="f.preview ? 'Öffnen' : 'Herunterladen'" class="w-6 h-6 grid place-items-center rounded text-neutral-400 hover:bg-beige hover:text-navy shrink-0"><Icon :name="f.preview ? 'eye' : 'download'" class="w-3.5 h-3.5" /></button>
                  <button @click="delTaskFile(f)" title="Löschen" class="w-6 h-6 grid place-items-center rounded text-neutral-400 hover:bg-red-50 hover:text-red-600 shrink-0"><Icon name="trash" class="w-3.5 h-3.5" /></button>
                </div>
                <button type="button" @click="triggerTaskUpload" :disabled="taskUploading"
                  class="w-full mt-1 rounded-xl border-2 border-dashed px-3 py-3 flex flex-col items-center gap-1 transition-colors cursor-pointer disabled:opacity-60"
                  :class="taskDragOver ? 'border-coral bg-coral/10 text-coral' : 'border-[#d8c7c1] text-neutral-500 hover:border-coral hover:text-coral hover:bg-coral/5'">
                  <Icon name="upload" class="w-5 h-5" :class="taskDragOver ? 'text-coral' : 'text-neutral-400'" />
                  <span class="text-[12px] font-medium">{{ taskUploading ? 'Lädt hoch…' : (taskDragOver ? 'Loslassen zum Hochladen' : 'Dateien hierher ziehen') }}</span>
                  <span v-if="!taskUploading && !taskDragOver" class="text-[10px] text-neutral-400">oder klicken (ZIP/PDF/… bis 50 MB)</span>
                </button>
                <input ref="taskFileInput" type="file" multiple class="hidden" @change="onTaskFilePicked" />
              </div>

              <!-- Sekundär: Kunde, Fälligkeit, Tags -->
              <div class="mt-3 pt-3 border-t border-[#e6dad6] space-y-2">
                <div class="flex items-center gap-2 text-[11px]">
                  <span class="text-neutral-500">Kunde:</span>
                  <select :value="selTask.companyId ?? ''" @change="setCompany(selTask, ($event.target as HTMLSelectElement).value === '' ? '' : Number(($event.target as HTMLSelectElement).value))" class="border border-[#e0d2cd] rounded-lg px-2 py-1 text-[11px] bg-white">
                    <option value="">— Kein Kunde —</option>
                    <option v-for="co in companies" :key="co.id" :value="co.id">{{ co.name }}</option>
                  </select>
                </div>
                <div class="flex items-center gap-1.5 flex-wrap text-[11px]">
                  <span class="text-neutral-500">Fällig:</span>
                  <span v-if="selTask.dueDate" class="px-1.5 py-0.5 rounded" :class="selTask.overdue ? 'bg-coral text-white' : 'bg-white border border-[#e6dad6] text-neutral-600'">{{ selTask.dueDate }}<span v-if="selTask.overdue"> · überfällig</span></span>
                  <span v-else class="text-neutral-400">—</span>
                  <input type="date" :value="selTask.dueDate || ''" @change="setDue(selTask, ($event.target as HTMLInputElement).value || null)" class="border border-[#e0d2cd] rounded-lg px-1.5 py-0.5 text-[11px] bg-white ml-auto" />
                </div>
                <div class="flex items-center gap-1 flex-wrap text-[10px]">
                  <span class="text-neutral-400">Wiedervorlage:</span>
                  <button @click="snooze(selTask, 1)" class="px-1.5 py-0.5 rounded bg-white border border-[#e6dad6] hover:border-coral">morgen</button>
                  <button @click="snooze(selTask, 3)" class="px-1.5 py-0.5 rounded bg-white border border-[#e6dad6] hover:border-coral">+3 Tage</button>
                  <button @click="snooze(selTask, 7)" class="px-1.5 py-0.5 rounded bg-white border border-[#e6dad6] hover:border-coral">nächste Woche</button>
                  <button v-if="selTask.dueDate" @click="setDue(selTask, null)" class="px-1.5 py-0.5 rounded text-neutral-400 hover:text-coral">entfernen</button>
                </div>
                <div><div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1">Tags</div>
                  <div class="flex flex-wrap gap-1">
                    <button v-for="tag in TAGS" :key="tag" @click="toggleTag(selTask, tag)" class="text-[10px] px-1.5 py-0.5 rounded" :style="selTask.tags.includes(tag) ? badgeStyle(tag) : 'background:#f0e7e3;color:#b7a9a3'">{{ tag }}</button>
                  </div>
                </div>
              </div>
            </div>
            <div v-else-if="detail">
              <div class="flex gap-2">
                <button @click="convertToTask" :disabled="converting || explaining" class="flex-1 py-2.5 rounded-xl bg-coral text-white text-sm font-medium hover:bg-coral-dark disabled:opacity-50">✨ In Aufgabe umwandeln</button>
                <button @click="explainConv" :disabled="converting || explaining" class="px-3 py-2.5 rounded-xl border border-coral/40 text-coral text-sm font-medium hover:bg-coral/10 disabled:opacity-50 whitespace-nowrap">{{ explaining ? '🤔 …' : '🔎 KI erklärt' }}</button>
              </div>

              <!-- Ladescreen: KI wandelt Mail in Aufgabe um -->
              <div v-if="converting" class="mt-3 flex items-center gap-3 p-4 rounded-xl bg-coral/5 border border-coral/20">
                <span class="inline-block w-5 h-5 border-2 border-coral/30 border-t-coral rounded-full animate-spin shrink-0"></span>
                <div class="text-[12.5px] text-[#8a3328]"><b>KI analysiert die Mail…</b><br><span class="text-neutral-500">Zusammenfassung, Frist & Zuständigen werden erkannt — einen Moment.</span></div>
              </div>

              <!-- KI-Erklärung -->
              <div v-if="explaining" class="mt-3 flex items-center gap-2 p-3 rounded-xl bg-beige-soft text-[12px] text-neutral-500">
                <span class="inline-block w-4 h-4 border-2 border-coral/30 border-t-coral rounded-full animate-spin shrink-0"></span> KI liest den Verlauf…
              </div>
              <div v-else-if="explanation" class="mt-3 p-3 rounded-xl bg-navy/5 border border-navy/15 text-[12.5px] text-ebony leading-relaxed">
                <div class="text-[10px] uppercase tracking-wide text-navy/60 mb-1">🔎 KI-Erklärung</div>
                <div v-html="mdHtml(explanation)"></div>
              </div>
            </div>

            <!-- Konversation, neueste zuerst -->
            <template v-if="detail">
            <div class="mt-4 text-[10px] uppercase tracking-wide text-neutral-400 mb-1.5">Konversation · {{ detail.messages.length }} Nachricht(en) · neueste zuerst</div>
            <div v-for="x in orderedMsgs" :key="x.i" class="border rounded-lg mb-2 overflow-hidden" :class="x.m.dir === 'out' ? 'border-coral/30' : 'border-[#e6dad6]'">
              <div @click="toggleMsg(x.i)" class="flex items-center gap-2 px-3 py-2 cursor-pointer" :class="x.m.dir === 'out' ? 'bg-coral/5' : 'bg-beige-soft'">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-semibold shrink-0 text-white" :style="x.m.dir === 'out' ? 'background:#eb5d4f' : 'background:#414c65'">{{ x.m.dir === 'out' ? '✓' : (x.m.who || '?').slice(0, 2).toUpperCase() }}</span>
                <div class="min-w-0 flex-1">
                  <div class="text-[12px] font-semibold leading-tight" :class="x.m.dir === 'out' ? 'text-coral' : 'text-navy'">{{ x.m.dir === 'out' ? 'Wir' : x.m.who }}</div>
                  <div v-if="!openMsgs.has(x.i)" class="text-[11px] text-neutral-400 truncate">{{ x.m.body.slice(0, 80) }}</div>
                </div>
                <Icon v-if="x.m.attachments && x.m.attachments.length" name="paperclip" class="w-3.5 h-3.5 text-neutral-400 shrink-0" />
                <span class="text-[10px] text-neutral-400 shrink-0">{{ fmtDate(x.m.time) }}</span>
              </div>
              <div v-if="openMsgs.has(x.i)" class="px-3 py-2.5 text-[12.5px] text-neutral-700 leading-relaxed whitespace-pre-wrap border-t" :class="x.m.dir === 'out' ? 'border-coral/20' : 'border-[#efe4df]'">{{ x.m.body }}</div>
              <div v-if="openMsgs.has(x.i) && x.m.attachments && x.m.attachments.length" class="px-3 pb-2.5 pt-2 flex flex-wrap gap-1.5 border-t" :class="x.m.dir === 'out' ? 'border-coral/20' : 'border-[#efe4df]'">
                <div v-for="a in x.m.attachments" :key="a.id" class="flex items-center rounded border bg-white overflow-hidden max-w-[240px]"
                  :class="a.pruned ? 'border-[#e6dad6] opacity-70' : 'border-[#e0d2cd]'">
                  <template v-if="a.pruned">
                    <span :title="`${a.name} – nach Aufbewahrungsfrist entfernt, Original im Mail-Archiv`"
                      class="flex items-center gap-1.5 text-[11px] px-2 py-1 text-neutral-400 min-w-0">
                      <Icon name="paperclip" class="w-3.5 h-3.5 shrink-0" />
                      <span class="truncate line-through">{{ a.name }}</span>
                      <span class="shrink-0 not-italic">· im Archiv</span>
                    </span>
                  </template>
                  <template v-else>
                    <button @click="openPreview(a)" :title="canPreview(a) ? `${a.name} – Vorschau` : `${a.name} – herunterladen`"
                      class="flex items-center gap-1.5 text-[11px] px-2 py-1 hover:text-coral min-w-0">
                      <Icon name="paperclip" class="w-3.5 h-3.5 shrink-0" />
                      <span class="truncate">{{ a.name }}</span>
                      <span class="text-neutral-400 shrink-0">{{ fmtSize(a.size) }}</span>
                    </button>
                    <button @click="downloadAttachment(a)" title="Herunterladen" class="px-1.5 py-1 border-l border-[#efe4df] text-neutral-400 hover:text-coral shrink-0">
                      <Icon name="download" class="w-3.5 h-3.5" />
                    </button>
                  </template>
                </div>
              </div>
            </div>
            </template>
          </div>

          <!-- Antwort (nur bei Konversation) -->
          <div v-if="detail" class="px-4 py-3 border-t border-[#e6dad6] bg-beige-soft">
            <button @click="openReply" class="w-full py-2.5 rounded-xl bg-coral text-white text-sm font-medium hover:bg-coral-dark">✉️ Antworten</button>
          </div>
        </template>
      </div>
    </div>

    <!-- Anhang-Vorschau -->
    <div v-if="previewOpen" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4" @click.self="closePreview">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl h-[85vh] flex flex-col overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-2.5 border-b border-[#e6dad6]">
          <Icon name="paperclip" class="w-4 h-4 text-navy shrink-0" />
          <span class="text-[13px] font-semibold text-ebony truncate flex-1">{{ previewName }}</span>
          <button @click="previewDownload" class="text-[12px] flex items-center gap-1 text-navy hover:text-coral px-2 py-1">
            <Icon name="download" class="w-4 h-4" /> Herunterladen
          </button>
          <button @click="closePreview" class="text-neutral-400 hover:text-ebony text-2xl leading-none px-1">×</button>
        </div>
        <div class="flex-1 bg-neutral-100 overflow-auto flex items-center justify-center">
          <div v-if="previewLoading" class="text-neutral-400 text-sm">Vorschau wird geladen…</div>
          <img v-else-if="previewIsImage && previewUrl" :src="previewUrl" class="max-h-full max-w-full object-contain" />
          <iframe v-else-if="previewUrl" :src="previewUrl" class="w-full h-full border-0"></iframe>
          <div v-else class="text-neutral-400 text-sm px-6 text-center">Keine Vorschau verfügbar – bitte herunterladen.</div>
        </div>
      </div>
    </div>

    <!-- Antwort verfassen -->
    <div v-if="replyOpen" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4 sm:p-8" @click.self="replyOpen = false">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl h-[88vh] flex flex-col overflow-hidden">
        <div class="flex items-center gap-2 px-4 py-2.5 border-b border-[#e6dad6] shrink-0">
          <Icon name="envelope" class="w-4 h-4 text-navy shrink-0" />
          <span class="text-[13px] font-semibold text-ebony">Antwort verfassen</span>
          <span class="text-[11px] text-neutral-400 truncate">· von {{ replyFrom }}</span>
          <button @click="replyOpen = false" class="ml-auto text-neutral-400 hover:text-ebony text-2xl leading-none px-1">×</button>
        </div>

        <!-- Kopf: Empfänger / Betreff -->
        <div class="px-4 pt-3 space-y-1.5 shrink-0">
          <div class="flex items-center gap-2">
            <span class="text-[11px] text-neutral-500 w-12 shrink-0">An</span>
            <input v-model="replyTo" class="flex-1 border border-[#e0d2cd] rounded-lg px-2 py-1 text-[12px]" placeholder="empfaenger@…" />
            <button v-if="!showCc" @click="showCc = true" class="text-[11px] text-coral shrink-0">+ CC</button>
          </div>
          <div v-if="showCc" class="flex items-center gap-2">
            <span class="text-[11px] text-neutral-500 w-12 shrink-0">CC</span>
            <input v-model="replyCc" class="flex-1 border border-[#e0d2cd] rounded-lg px-2 py-1 text-[12px]" placeholder="cc1@…, cc2@…" />
          </div>
          <div class="flex items-center gap-2">
            <span class="text-[11px] text-neutral-500 w-12 shrink-0">Betreff</span>
            <input v-model="replySubject" class="flex-1 border border-[#e0d2cd] rounded-lg px-2 py-1 text-[12px]" />
          </div>
        </div>

        <!-- Tabs -->
        <div class="px-4 pt-3 flex items-center gap-1 shrink-0">
          <button @click="replyTab = 'schreiben'" class="text-[12px] px-2.5 py-1 rounded-lg" :class="replyTab === 'schreiben' ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'">Schreiben</button>
          <button @click="replyTab = 'vorschau'" class="text-[12px] px-2.5 py-1 rounded-lg" :class="replyTab === 'vorschau' ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'">Vorschau</button>
          <button @click="draftReply" :disabled="replyBusy" class="ml-auto text-[11px] px-2 py-1 rounded-lg bg-coral/15 text-coral disabled:opacity-40">{{ replyBusy ? 'KI denkt…' : '✨ KI-Entwurf' }}</button>
        </div>

        <!-- Inhalt -->
        <div class="flex-1 min-h-0 overflow-y-auto px-4 py-3">
          <template v-if="replyTab === 'schreiben'">
            <textarea v-model="replyText" rows="8" placeholder="Antwort schreiben oder KI-Entwurf holen…" class="w-full border border-[#e0d2cd] px-3 py-2 text-[13px] bg-white leading-relaxed" :class="replySignature ? 'rounded-t-lg border-b-0' : 'rounded-lg'"></textarea>
            <!-- Signatur inline (ausgegraut), pro Nachricht anpassbar -->
            <div v-if="replySignature" class="border border-[#e0d2cd] rounded-b-lg bg-white px-3 py-2">
              <div class="flex items-center justify-between mb-1">
                <span class="text-[10px] uppercase tracking-wide text-neutral-400">Signatur (in dieser Nachricht)</span>
                <button @click="editSig = !editSig" class="text-[11px] text-coral font-medium">{{ editSig ? '✓ fertig' : '✏️ anpassen' }}</button>
              </div>
              <div v-if="!editSig" class="text-[12px] text-neutral-400 leading-snug" v-html="replySignature"></div>
              <textarea v-else v-model="replySignature" rows="6" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[12px] font-mono"></textarea>
            </div>

            <!-- Signatur wählen: Karten mit voller Vorschau -->
            <div class="mt-3">
              <div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1">Signatur wählen</div>
              <div class="flex gap-2 flex-wrap">
                <button v-for="s in replySignatures" :key="s.id" @click="pickSig(s)"
                  class="text-left border rounded-lg p-2 w-[210px] transition hover:border-coral"
                  :class="activeSigId === s.id ? 'border-coral ring-1 ring-coral/30 bg-coral/5' : 'border-[#e6dad6] bg-white'">
                  <div class="text-[11px] font-semibold text-navy mb-1">{{ s.name }}</div>
                  <div class="text-[10px] text-neutral-400 leading-snug max-h-28 overflow-hidden" v-html="s.html"></div>
                </button>
                <button @click="pickNoSig" class="border rounded-lg p-2 w-[110px] text-[11px] transition hover:border-coral"
                  :class="!replySignature ? 'border-coral ring-1 ring-coral/30 bg-coral/5 text-coral' : 'border-[#e6dad6] bg-white text-neutral-500'">Keine Signatur</button>
              </div>
            </div>

            <!-- Anhänge -->
            <div class="mt-3">
              <div class="flex items-center justify-between mb-1">
                <span class="text-[10px] uppercase tracking-wide text-neutral-400">Anhänge</span>
                <button @click="triggerReplyUpload" :disabled="taskUploading" class="text-[11px] text-coral font-medium disabled:opacity-50">{{ taskUploading ? 'Lädt hoch…' : '+ Datei hochladen' }}</button>
                <input ref="replyFileInput" type="file" multiple class="hidden" @change="onReplyFilePick" />
              </div>
              <div v-if="replyFiles.length" class="space-y-1">
                <label v-for="f in replyFiles" :key="f.id" class="flex items-center gap-2 text-[12px] bg-white border border-[#e6dad6] rounded-lg px-2 py-1.5 cursor-pointer hover:border-coral">
                  <input type="checkbox" :checked="replyFileIds.includes(f.id)" @change="toggleReplyFile(f.id)" class="accent-coral" />
                  <span class="w-6 h-6 rounded bg-navy/10 text-navy text-[9px] grid place-items-center font-semibold uppercase shrink-0">{{ f.ext }}</span>
                  <span class="truncate flex-1 text-ebony">{{ f.name }}</span>
                  <span class="text-[10px] text-neutral-300 shrink-0">{{ fmtFileSize(f.size) }}</span>
                </label>
              </div>
              <div v-else class="text-[11px] text-neutral-400">Keine Dateien — „+ Datei hochladen" hängt eine an.</div>
            </div>
          </template>
          <div v-else class="border border-[#e6dad6] rounded-lg p-4 bg-white" v-html="previewHtml"></div>
        </div>

        <!-- Footer -->
        <div class="px-4 py-3 border-t border-[#e6dad6] flex items-center gap-2 shrink-0">
          <span v-if="replyMsg" class="text-[11px] text-red-600">{{ replyMsg }}</span>
          <button @click="replyOpen = false" class="ml-auto text-[12px] px-3 py-1.5 rounded-lg text-neutral-600 hover:bg-beige">Abbrechen</button>
          <button @click="sendReply" :disabled="replyBusy || !replyText.trim() || !replyTo.trim()" class="text-[13px] px-4 py-1.5 rounded-lg bg-coral text-white font-medium disabled:opacity-50">{{ replyBusy ? 'Senden…' : (replyFileIds.length ? `✉️ Senden (${replyFileIds.length} Anh.)` : '✉️ Senden') }}</button>
        </div>
      </div>
    </div>

    <!-- Neue Aufgabe (volles Formular) -->
    <div v-if="newTaskOpen" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="newTaskOpen = false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden">
        <div class="flex items-center gap-2 px-5 py-3 border-b border-[#e6dad6]">
          <Icon name="plus" class="w-4 h-4 text-coral shrink-0" />
          <span class="text-[14px] font-semibold text-navy">Neue Aufgabe</span>
          <button @click="newTaskOpen = false" class="ml-auto text-neutral-400 hover:text-ebony text-2xl leading-none px-1">×</button>
        </div>
        <div class="px-5 py-4 overflow-y-auto space-y-3">
          <div>
            <label class="text-[10px] uppercase tracking-wide text-neutral-400">Titel *</label>
            <input v-model="nt.title" placeholder="Worum geht es?" @keyup.enter="saveNewTask"
              class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-3 py-2 text-[13px]" />
          </div>
          <div>
            <label class="text-[10px] uppercase tracking-wide text-neutral-400">Beschreibung</label>
            <textarea v-model="nt.description" rows="3" placeholder="Details, Kontext, Anweisungen…"
              class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-3 py-2 text-[13px] resize-y"></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-[10px] uppercase tracking-wide text-neutral-400">Status</label>
              <select v-model="nt.status" class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-2 py-2 text-[13px] bg-white">
                <option v-for="s in STATUS_KEYS" :key="s" :value="s">{{ STATUS[s] }}</option>
              </select>
            </div>
            <div>
              <label class="text-[10px] uppercase tracking-wide text-neutral-400">Priorität</label>
              <select v-model="nt.priority" class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-2 py-2 text-[13px] bg-white">
                <option v-for="p in PRIO_KEYS" :key="p" :value="p">{{ PRIO[p] }}</option>
              </select>
            </div>
            <div>
              <label class="text-[10px] uppercase tracking-wide text-neutral-400">Zuständig</label>
              <select v-model="nt.assigneeId" class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-2 py-2 text-[13px] bg-white">
                <option value="">— Unzugewiesen —</option>
                <option v-for="p in team" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div>
              <label class="text-[10px] uppercase tracking-wide text-neutral-400">Fällig</label>
              <input v-model="nt.dueDate" type="date" class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-2 py-2 text-[13px] bg-white" />
            </div>
          </div>
          <div>
            <label class="text-[10px] uppercase tracking-wide text-neutral-400">Kunde</label>
            <select v-model="nt.companyId" class="mt-1 w-full border border-[#e0d2cd] rounded-lg px-2 py-2 text-[13px] bg-white">
              <option value="">— Kein Kunde —</option>
              <option v-for="co in companies" :key="co.id" :value="co.id">{{ co.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-[10px] uppercase tracking-wide text-neutral-400">Tags</label>
            <div class="mt-1 flex flex-wrap gap-1">
              <button v-for="tag in TAGS" :key="tag" type="button" @click="ntToggleTag(tag)" class="text-[11px] px-2 py-1 rounded" :style="nt.tags.includes(tag) ? badgeStyle(tag) : 'background:#f0e7e3;color:#b7a9a3'">{{ tag }}</button>
            </div>
          </div>
          <label v-if="nt.assigneeId !== '' && Number(nt.assigneeId) !== (auth.me?.id ?? -1)" class="flex items-center gap-2 text-[12px] text-neutral-600">
            <input v-model="nt.notify" type="checkbox" class="rounded" /> Zuständigen per E-Mail benachrichtigen
          </label>
        </div>
        <div class="px-5 py-3 border-t border-[#e6dad6] flex items-center gap-2">
          <button @click="newTaskOpen = false" class="ml-auto text-[12px] px-3 py-1.5 rounded-lg text-neutral-600 hover:bg-beige">Abbrechen</button>
          <button @click="saveNewTask" :disabled="ntSaving || !nt.title.trim()" class="text-[13px] px-4 py-1.5 rounded-lg bg-coral text-white font-medium disabled:opacity-50">{{ ntSaving ? 'Anlegen…' : 'Aufgabe anlegen' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
