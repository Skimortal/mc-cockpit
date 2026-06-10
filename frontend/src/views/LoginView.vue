<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'

const auth = useAuth()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/aufgaben')
  } catch {
    error.value = 'Login fehlgeschlagen – E-Mail oder Passwort prüfen.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex">
    <!-- Markenpanel (links) -->
    <div class="hidden md:flex md:w-1/2 lg:w-3/5 bg-navy text-white relative overflow-hidden">
      <!-- große, dezente Bildmarke -->
      <img src="/most-mark.png" class="absolute -right-16 -bottom-16 w-[28rem] opacity-10 select-none" alt="" />
      <div class="relative z-10 flex flex-col justify-between p-12 w-full">
        <img src="/most-weiss.png" class="h-7" alt="MOST Connect" />
        <div>
          <h2 class="font-head text-[34px] leading-tight tracking-wide">Ein Cockpit.</h2>
          <p class="text-white/70 text-[15px] mt-3 max-w-sm">Posteingang, Aufgaben und Kunden an einem Ort — nichts fällt mehr durch.</p>
        </div>
        <div class="text-white/40 text-[12px]">MOST Connect KG · Cockpit</div>
      </div>
    </div>

    <!-- Login-Formular (rechts) -->
    <div class="flex-1 flex items-center justify-center bg-beige px-6">
      <form @submit.prevent="submit" class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-sm border border-[#e6dad6]">
        <img src="/most-rotorange.png" class="h-6 mb-6 md:hidden" alt="MOST Connect" />
        <h1 class="font-head text-[22px] text-ebony tracking-wide">Anmelden</h1>
        <p class="text-neutral-500 text-[13px] mt-1 mb-6">Willkommen zurück im Cockpit.</p>

        <label class="block text-[12px] font-medium text-neutral-600 mb-1">E-Mail</label>
        <input v-model="email" type="email" autocomplete="username"
          class="w-full border border-[#e0d2cd] rounded-lg px-3 py-2.5 mb-4 text-[14px] text-ebony focus:outline-none focus:ring-2 focus:ring-coral/40 focus:border-coral" />

        <label class="block text-[12px] font-medium text-neutral-600 mb-1">Passwort</label>
        <input v-model="password" type="password" autocomplete="current-password" @keyup.enter="submit"
          class="w-full border border-[#e0d2cd] rounded-lg px-3 py-2.5 mb-4 text-[14px] text-ebony focus:outline-none focus:ring-2 focus:ring-coral/40 focus:border-coral" />

        <p v-if="error" class="text-red-600 text-[13px] mb-3">{{ error }}</p>

        <button type="submit" :disabled="loading"
          class="w-full bg-coral hover:bg-coral-dark disabled:opacity-50 text-white font-medium py-2.5 rounded-lg transition">
          {{ loading ? 'Anmelden…' : 'Anmelden' }}
        </button>
      </form>
    </div>
  </div>
</template>
