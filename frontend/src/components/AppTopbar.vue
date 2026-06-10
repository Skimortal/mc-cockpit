<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'

const auth = useAuth()
const route = useRoute()
const router = useRouter()

function logout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <header class="bg-navy text-white flex items-center gap-3 px-5 py-2.5 shrink-0">
    <img src="/most-weiss.png" class="h-5" alt="MOST Connect" />
    <nav class="flex items-center gap-1 ml-1">
      <RouterLink to="/aufgaben" class="text-[13px] px-3 py-1 rounded-lg"
        :class="route.path === '/aufgaben' ? 'bg-white text-navy font-semibold' : 'text-white/70 hover:bg-white/10'">Aufgaben</RouterLink>
      <RouterLink to="/kunden" class="text-[13px] px-3 py-1 rounded-lg"
        :class="route.path === '/kunden' ? 'bg-white text-navy font-semibold' : 'text-white/70 hover:bg-white/10'">Kunden</RouterLink>
    </nav>
    <slot />
    <div class="ml-auto flex items-center gap-3 text-[13px]">
      <RouterLink to="/einstellungen" class="hover:opacity-100" :class="route.path === '/einstellungen' ? 'text-white font-semibold' : 'text-white/75'">⚙ Einstellungen</RouterLink>
      <span class="opacity-90">{{ auth.me?.name }}</span>
      <button @click="logout" class="opacity-70 hover:opacity-100">Abmelden</button>
    </div>
  </header>
</template>
