<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import Icon from './Icon.vue'
import { hlHtml } from '../composables/highlight'

const router = useRouter()
const q = ref('')
const open = ref(false)
const active = ref(-1)
const inputEl = ref<HTMLInputElement | null>(null)
const emptyRes = { tasks: [], conversations: [], companies: [], documents: [] }
const res = ref<{ tasks: any[]; conversations: any[]; companies: any[]; documents: any[] }>({ ...emptyRes })
let timer: ReturnType<typeof setTimeout> | null = null

const flat = computed(() => [
  ...res.value.tasks.map((t) => ({ kind: 'task', item: t })),
  ...res.value.conversations.map((c) => ({ kind: 'conv', item: c })),
  ...res.value.companies.map((co) => ({ kind: 'company', item: co })),
  ...res.value.documents.map((d) => ({ kind: 'document', item: d })),
])
function hasResults() {
  return flat.value.length > 0
}
function isActive(kind: string, id: number) {
  const f = flat.value[active.value]
  return !!f && f.kind === kind && f.item.id === id
}
function onInput() {
  open.value = true
  if (timer) clearTimeout(timer)
  timer = setTimeout(run, 200)
}
async function run() {
  if (q.value.trim().length < 2) {
    res.value = { ...emptyRes }
    active.value = -1
    return
  }
  res.value = (await api.get('/api/search', { params: { q: q.value } })).data
  active.value = -1
}
function go(path: string, query: Record<string, any>) {
  open.value = false
  q.value = ''
  router.push({ path, query })
}
function goAll() {
  const term = q.value.trim()
  if (term.length < 2) return
  open.value = false
  q.value = ''
  router.push({ path: '/suche', query: { q: term } })
}
function openItem(f: { kind: string; item: any }) {
  if (f.kind === 'task') {
    if (f.item.conversationId) go('/aufgaben', { conv: f.item.conversationId })
  } else if (f.kind === 'conv') {
    const c = f.item
    go('/aufgaben', c.attachment?.preview ? { conv: c.id, att: c.attachment.id } : { conv: c.id })
  } else if (f.kind === 'document') {
    openDoc(f.item)
  } else {
    go('/kunden', { company: f.item.id })
  }
}
async function openDoc(d: any) {
  open.value = false
  q.value = ''
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
function move(d: number) {
  open.value = true
  const n = flat.value.length
  if (!n) return
  active.value = Math.max(-1, Math.min(n - 1, active.value + d))
}
function onEnter() {
  const f = flat.value[active.value]
  if (f) openItem(f)
  else goAll()
}
function onBlur() {
  setTimeout(() => (open.value = false), 150)
}
function focusSearch() {
  open.value = true
  inputEl.value?.focus()
  inputEl.value?.select()
}
function onGlobalKey(e: KeyboardEvent) {
  const el = e.target as HTMLElement | null
  const typing = !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)
  if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) {
    e.preventDefault()
    focusSearch()
  } else if (e.key === '/' && !typing) {
    e.preventDefault()
    focusSearch()
  }
}
onMounted(() => window.addEventListener('keydown', onGlobalKey))
onUnmounted(() => window.removeEventListener('keydown', onGlobalKey))
</script>

<template>
  <div class="relative w-full">
    <div class="flex items-center gap-2 bg-white/10 rounded-lg px-3 py-1.5 focus-within:bg-white/20 transition">
      <Icon name="search" class="w-4 h-4 text-white/60 shrink-0" />
      <input ref="inputEl" v-model="q" @input="onInput" @focus="open = true" @blur="onBlur"
        @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keyup.enter="onEnter" @keyup.escape="open = false"
        placeholder="Suchen…  (⌘/Ctrl+K · Enter = alle Treffer)"
        class="bg-transparent text-[13px] text-white placeholder-white/50 outline-none w-full" />
      <kbd class="hidden sm:block text-[10px] text-white/40 border border-white/20 rounded px-1 shrink-0">⌘K</kbd>
    </div>
    <div v-if="open && q.trim().length >= 2"
      class="absolute left-0 right-0 mt-1 bg-white rounded-xl shadow-2xl border border-[#e6dad6] max-h-[70vh] overflow-y-auto z-50 text-ebony">
      <div v-if="!hasResults()" class="px-3 py-3 text-[12px] text-neutral-400">Keine Treffer.</div>
      <template v-else>
        <div v-if="res.tasks.length" class="py-1">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Aufgaben</div>
          <button v-for="t in res.tasks" :key="'t' + t.id" @mousedown.prevent="openItem({ kind: 'task', item: t })"
            class="w-full text-left px-3 py-1.5 flex items-center gap-2" :class="isActive('task', t.id) ? 'bg-beige' : 'hover:bg-beige-soft'">
            <Icon name="tasks" class="w-4 h-4 text-navy shrink-0" /><span class="text-[13px] truncate" v-html="hlHtml(t.titleHl || t.title)"></span>
          </button>
        </div>
        <div v-if="res.conversations.length" class="py-1 border-t border-[#f0e7e3]">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Mails</div>
          <button v-for="c in res.conversations" :key="'c' + c.id" @mousedown.prevent="openItem({ kind: 'conv', item: c })"
            class="w-full text-left px-3 py-1.5 block" :class="isActive('conv', c.id) ? 'bg-beige' : 'hover:bg-beige-soft'">
            <div class="flex items-center gap-2">
              <Icon name="envelope" class="w-4 h-4 text-navy shrink-0" />
              <span class="text-[13px] truncate" v-html="hlHtml(c.subjectHl || c.subject)"></span>
              <Icon v-if="c.attachment?.preview" name="paperclip" class="w-3.5 h-3.5 text-coral shrink-0" />
              <span class="text-[11px] text-neutral-400 ml-auto shrink-0 truncate max-w-[35%]">{{ c.from }}</span>
            </div>
            <div v-if="c.snippet" class="text-[11px] text-neutral-400 mt-0.5 ml-6 truncate" v-html="hlHtml(c.snippet)"></div>
          </button>
        </div>
        <div v-if="res.companies.length" class="py-1 border-t border-[#f0e7e3]">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Kunden</div>
          <button v-for="co in res.companies" :key="'co' + co.id" @mousedown.prevent="openItem({ kind: 'company', item: co })"
            class="w-full text-left px-3 py-1.5 flex items-center gap-2" :class="isActive('company', co.id) ? 'bg-beige' : 'hover:bg-beige-soft'">
            <Icon name="building" class="w-4 h-4 text-navy shrink-0" /><span class="text-[13px] truncate" v-html="hlHtml(co.nameHl || co.name)"></span>
          </button>
        </div>
        <div v-if="res.documents.length" class="py-1 border-t border-[#f0e7e3]">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Dokumente</div>
          <button v-for="d in res.documents" :key="'d' + d.kind + d.id" @mousedown.prevent="openItem({ kind: 'document', item: d })"
            class="w-full text-left px-3 py-1.5 flex items-center gap-2" :class="isActive('document', d.id) ? 'bg-beige' : 'hover:bg-beige-soft'">
            <Icon name="paperclip" class="w-4 h-4 text-navy shrink-0" />
            <span class="text-[13px] truncate" v-html="hlHtml(d.nameHl || d.name)"></span>
            <span v-if="d.companyName" class="text-[11px] text-neutral-400 ml-auto shrink-0 truncate max-w-[35%]">{{ d.companyName }}</span>
          </button>
        </div>
      </template>
      <button @mousedown.prevent="goAll" class="w-full text-left px-3 py-2 text-[12px] text-coral hover:bg-beige-soft border-t border-[#f0e7e3] font-medium">
        Alle Ergebnisse anzeigen →
      </button>
    </div>
  </div>
</template>
