<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import AppTopbar from '../components/AppTopbar.vue'

const auth = useAuth()

interface Mailbox { id: number; name: string; email: string; scope: string; mine: boolean }
interface Conv { id: number; from: string; email: string; subject: string; lastMessageAt: string; messageCount: number; state: string; taskId: number | null; owner: string | null; mailboxName: string; mailboxScope: string }
interface Person { id: number; name: string }
interface Comment { author: string; body: string; createdAt: string }
interface Task {
  id: number; title: string; type: string; status: string; priority: string; dueDate: string | null
  aiSummary: string | null; suggestedAssignee: string | null; assignee: Person | null
  conversationId: number | null; companyName: string | null; tags: string[]; comments: Comment[]
}
interface Msg { dir: string; who: string; to: string; time: string; body: string }
interface ConvDetail { id: number; subject: string; customerName: string; customerEmail: string; taskId: number | null; messages: Msg[] }

const TAGS = ['Ausschreibung', 'Muster', 'Reklamation', 'Labor', 'Logistik', 'Rechnung', 'Etikett', 'ASN', 'Allgemein']
const STATUS: Record<string, string> = { open: 'Offen', in_progress: 'In Arbeit', waiting: 'Wartet', done: 'Erledigt' }
const STATUS_KEYS = ['open', 'in_progress', 'waiting', 'done']

const mailboxes = ref<Mailbox[]>([])
const mailboxFilter = ref<string>('') // '' = alle | mailbox id
const inboxFilter = ref<'alle' | 'neu'>('alle')
const convs = ref<Conv[]>([])
const tasks = ref<Task[]>([])
const team = ref<Person[]>([])
const group = ref<'person' | 'status'>('person')
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

const selTask = computed(() => tasks.value.find((t) => t.conversationId === selConvId.value) || null)

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

function boardColumns() {
  const active = tasks.value.filter((t) => t.status !== 'done' || showDone.value)
  if (group.value === 'person') {
    return [
      { name: 'Unzugewiesen', alarm: true, type: 'person', val: '', items: active.filter((t) => !t.assignee) },
      ...team.value.map((p) => ({ name: p.name, alarm: false, type: 'person', val: String(p.id), items: active.filter((t) => t.assignee?.id === p.id) })),
    ]
  }
  return STATUS_KEYS.map((s) => ({ name: STATUS[s], alarm: false, type: 'status', val: s, items: active.filter((t) => t.status === s) }))
}

async function loadInbox() {
  const q = mailboxFilter.value ? `?mailbox=${mailboxFilter.value}` : ''
  convs.value = (await api.get('/api/inbox' + q)).data
}
async function loadBoard() {
  tasks.value = (await api.get('/api/board')).data
}
async function loadAll() {
  loading.value = true
  const [mb, tm] = await Promise.all([api.get('/api/mailboxes'), api.get('/api/team')])
  mailboxes.value = mb.data
  team.value = tm.data
  await Promise.all([loadInbox(), loadBoard()])
  loading.value = false
}

async function selectConv(id: number) {
  selConvId.value = id
  replyText.value = ''; replyMsg.value = ''; commentText.value = ''
  detail.value = (await api.get(`/api/conversations/${id}`)).data
  openMsgs.value = new Set([(detail.value?.messages.length ?? 1) - 1])
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
  } finally { converting.value = false }
}
async function assign(task: Task, userId: number | '') {
  await api.post(`/api/tasks/${task.id}/assign`, { userId: userId === '' ? null : userId })
  await Promise.all([loadBoard(), loadInbox()])
}
async function setStatus(task: Task, status: string) {
  await api.post(`/api/tasks/${task.id}/status`, { status })
  await Promise.all([loadBoard(), loadInbox()])
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
async function draftReply() {
  if (!selTask.value) return
  replyBusy.value = true; replyMsg.value = ''
  try {
    replyText.value = (await api.post(`/api/tasks/${selTask.value.id}/draft-reply`)).data.draft
  } catch { replyMsg.value = 'KI-Entwurf fehlgeschlagen.' } finally { replyBusy.value = false }
}
async function sendReply() {
  if (!selTask.value || !replyText.value.trim()) return
  replyBusy.value = true; replyMsg.value = ''
  try {
    const { data } = await api.post(`/api/tasks/${selTask.value.id}/reply`, { body: replyText.value })
    replyMsg.value = `Gesendet an ${data.to}.`
    replyText.value = ''
    if (selConvId.value) await selectConv(selConvId.value)
    await loadBoard()
  } catch (e: any) {
    replyMsg.value = 'Senden fehlgeschlagen: ' + (e?.response?.data?.error ?? 'unbekannt')
  } finally { replyBusy.value = false }
}

function onDrop(col: { type: string; val: string }) {
  const t = tasks.value.find((x) => x.id === dragId.value)
  dragId.value = null
  if (!t) return
  if (col.type === 'person') assign(t, col.val === '' ? '' : Number(col.val))
  else setStatus(t, col.val)
}

watch(mailboxFilter, loadInbox)
onMounted(async () => {
  if (!auth.me) await auth.fetchMe().catch(() => {})
  await loadAll()
})
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar>
      <div class="ml-3 flex items-center gap-2 text-[12px]">
        <div class="flex items-center bg-white/10 rounded-lg p-0.5">
          <button @click="group = 'person'" class="px-2 py-1 rounded" :class="group === 'person' ? 'bg-white text-navy' : 'text-white/70'">Person</button>
          <button @click="group = 'status'" class="px-2 py-1 rounded" :class="group === 'status' ? 'bg-white text-navy' : 'text-white/70'">Status</button>
        </div>
        <button @click="loadAll" class="text-white/70 hover:text-white">⟳ Aktualisieren</button>
      </div>
    </AppTopbar>

    <div class="flex-1 flex min-h-0">
      <!-- Posteingang -->
      <div class="w-[330px] shrink-0 bg-beige-soft border-r border-[#e6dad6] flex flex-col">
        <!-- Postfach-Switcher -->
        <div class="px-2.5 pt-2.5 pb-1.5 flex flex-wrap gap-1 border-b border-[#ece1dc]">
          <button @click="mailboxFilter = ''" class="text-[11px] px-2 py-1 rounded" :class="mailboxFilter === '' ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'">Alle</button>
          <button v-for="m in mailboxes" :key="m.id" @click="mailboxFilter = String(m.id)"
            class="text-[11px] px-2 py-1 rounded flex items-center gap-1"
            :class="mailboxFilter === String(m.id) ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'">
            <span class="w-1.5 h-1.5 rounded-full" :style="m.scope === 'global' ? 'background:#414c65' : 'background:#eb5d4f'"></span>
            {{ m.name }}
          </button>
        </div>
        <div class="px-3 pt-2 pb-1 flex items-center gap-1 text-[11px]">
          <button @click="inboxFilter = 'alle'" class="px-2 py-1 rounded" :class="inboxFilter === 'alle' ? 'bg-coral text-white' : 'text-neutral-500 hover:bg-beige'">Alle</button>
          <button @click="inboxFilter = 'neu'" class="px-2 py-1 rounded" :class="inboxFilter === 'neu' ? 'bg-coral text-white' : 'text-neutral-500 hover:bg-beige'">ohne Aufgabe</button>
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
        <div class="px-5 pt-3 pb-2 flex items-center gap-3">
          <span class="text-[10px] uppercase tracking-wider text-neutral-400">Aufgaben — nach {{ group === 'person' ? 'Person' : 'Status' }}</span>
          <button @click="showDone = !showDone" class="text-[10px] px-2 py-0.5 rounded" :class="showDone ? 'bg-navy text-white' : 'bg-white text-neutral-500 border border-[#e6dad6]'">{{ showDone ? 'erledigte ausblenden' : 'erledigte zeigen' }}</button>
        </div>
        <div class="flex-1 overflow-x-auto px-5 pb-4">
          <div class="flex gap-4 h-full">
            <div v-for="col in boardColumns()" :key="col.name" class="dropcol w-56 shrink-0 rounded-xl transition"
              @dragover.prevent="($event.currentTarget as HTMLElement).classList.add('over')"
              @dragleave="($event.currentTarget as HTMLElement).classList.remove('over')"
              @drop="($event.currentTarget as HTMLElement).classList.remove('over'); onDrop(col)">
              <div class="flex items-center justify-between mb-2 px-1">
                <span class="text-[12px] font-semibold" :class="col.alarm && col.items.length ? 'text-coral' : 'text-navy'">{{ col.alarm ? '⚠ ' : '' }}{{ col.name }}</span>
                <span class="text-[10px] px-1.5 rounded-full bg-coral/15 text-coral">{{ col.items.length }}</span>
              </div>
              <div class="space-y-2 min-h-[64px]">
                <article v-for="t in col.items" :key="t.id" draggable="true"
                  @dragstart="dragId = t.id" @dragend="dragId = null"
                  @click="t.conversationId && selectConv(t.conversationId)"
                  class="relative bg-white border border-[#e6dad6] rounded-xl p-2.5 shadow-sm hover:shadow-md cursor-pointer">
                  <button @click.stop="setStatus(t, 'done')" title="Als erledigt markieren"
                    class="absolute top-1.5 right-1.5 w-5 h-5 rounded-full bg-beige-soft hover:bg-[#3f9d6b] hover:text-white text-neutral-400 text-[11px] leading-none border border-[#e6dad6] flex items-center justify-center">✓</button>
                  <div class="flex items-center gap-1.5 mb-1.5 pr-6 flex-wrap">
                    <span v-for="tag in t.tags.slice(0, 2)" :key="tag" class="text-[10px] px-1.5 py-0.5 rounded" :style="badgeStyle(tag)">{{ tag }}</span>
                    <span v-if="t.priority === 'high'" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f;color:#fff">Hoch</span>
                    <span v-if="t.dueDate" class="ml-auto text-[10px] text-neutral-400">⏱ {{ t.dueDate.slice(5) }}</span>
                  </div>
                  <div class="text-[12.5px] leading-snug text-ebony">{{ t.title }}</div>
                </article>
                <div v-if="!col.items.length" class="text-[11px] text-neutral-300 px-1 py-5 text-center border-2 border-dashed border-[#e6dad6] rounded-lg">hierher ziehen</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Detail -->
      <div class="w-[380px] shrink-0 bg-white border-l border-[#e6dad6] flex flex-col">
        <div v-if="!detail" class="h-full flex items-center justify-center text-center text-neutral-400 text-sm px-6">Wähle links eine Konversation<br>oder eine Aufgabe.</div>
        <template v-else>
          <div class="px-4 py-3 border-b border-[#e6dad6] flex items-start gap-2">
            <div class="min-w-0">
              <div class="text-[14px] font-semibold text-navy leading-tight truncate">{{ detail.subject }}</div>
              <div class="text-[11px] text-neutral-400 mt-0.5">{{ detail.customerName || detail.customerEmail }}</div>
            </div>
            <button @click="detail = null; selConvId = null" class="ml-auto text-neutral-400 hover:text-neutral-700 text-xl leading-none">×</button>
          </div>

          <div class="px-4 py-3 overflow-y-auto flex-1">
            <!-- Thread -->
            <div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1.5">Konversation · {{ detail.messages.length }} Nachricht(en)</div>
            <div v-for="(m, i) in detail.messages" :key="i" class="border rounded-lg mb-2 overflow-hidden" :class="m.dir === 'out' ? 'border-coral/30' : 'border-[#e6dad6]'">
              <div @click="toggleMsg(i)" class="flex items-center gap-2 px-3 py-2 cursor-pointer" :class="m.dir === 'out' ? 'bg-coral/5' : 'bg-beige-soft'">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-semibold shrink-0 text-white" :style="m.dir === 'out' ? 'background:#eb5d4f' : 'background:#414c65'">{{ m.dir === 'out' ? '✓' : (m.who || '?').slice(0, 2).toUpperCase() }}</span>
                <div class="min-w-0 flex-1">
                  <div class="text-[12px] font-semibold leading-tight" :class="m.dir === 'out' ? 'text-coral' : 'text-navy'">{{ m.dir === 'out' ? 'Wir' : m.who }}</div>
                  <div v-if="!openMsgs.has(i)" class="text-[11px] text-neutral-400 truncate">{{ m.body.slice(0, 80) }}</div>
                </div>
                <span class="text-[10px] text-neutral-400 shrink-0">{{ m.time.slice(5, 16) }}</span>
              </div>
              <div v-if="openMsgs.has(i)" class="px-3 py-2.5 text-[12.5px] text-neutral-700 leading-relaxed whitespace-pre-wrap border-t" :class="m.dir === 'out' ? 'border-coral/20' : 'border-[#efe4df]'">{{ m.body }}</div>
            </div>

            <!-- Aufgabe -->
            <div v-if="selTask" class="mt-3 p-3 rounded-xl bg-beige-soft border border-[#e6dad6]">
              <div class="text-[13px] font-semibold text-ebony">{{ selTask.title }}</div>
              <div v-if="selTask.aiSummary" class="mt-1 p-2 rounded-lg bg-coral/10 text-[12px] text-[#8a3328]"><b>KI:</b> {{ selTask.aiSummary }}</div>
              <div class="mt-2 flex items-center gap-2 text-[11px]">
                <span class="text-neutral-500">Zuständig:</span>
                <select :value="selTask.assignee?.id ?? ''" @change="assign(selTask, ($event.target as HTMLSelectElement).value === '' ? '' : Number(($event.target as HTMLSelectElement).value))" class="border border-[#e0d2cd] rounded-lg px-2 py-1 text-[11px] bg-white">
                  <option value="">— Unzugewiesen —</option>
                  <option v-for="p in team" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
              </div>
              <div class="mt-2 flex items-center gap-1 flex-wrap">
                <button v-for="s in STATUS_KEYS" :key="s" @click="setStatus(selTask, s)" class="text-[10px] px-2 py-1 rounded-lg" :class="selTask.status === s ? 'bg-coral text-white' : 'bg-white text-neutral-600 border border-[#e6dad6]'">{{ STATUS[s] }}</button>
              </div>
              <button v-if="selTask.status !== 'done'" @click="setStatus(selTask, 'done')" class="mt-2 w-full py-2 rounded-xl text-white text-sm font-medium" style="background:#3f9d6b">✓ Aufgabe erledigt</button>
              <!-- Tags -->
              <div class="mt-3"><div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1">Tags</div>
                <div class="flex flex-wrap gap-1">
                  <button v-for="tag in TAGS" :key="tag" @click="toggleTag(selTask, tag)" class="text-[10px] px-1.5 py-0.5 rounded" :style="selTask.tags.includes(tag) ? badgeStyle(tag) : 'background:#f0e7e3;color:#b7a9a3'">{{ tag }}</button>
                </div>
              </div>
              <!-- Kommentare -->
              <div class="mt-3"><div class="text-[10px] uppercase tracking-wide text-neutral-400 mb-1">Kommentare</div>
                <div v-for="(k, i) in selTask.comments" :key="i" class="text-[11px] mb-1">
                  <span class="font-semibold text-navy">{{ k.author }}</span> <span class="text-neutral-400">{{ k.createdAt.slice(5, 16) }}</span><br>
                  <span class="text-neutral-600 whitespace-pre-wrap">{{ k.body }}</span>
                </div>
                <div class="flex gap-1 mt-1">
                  <input v-model="commentText" placeholder="Kommentar…" @keyup.enter="addComment(selTask)" class="flex-1 border border-[#e0d2cd] rounded-lg px-2 py-1 text-[11px]" />
                  <button @click="addComment(selTask)" class="text-[11px] px-2 py-1 rounded-lg bg-navy text-white">+</button>
                </div>
              </div>
            </div>
            <div v-else class="mt-3">
              <button @click="convertToTask" :disabled="converting" class="w-full py-2.5 rounded-xl bg-coral text-white text-sm font-medium hover:bg-coral-dark disabled:opacity-50">{{ converting ? '✨ KI fasst zusammen…' : '✨ In Aufgabe umwandeln' }}</button>
            </div>
          </div>

          <!-- Antwort -->
          <div class="px-4 py-3 border-t border-[#e6dad6] bg-beige-soft">
            <div class="flex items-center justify-between mb-1.5">
              <span class="text-[12px] font-semibold text-navy">Antwort an {{ detail.customerName || detail.customerEmail }}</span>
              <button @click="draftReply" :disabled="replyBusy || !selTask" class="text-[11px] px-2 py-1 rounded-lg bg-coral/15 text-coral disabled:opacity-40" :title="!selTask ? 'Erst in Aufgabe umwandeln' : ''">{{ replyBusy ? 'KI denkt…' : '✨ KI-Entwurf' }}</button>
            </div>
            <textarea v-model="replyText" rows="4" placeholder="Antwort schreiben oder KI-Entwurf holen…" class="w-full border border-[#e0d2cd] rounded-lg px-3 py-2 text-[12px] bg-white"></textarea>
            <div class="mt-1.5 flex items-center justify-between">
              <span class="text-[11px]" :class="replyMsg.startsWith('Gesendet') ? 'text-green-600' : 'text-neutral-500'">{{ replyMsg }}</span>
              <button @click="sendReply" :disabled="replyBusy || !replyText.trim()" class="text-[12px] px-3 py-1.5 rounded-lg bg-coral text-white font-medium disabled:opacity-50">Senden</button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
