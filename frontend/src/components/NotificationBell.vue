<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'
import Icon from './Icon.vue'

interface Notif { id: number; type: string; text: string; taskId: number | null; conversationId: number | null; read: boolean; createdAt: string }

const router = useRouter()
const open = ref(false)
const unread = ref(0)
const items = ref<Notif[]>([])
let timer: ReturnType<typeof setInterval> | undefined

async function load() {
  try {
    const { data } = await api.get('/api/notifications')
    unread.value = data.unread
    items.value = data.items
  } catch {
    /* still im Hintergrund */
  }
}
async function openItem(n: Notif) {
  open.value = false
  if (!n.read) {
    n.read = true
    unread.value = Math.max(0, unread.value - 1)
    api.post(`/api/notifications/${n.id}/read`).catch(() => {})
  }
  if (n.conversationId) router.push({ path: '/aufgaben', query: { conv: n.conversationId } })
}
async function markAll() {
  await api.post('/api/notifications/read-all').catch(() => {})
  items.value.forEach((n) => (n.read = true))
  unread.value = 0
}
function toggle() {
  open.value = !open.value
  if (open.value) load()
}
function onBlur() {
  setTimeout(() => (open.value = false), 200)
}
function fmt(s: string) {
  const [d, t] = s.split(' ')
  const [, mo, day] = d.split('-')
  return `${day}.${mo}. ${t ?? ''}`.trim()
}

onMounted(() => {
  load()
  timer = setInterval(load, 60000)
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <div class="relative">
    <button @click="toggle" @blur="onBlur" class="relative w-9 h-9 rounded-lg flex items-center justify-center text-white/80 hover:bg-white/10" title="Benachrichtigungen">
      <Icon name="bell" class="w-5 h-5" />
      <span v-if="unread > 0" class="absolute top-1 right-1 min-w-[16px] h-[16px] px-1 rounded-full bg-coral text-white text-[10px] font-bold flex items-center justify-center">{{ unread > 9 ? '9+' : unread }}</span>
    </button>
    <div v-if="open" class="absolute right-0 mt-1 w-80 bg-white rounded-xl shadow-2xl border border-[#e6dad6] z-50 text-ebony overflow-hidden">
      <div class="flex items-center justify-between px-3 py-2 border-b border-[#f0e7e3]">
        <span class="text-[12px] font-semibold text-navy">Benachrichtigungen</span>
        <button v-if="unread > 0" @mousedown.prevent="markAll" class="text-[11px] text-coral hover:underline">Alle gelesen</button>
      </div>
      <div class="max-h-[60vh] overflow-y-auto">
        <div v-if="!items.length" class="px-3 py-6 text-center text-[12px] text-neutral-400">Keine Benachrichtigungen.</div>
        <button v-for="n in items" :key="n.id" @mousedown.prevent="openItem(n)"
          class="w-full text-left px-3 py-2 hover:bg-beige-soft flex gap-2 border-b border-[#f6efeb] last:border-0"
          :class="n.read ? 'opacity-60' : ''">
          <span class="mt-0.5 w-2 h-2 rounded-full shrink-0" :class="n.read ? 'bg-transparent' : 'bg-coral'"></span>
          <div class="min-w-0">
            <div class="text-[12.5px] leading-snug">{{ n.text }}</div>
            <div class="text-[10.5px] text-neutral-400 mt-0.5">{{ fmt(n.createdAt) }}</div>
          </div>
        </button>
      </div>
    </div>
  </div>
</template>
