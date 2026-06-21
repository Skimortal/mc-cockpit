<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'
import DocThumb from '../components/DocThumb.vue'
import { hlHtml } from '../composables/highlight'

interface Conv { id: number; subject: string; subjectHl?: string; from: string | null; hasTask: boolean; date: string | null; mailbox: string | null; snippet: string | null }
interface TaskHit { id: number; title: string; titleHl?: string; status: string | null; conversationId: number | null; snippet?: string | null }
interface CompanyHit { id: number; name: string; nameHl?: string; subtitle: string | null; snippet?: string | null }
interface DocHit { kind: 'document' | 'attachment'; id: number; name: string; nameHl?: string; type: string | null; companyName: string | null; snippet?: string | null; preview: boolean; pruned: boolean; conversationId: number | null }
interface Counts { tasks: number; conversations: number; companies: number; documents: number }

type SectionKey = 'mails' | 'documents' | 'aufgaben' | 'kunden'
const PREVIEW = 6
const LIMIT = 50

const route = useRoute()
const router = useRouter()
const q = ref(String(route.query.q ?? ''))
const loading = ref(false)
const expanded = ref<SectionKey | null>(null)
const empty = { tasks: [], conversations: [], companies: [], documents: [], counts: { tasks: 0, conversations: 0, companies: 0, documents: 0 } }
const res = ref<{ tasks: TaskHit[]; conversations: Conv[]; companies: CompanyHit[]; documents: DocHit[]; counts: Counts }>({ ...empty })
let timer: ReturnType<typeof setTimeout> | null = null

function total() {
  const c = res.value.counts
  return c.tasks + c.conversations + c.companies + c.documents
}
async function run() {
  const term = q.value.trim()
  if (term.length < 2) {
    res.value = { ...empty }
    return
  }
  loading.value = true
  try {
    res.value = (await api.get('/api/search', { params: { q: term, limit: LIMIT } })).data
  } finally {
    loading.value = false
  }
}
function onInput() {
  if (timer) clearTimeout(timer)
  expanded.value = null
  timer = setTimeout(() => {
    router.replace({ path: '/suche', query: { q: q.value.trim() } })
    run()
  }, 250)
}
function fmtDate(s: string | null) {
  if (!s) return ''
  const [d, t] = s.split(' ')
  const [, mo, day] = d.split('-')
  return `${day}.${mo}. ${t ?? ''}`.trim()
}
function fmtStatus(s: string | null) {
  return s === 'done' ? 'erledigt' : s === 'in_progress' ? 'in Arbeit' : 'offen'
}
function openConv(c: Conv | { id: number }) {
  router.push({ path: '/aufgaben', query: { conv: c.id } })
}

// Klick: Vorschau im neuen Tab (Tab synchron öffnen wg. Popup-Blocker), sonst Download.
async function openDoc(d: DocHit) {
  if (d.pruned) return
  const base = d.kind === 'attachment' ? 'attachments' : 'documents'
  const win = d.preview ? window.open('', '_blank') : null
  try {
    const r = await api.get(`/api/${base}/${d.id}/${d.preview ? 'preview' : 'download'}`, { responseType: 'blob' })
    const url = URL.createObjectURL(r.data as Blob)
    if (d.preview && win) {
      win.location.href = url
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } else {
      const link = document.createElement('a')
      link.href = url
      link.download = d.name
      link.click()
      URL.revokeObjectURL(url)
    }
  } catch {
    if (win) win.close()
  }
}

// pro Bereich: in der Übersicht die ersten PREVIEW, im aufgeklappten Modus alle
function show(key: SectionKey): boolean {
  return expanded.value === null || expanded.value === key
}
function limited<T>(key: SectionKey, list: T[]): T[] {
  return expanded.value === key ? list : list.slice(0, PREVIEW)
}
// Mails nach Postfach gruppieren (Untertitel nur, wenn mehrere Postfächer betroffen sind)
const mailGroups = computed(() => {
  const m = new Map<string, Conv[]>()
  for (const c of limited('mails', res.value.conversations)) {
    const k = c.mailbox || '—'
    if (!m.has(k)) m.set(k, [])
    m.get(k)!.push(c)
  }
  return Array.from(m, ([mailbox, items]) => ({ mailbox, items }))
})

watch(() => route.query.q, (v) => {
  const s = String(v ?? '')
  if (s !== q.value) {
    q.value = s
    expanded.value = null
    run()
  }
})
onMounted(run)
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar />
    <div class="flex-1 overflow-y-auto bg-beige-soft">
      <div class="max-w-[1600px] mx-auto px-6 py-6">
        <div class="flex items-center gap-2 bg-white border border-[#e0d2cd] rounded-xl px-3 py-2 shadow-sm max-w-3xl">
          <Icon name="search" class="w-4 h-4 text-neutral-400 shrink-0" />
          <input v-model="q" @input="onInput" autofocus placeholder="Suchen über Mails, Dokumente, Aufgaben, Kunden…"
            class="flex-1 bg-transparent text-[14px] text-ebony outline-none" />
        </div>

        <div v-if="expanded" class="mt-3">
          <button @click="expanded = null" class="text-[12px] text-coral font-medium">← zurück zur Übersicht</button>
        </div>

        <div v-if="loading" class="text-[13px] text-neutral-400 mt-6 px-1">Sucht…</div>
        <div v-else-if="q.trim().length >= 2 && total() === 0" class="text-[13px] text-neutral-400 mt-6 px-1">Keine Treffer für „{{ q }}".</div>

        <!-- Bereiche: mehrspaltig (Masonry) in der Übersicht, einspaltig wenn ein Bereich aufgeklappt -->
        <div class="mt-5" :class="expanded ? '' : 'gap-5 columns-1 md:columns-2 2xl:columns-3 [&>section]:break-inside-avoid [&>section]:mb-5'">

          <!-- MAILS -->
          <section v-if="res.conversations.length && show('mails')">
            <div class="flex items-center justify-between mb-2 px-1">
              <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400">
                <Icon name="envelope" class="w-4 h-4" /> Mails <span class="text-coral">{{ res.counts.conversations }}</span>
              </h2>
              <button v-if="expanded !== 'mails' && res.counts.conversations > PREVIEW" @click="expanded = 'mails'" class="text-[12px] text-coral font-medium">Alle {{ res.counts.conversations }} →</button>
            </div>
            <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
              <template v-for="g in mailGroups" :key="g.mailbox">
                <div v-if="mailGroups.length > 1" class="px-4 py-1.5 bg-beige-soft text-[10px] uppercase tracking-wide text-navy/70 flex items-center gap-1.5">
                  <Icon name="envelope" class="w-3.5 h-3.5" /> {{ g.mailbox }} <span class="text-coral">{{ g.items.length }}</span>
                </div>
                <button v-for="c in g.items" :key="c.id" @click="openConv(c)"
                  class="w-full text-left px-4 py-2.5 hover:bg-beige-soft block">
                  <div class="flex items-center gap-2">
                    <span v-if="c.subject" class="text-[13.5px] font-semibold text-navy truncate" v-html="hlHtml(c.subjectHl || c.subject)"></span>
                    <span v-else class="text-[13.5px] font-semibold text-neutral-400 truncate">(ohne Betreff)</span>
                    <span v-if="c.hasTask" class="text-[10px] px-1.5 py-0.5 rounded bg-coral/15 text-coral shrink-0">Aufgabe</span>
                    <span class="text-[11px] text-neutral-400 ml-auto shrink-0">{{ fmtDate(c.date) }}</span>
                  </div>
                  <div class="text-[11.5px] text-neutral-500 mt-0.5 flex items-center gap-1.5">
                    <span class="truncate">{{ c.from }}</span>
                    <span v-if="c.mailbox && mailGroups.length <= 1" class="text-neutral-300">·</span>
                    <span v-if="c.mailbox && mailGroups.length <= 1" class="text-neutral-400 truncate">{{ c.mailbox }}</span>
                  </div>
                  <div v-if="c.snippet" class="text-[12px] text-neutral-500 mt-1 leading-snug line-clamp-2" v-html="hlHtml(c.snippet)"></div>
                </button>
              </template>
            </div>
          </section>

          <!-- DOKUMENTE (Uploads + Anhänge) -->
          <section v-if="res.documents.length && show('documents')">
            <div class="flex items-center justify-between mb-2 px-1">
              <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400">
                <Icon name="paperclip" class="w-4 h-4" /> Dokumente <span class="text-coral">{{ res.counts.documents }}</span>
              </h2>
              <button v-if="expanded !== 'documents' && res.counts.documents > PREVIEW" @click="expanded = 'documents'" class="text-[12px] text-coral font-medium">Alle {{ res.counts.documents }} →</button>
            </div>
            <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
              <button v-for="d in limited('documents', res.documents)" :key="d.kind + d.id" @click="openDoc(d)" :disabled="d.pruned"
                class="w-full text-left px-4 py-2.5 hover:bg-beige-soft flex gap-3 disabled:hover:bg-transparent disabled:cursor-default">
                <!-- Thumbnail (Vorschau vor dem Klick) mit Typ-Badge als Fallback -->
                <DocThumb v-if="d.preview && !d.pruned" :kind="d.kind" :id="d.id" class="w-28 h-36">
                  <span class="text-[12px] font-semibold uppercase text-coral">{{ d.type || (d.kind === 'attachment' ? '✉' : 'DOC') }}</span>
                </DocThumb>
                <span v-else class="w-28 h-36 shrink-0 rounded border border-[#e6dad6] bg-beige-soft grid place-items-center text-[12px] font-semibold uppercase text-neutral-400">{{ d.type || (d.kind === 'attachment' ? '✉' : 'DOC') }}</span>

                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2">
                    <span class="text-[13.5px] text-ebony truncate" :class="d.pruned ? 'line-through text-neutral-400' : ''" v-html="hlHtml(d.nameHl || d.name)"></span>
                    <span v-if="d.pruned" class="text-[11px] text-neutral-400 ml-auto shrink-0">· im Archiv</span>
                    <span v-else class="text-[11px] text-coral ml-auto shrink-0">{{ d.preview ? 'Vorschau' : 'Download' }}</span>
                  </div>
                  <div class="text-[11.5px] text-neutral-500 mt-0.5 flex items-center gap-1.5">
                    <Icon :name="d.kind === 'attachment' ? 'envelope' : 'building'" class="w-3.5 h-3.5 text-neutral-300 shrink-0" />
                    <span class="truncate">{{ d.kind === 'attachment' ? 'Mail-Anhang' : 'Dokument' }}{{ d.companyName ? ' · ' + d.companyName : '' }}</span>
                  </div>
                  <div v-if="d.snippet" class="text-[12px] text-neutral-500 mt-1 leading-snug line-clamp-2" v-html="hlHtml(d.snippet)"></div>
                </div>
              </button>
            </div>
          </section>

          <!-- AUFGABEN -->
          <section v-if="res.tasks.length && show('aufgaben')">
            <div class="flex items-center justify-between mb-2 px-1">
              <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400">
                <Icon name="tasks" class="w-4 h-4" /> Aufgaben <span class="text-coral">{{ res.counts.tasks }}</span>
              </h2>
              <button v-if="expanded !== 'aufgaben' && res.counts.tasks > PREVIEW" @click="expanded = 'aufgaben'" class="text-[12px] text-coral font-medium">Alle {{ res.counts.tasks }} →</button>
            </div>
            <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
              <button v-for="t in limited('aufgaben', res.tasks)" :key="t.id" @click="t.conversationId && openConv({ id: t.conversationId })"
                class="w-full text-left px-4 py-2.5 hover:bg-beige-soft block" :disabled="!t.conversationId">
                <div class="flex items-center gap-2">
                  <span class="text-[13.5px] text-ebony truncate" v-html="hlHtml(t.titleHl || t.title)"></span>
                  <span class="text-[11px] text-neutral-400 ml-auto shrink-0">{{ fmtStatus(t.status) }}</span>
                </div>
                <div v-if="t.snippet" class="text-[12px] text-neutral-500 mt-0.5 leading-snug line-clamp-1" v-html="hlHtml(t.snippet)"></div>
              </button>
            </div>
          </section>

          <!-- KUNDEN -->
          <section v-if="res.companies.length && show('kunden')">
            <div class="flex items-center justify-between mb-2 px-1">
              <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400">
                <Icon name="building" class="w-4 h-4" /> Kunden <span class="text-coral">{{ res.counts.companies }}</span>
              </h2>
              <button v-if="expanded !== 'kunden' && res.counts.companies > PREVIEW" @click="expanded = 'kunden'" class="text-[12px] text-coral font-medium">Alle {{ res.counts.companies }} →</button>
            </div>
            <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
              <button v-for="co in limited('kunden', res.companies)" :key="co.id" @click="router.push({ path: '/kunden', query: { company: co.id } })"
                class="w-full text-left px-4 py-2.5 hover:bg-beige-soft block">
                <div class="flex items-center gap-2">
                  <Icon name="building" class="w-4 h-4 text-navy shrink-0" />
                  <span class="text-[13.5px] text-ebony truncate" v-html="hlHtml(co.nameHl || co.name)"></span>
                  <span v-if="co.subtitle" class="text-[11.5px] text-neutral-400 truncate">· {{ co.subtitle }}</span>
                </div>
                <div v-if="co.snippet" class="text-[12px] text-neutral-500 mt-0.5 leading-snug line-clamp-1 pl-6" v-html="hlHtml(co.snippet)"></div>
              </button>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>
