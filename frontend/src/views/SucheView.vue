<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'

interface Conv { id: number; subject: string; from: string | null; hasTask: boolean; date: string | null; mailbox: string | null; snippet: string | null }
interface TaskHit { id: number; title: string; status: string | null; conversationId: number | null }
interface CompanyHit { id: number; name: string; subtitle: string | null }

const route = useRoute()
const router = useRouter()
const q = ref(String(route.query.q ?? ''))
const loading = ref(false)
const res = ref<{ tasks: TaskHit[]; conversations: Conv[]; companies: CompanyHit[] }>({ tasks: [], conversations: [], companies: [] })
let timer: ReturnType<typeof setTimeout> | null = null

function total() {
  return res.value.tasks.length + res.value.conversations.length + res.value.companies.length
}
async function run() {
  const term = q.value.trim()
  if (term.length < 2) {
    res.value = { tasks: [], conversations: [], companies: [] }
    return
  }
  loading.value = true
  try {
    res.value = (await api.get('/api/search', { params: { q: term, limit: 40 } })).data
  } finally {
    loading.value = false
  }
}
function onInput() {
  if (timer) clearTimeout(timer)
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

watch(() => route.query.q, (v) => {
  const s = String(v ?? '')
  if (s !== q.value) {
    q.value = s
    run()
  }
})
onMounted(run)
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar />
    <div class="flex-1 overflow-y-auto bg-beige-soft">
      <div class="max-w-3xl mx-auto px-6 py-6">
        <div class="flex items-center gap-2 bg-white border border-[#e0d2cd] rounded-xl px-3 py-2 shadow-sm">
          <Icon name="search" class="w-4 h-4 text-neutral-400 shrink-0" />
          <input v-model="q" @input="onInput" autofocus placeholder="Suchen über Aufgaben, Mails (inkl. Anhänge), Kunden…"
            class="flex-1 bg-transparent text-[14px] text-ebony outline-none" />
        </div>

        <div v-if="loading" class="text-[13px] text-neutral-400 mt-6 px-1">Sucht…</div>
        <div v-else-if="q.trim().length >= 2 && total() === 0" class="text-[13px] text-neutral-400 mt-6 px-1">Keine Treffer für „{{ q }}".</div>

        <!-- MAILS -->
        <section v-if="res.conversations.length" class="mt-6">
          <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400 mb-2 px-1">
            <Icon name="envelope" class="w-4 h-4" /> Mails <span class="text-coral">{{ res.conversations.length }}</span>
          </h2>
          <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
            <button v-for="c in res.conversations" :key="c.id" @click="router.push({ path: '/aufgaben', query: { conv: c.id } })"
              class="w-full text-left px-4 py-2.5 hover:bg-beige-soft block">
              <div class="flex items-center gap-2">
                <span class="text-[13.5px] font-semibold text-navy truncate">{{ c.subject || '(ohne Betreff)' }}</span>
                <span v-if="c.hasTask" class="text-[10px] px-1.5 py-0.5 rounded bg-coral/15 text-coral shrink-0">Aufgabe</span>
                <span class="text-[11px] text-neutral-400 ml-auto shrink-0">{{ fmtDate(c.date) }}</span>
              </div>
              <div class="text-[11.5px] text-neutral-500 mt-0.5 flex items-center gap-1.5">
                <span class="truncate">{{ c.from }}</span>
                <span v-if="c.mailbox" class="text-neutral-300">·</span>
                <span v-if="c.mailbox" class="text-neutral-400 truncate">{{ c.mailbox }}</span>
              </div>
              <div v-if="c.snippet" class="text-[12px] text-neutral-500 mt-1 leading-snug line-clamp-2">…{{ c.snippet }}…</div>
            </button>
          </div>
        </section>

        <!-- AUFGABEN -->
        <section v-if="res.tasks.length" class="mt-6">
          <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400 mb-2 px-1">
            <Icon name="tasks" class="w-4 h-4" /> Aufgaben <span class="text-coral">{{ res.tasks.length }}</span>
          </h2>
          <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
            <button v-for="t in res.tasks" :key="t.id" @click="t.conversationId && router.push({ path: '/aufgaben', query: { conv: t.conversationId } })"
              class="w-full text-left px-4 py-2.5 hover:bg-beige-soft flex items-center gap-2" :disabled="!t.conversationId">
              <span class="text-[13.5px] text-ebony truncate">{{ t.title }}</span>
              <span class="text-[11px] text-neutral-400 ml-auto shrink-0">{{ fmtStatus(t.status) }}</span>
            </button>
          </div>
        </section>

        <!-- KUNDEN -->
        <section v-if="res.companies.length" class="mt-6">
          <h2 class="flex items-center gap-2 text-[12px] uppercase tracking-wider text-neutral-400 mb-2 px-1">
            <Icon name="building" class="w-4 h-4" /> Kunden <span class="text-coral">{{ res.companies.length }}</span>
          </h2>
          <div class="bg-white border border-[#e6dad6] rounded-xl divide-y divide-[#f0e7e3] overflow-hidden">
            <button v-for="co in res.companies" :key="co.id" @click="router.push({ path: '/kunden', query: { company: co.id } })"
              class="w-full text-left px-4 py-2.5 hover:bg-beige-soft flex items-center gap-2">
              <Icon name="building" class="w-4 h-4 text-navy shrink-0" />
              <span class="text-[13.5px] text-ebony truncate">{{ co.name }}</span>
              <span v-if="co.subtitle" class="text-[11.5px] text-neutral-400 truncate">· {{ co.subtitle }}</span>
            </button>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
