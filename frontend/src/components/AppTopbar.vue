<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'
import Icon from './Icon.vue'
import GlobalSearch from './GlobalSearch.vue'
import NotificationBell from './NotificationBell.vue'

const auth = useAuth()
const route = useRoute()
const router = useRouter()

const initials = computed(() =>
  (auth.me?.name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase(),
)

function logout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <header class="bg-navy text-white flex items-center gap-2 px-4 py-2 shrink-0 shadow-md">
    <RouterLink to="/aufgaben" title="Zu den Aufgaben">
      <img src="/most-weiss.png" class="h-5 mr-1" alt="MOST Connect" />
    </RouterLink>

    <nav class="flex items-center gap-1">
      <RouterLink to="/aufgaben" class="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded-lg transition"
        :class="route.path === '/aufgaben' ? 'bg-white/15 text-white' : 'text-white/65 hover:bg-white/10 hover:text-white'">
        <Icon name="tasks" class="w-4 h-4" /> Aufgaben
      </RouterLink>
      <RouterLink to="/kunden" class="flex items-center gap-1.5 text-[13px] px-3 py-1.5 rounded-lg transition"
        :class="route.path === '/kunden' ? 'bg-white/15 text-white' : 'text-white/65 hover:bg-white/10 hover:text-white'">
        <Icon name="building" class="w-4 h-4" /> Kunden
      </RouterLink>
    </nav>

    <div class="flex-1 flex justify-center px-3">
      <GlobalSearch class="w-full max-w-md" />
    </div>

    <div class="flex items-center gap-1.5">
      <NotificationBell />
      <RouterLink to="/einstellungen" title="Einstellungen"
        class="w-9 h-9 rounded-lg grid place-items-center transition"
        :class="route.path === '/einstellungen' ? 'bg-white/15 text-white' : 'text-white/65 hover:bg-white/10 hover:text-white'">
        <Icon name="cog" class="w-[18px] h-[18px]" />
      </RouterLink>

      <div class="w-px h-5 bg-white/15 mx-1"></div>

      <div class="flex items-center gap-2 pl-1">
        <span class="w-7 h-7 rounded-full bg-coral grid place-items-center text-[11px] font-semibold">{{ initials }}</span>
        <span class="text-[13px] text-white/90 hidden md:inline">{{ auth.me?.name }}</span>
      </div>

      <button @click="logout" title="Abmelden" class="w-9 h-9 rounded-lg grid place-items-center text-white/65 hover:bg-white/10 hover:text-white transition">
        <Icon name="logout" class="w-[18px] h-[18px]" />
      </button>
    </div>
  </header>
</template>
