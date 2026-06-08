<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import { useAuth } from '../stores/auth'

interface Person { id: number; name: string }
interface Task {
  id: number
  title: string
  type: string
  status: string
  priority: string
  dueDate: string | null
  aiSummary: string | null
  suggestedAssignee: string | null
  assignee: Person | null
  conversationId: number | null
  companyName: string | null
  tenderName: string | null
  source: { subject: string; from: string; occurredAt: string; bodyText: string } | null
}

const auth = useAuth()
const router = useRouter()
const tasks = ref<Task[]>([])
const team = ref<Person[]>([])
const selected = ref<Task | null>(null)
const loading = ref(true)

const statusCols = [
  { key: 'open', label: 'Offen' },
  { key: 'in_progress', label: 'In Arbeit' },
  { key: 'waiting', label: 'Wartet' },
  { key: 'done', label: 'Erledigt' },
]
const typeLabels: Record<string, string> = {
  general: 'Allgemein', send_sample: 'Muster', deadline: 'Frist', send_asn: 'ASN',
  label_manhattan: 'Etikett', logistics: 'Logistik', lab: 'Labor',
}
const typeColors: Record<string, string> = {
  general: 'bg-slate-100 text-slate-600', send_sample: 'bg-amber-100 text-amber-700',
  deadline: 'bg-rose-100 text-rose-700', send_asn: 'bg-sky-100 text-sky-700',
  label_manhattan: 'bg-violet-100 text-violet-700', logistics: 'bg-teal-100 text-teal-700',
  lab: 'bg-emerald-100 text-emerald-700',
}
const prioBadge: Record<string, string> = {
  high: 'bg-red-100 text-red-700', normal: 'bg-slate-100 text-slate-500', low: 'bg-slate-50 text-slate-400',
}
const prioLabel: Record<string, string> = { high: 'Hoch', normal: 'Normal', low: 'Niedrig' }

const byStatus = computed(() => {
  const m: Record<string, Task[]> = { open: [], in_progress: [], waiting: [], done: [] }
  for (const t of tasks.value) (m[t.status] ?? m.open).push(t)
  return m
})

async function load() {
  loading.value = true
  const [b, tm] = await Promise.all([api.get('/api/board'), api.get('/api/team')])
  tasks.value = b.data
  team.value = tm.data
  loading.value = false
}

async function assign(task: Task, userId: number | '') {
  const { data } = await api.post(`/api/tasks/${task.id}/assign`, { userId: userId === '' ? null : userId })
  Object.assign(task, data)
}
async function setStatus(task: Task, status: string) {
  const { data } = await api.post(`/api/tasks/${task.id}/status`, { status })
  Object.assign(task, data)
  if (selected.value?.id === task.id) selected.value = { ...task }
}
function logout() {
  auth.logout()
  router.push('/login')
}

onMounted(async () => {
  if (!auth.me) await auth.fetchMe().catch(() => {})
  await load()
})
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <!-- Top bar -->
    <header class="bg-white border-b border-slate-200 px-6 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <span class="font-semibold text-slate-800">MOST Connect Cockpit</span>
        <span class="text-slate-400 text-sm">· Aufgaben</span>
      </div>
      <div class="flex items-center gap-3">
        <button @click="load" class="text-sm text-slate-600 hover:text-slate-900">Aktualisieren</button>
        <span class="text-sm text-slate-500">{{ auth.me?.name }}</span>
        <button @click="logout" class="text-sm text-slate-500 hover:text-red-600">Abmelden</button>
      </div>
    </header>

    <!-- Board -->
    <main class="flex-1 overflow-x-auto p-4">
      <div v-if="loading" class="text-slate-400 p-8">Lädt…</div>
      <div v-else class="grid grid-cols-1 md:grid-cols-4 gap-4 min-w-[900px]">
        <section v-for="col in statusCols" :key="col.key" class="bg-slate-100 rounded-xl p-3">
          <h2 class="text-sm font-semibold text-slate-600 mb-3 flex items-center justify-between">
            {{ col.label }}
            <span class="text-xs bg-slate-200 text-slate-500 rounded-full px-2">{{ byStatus[col.key].length }}</span>
          </h2>

          <div class="space-y-2">
            <article v-for="t in byStatus[col.key]" :key="t.id"
                     @click="selected = t"
                     class="bg-white rounded-lg p-3 shadow-sm hover:shadow cursor-pointer border border-transparent hover:border-indigo-200 transition">
              <div class="flex items-center gap-2 mb-1.5">
                <span class="text-[11px] px-1.5 py-0.5 rounded" :class="typeColors[t.type]">{{ typeLabels[t.type] ?? t.type }}</span>
                <span v-if="t.priority !== 'normal'" class="text-[11px] px-1.5 py-0.5 rounded" :class="prioBadge[t.priority]">{{ prioLabel[t.priority] }}</span>
                <span v-if="t.dueDate" class="text-[11px] text-slate-400 ml-auto">⏱ {{ t.dueDate }}</span>
              </div>
              <p class="text-sm text-slate-800 leading-snug">{{ t.title }}</p>
              <div class="mt-2 flex items-center justify-between">
                <span class="text-[11px] text-slate-400 truncate max-w-[60%]" :title="t.source?.subject">
                  {{ t.source?.from }}
                </span>
                <span v-if="t.assignee" class="text-[11px] bg-indigo-50 text-indigo-700 rounded-full px-2 py-0.5">{{ t.assignee.name }}</span>
                <span v-else-if="t.suggestedAssignee" class="text-[11px] text-slate-400 italic">→ {{ t.suggestedAssignee }}?</span>
              </div>
            </article>
            <p v-if="!byStatus[col.key].length" class="text-xs text-slate-400 px-1 py-3">—</p>
          </div>
        </section>
      </div>
    </main>

    <!-- Detail drawer -->
    <transition name="slide">
      <aside v-if="selected" class="fixed inset-y-0 right-0 w-full max-w-xl bg-white shadow-2xl border-l border-slate-200 flex flex-col">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="text-xs px-1.5 py-0.5 rounded" :class="typeColors[selected.type]">{{ typeLabels[selected.type] ?? selected.type }}</span>
            <span class="text-xs px-1.5 py-0.5 rounded" :class="prioBadge[selected.priority]">{{ prioLabel[selected.priority] }}</span>
          </div>
          <button @click="selected = null" class="text-slate-400 hover:text-slate-700 text-xl leading-none">×</button>
        </div>

        <div class="px-5 py-4 overflow-y-auto flex-1">
          <h2 class="text-lg font-semibold text-slate-800">{{ selected.title }}</h2>
          <p v-if="selected.dueDate" class="text-sm text-slate-500 mt-1">Frist: {{ selected.dueDate }}</p>
          <p v-if="selected.tenderName" class="text-sm text-slate-500">Ausschreibung: {{ selected.tenderName }}</p>
          <p v-if="selected.companyName" class="text-sm text-slate-500">Firma: {{ selected.companyName }}</p>

          <div v-if="selected.aiSummary" class="mt-3 bg-indigo-50 text-indigo-900 text-sm rounded-lg p-3">
            <span class="font-medium">KI-Zusammenfassung:</span> {{ selected.aiSummary }}
          </div>

          <!-- Zuweisen + Status -->
          <div class="mt-4 flex flex-wrap items-center gap-3">
            <label class="text-sm text-slate-600">Zuständig:</label>
            <select :value="selected.assignee?.id ?? ''" @change="assign(selected, ($event.target as HTMLSelectElement).value === '' ? '' : Number(($event.target as HTMLSelectElement).value))"
                    class="border border-slate-300 rounded-lg px-2 py-1 text-sm">
              <option value="">— niemand —</option>
              <option v-for="p in team" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <span v-if="!selected.assignee && selected.suggestedAssignee" class="text-xs text-slate-400">Vorschlag: {{ selected.suggestedAssignee }}</span>
          </div>
          <div class="mt-3 flex items-center gap-2">
            <span class="text-sm text-slate-600">Status:</span>
            <button v-for="s in statusCols" :key="s.key" @click="setStatus(selected, s.key)"
                    class="text-xs px-2 py-1 rounded-lg border"
                    :class="selected.status === s.key ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'">
              {{ s.label }}
            </button>
          </div>

          <!-- Quell-Mail -->
          <div v-if="selected.source" class="mt-5 border-t border-slate-200 pt-4">
            <h3 class="text-sm font-semibold text-slate-700 mb-1">Quell-E-Mail</h3>
            <p class="text-xs text-slate-500">Von {{ selected.source.from }} · {{ selected.source.occurredAt }}</p>
            <p class="text-sm font-medium text-slate-700 mt-1">{{ selected.source.subject }}</p>
            <pre class="text-sm text-slate-600 whitespace-pre-wrap font-sans mt-2 bg-slate-50 rounded-lg p-3 max-h-80 overflow-y-auto">{{ selected.source.bodyText }}</pre>
          </div>
        </div>

        <div class="px-5 py-3 border-t border-slate-200 text-xs text-slate-400">
          Antwort-aus-der-Aufgabe folgt im nächsten Schritt.
        </div>
      </aside>
    </transition>
  </div>
</template>

<style scoped>
.slide-enter-active, .slide-leave-active { transition: transform 0.2s ease; }
.slide-enter-from, .slide-leave-to { transform: translateX(100%); }
</style>
