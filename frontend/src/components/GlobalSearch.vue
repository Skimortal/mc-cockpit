<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import Icon from './Icon.vue'

const router = useRouter()
const q = ref('')
const open = ref(false)
const res = ref<{ tasks: any[]; conversations: any[]; companies: any[] }>({ tasks: [], conversations: [], companies: [] })
let timer: ReturnType<typeof setTimeout> | null = null

function hasResults() {
  return res.value.tasks.length || res.value.conversations.length || res.value.companies.length
}
function onInput() {
  open.value = true
  if (timer) clearTimeout(timer)
  timer = setTimeout(run, 220)
}
async function run() {
  if (q.value.trim().length < 2) {
    res.value = { tasks: [], conversations: [], companies: [] }
    return
  }
  res.value = (await api.get('/api/search', { params: { q: q.value } })).data
}
function go(path: string, query: Record<string, any>) {
  open.value = false
  q.value = ''
  router.push({ path, query })
}
function onBlur() {
  setTimeout(() => (open.value = false), 150)
}
</script>

<template>
  <div class="relative w-full">
    <div class="flex items-center gap-2 bg-white/10 rounded-lg px-3 py-1.5 focus-within:bg-white/20 transition">
      <Icon name="search" class="w-4 h-4 text-white/60 shrink-0" />
      <input v-model="q" @input="onInput" @focus="open = true" @blur="onBlur" @keyup.escape="open = false"
        placeholder="Suchen über Aufgaben, Mails, Kunden…"
        class="bg-transparent text-[13px] text-white placeholder-white/50 outline-none w-full" />
    </div>
    <div v-if="open && q.trim().length >= 2"
      class="absolute left-0 right-0 mt-1 bg-white rounded-xl shadow-2xl border border-[#e6dad6] max-h-[70vh] overflow-y-auto z-50 text-ebony">
      <div v-if="!hasResults()" class="px-3 py-3 text-[12px] text-neutral-400">Keine Treffer.</div>
      <template v-else>
        <div v-if="res.tasks.length" class="py-1">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Aufgaben</div>
          <button v-for="t in res.tasks" :key="'t' + t.id" @mousedown.prevent="go('/aufgaben', { conv: t.conversationId })"
            class="w-full text-left px-3 py-1.5 hover:bg-beige-soft flex items-center gap-2">
            <Icon name="tasks" class="w-4 h-4 text-navy shrink-0" /><span class="text-[13px] truncate">{{ t.title }}</span>
          </button>
        </div>
        <div v-if="res.conversations.length" class="py-1 border-t border-[#f0e7e3]">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Mails</div>
          <button v-for="c in res.conversations" :key="'c' + c.id" @mousedown.prevent="go('/aufgaben', { conv: c.id })"
            class="w-full text-left px-3 py-1.5 hover:bg-beige-soft flex items-center gap-2">
            <Icon name="envelope" class="w-4 h-4 text-navy shrink-0" /><span class="text-[13px] truncate">{{ c.subject }}</span>
            <span class="text-[11px] text-neutral-400 ml-auto shrink-0 truncate max-w-[40%]">{{ c.from }}</span>
          </button>
        </div>
        <div v-if="res.companies.length" class="py-1 border-t border-[#f0e7e3]">
          <div class="px-3 pt-1.5 pb-0.5 text-[10px] uppercase tracking-wide text-neutral-400">Kunden</div>
          <button v-for="co in res.companies" :key="'co' + co.id" @mousedown.prevent="go('/kunden', { company: co.id })"
            class="w-full text-left px-3 py-1.5 hover:bg-beige-soft flex items-center gap-2">
            <Icon name="building" class="w-4 h-4 text-navy shrink-0" /><span class="text-[13px] truncate">{{ co.name }}</span>
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
