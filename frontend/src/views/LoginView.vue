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
    router.push('/')
  } catch {
    error.value = 'Login fehlgeschlagen – E-Mail oder Passwort prüfen.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-100">
    <form @submit.prevent="submit" class="bg-white p-8 rounded-2xl shadow-lg w-full max-w-sm">
      <h1 class="text-2xl font-semibold text-slate-800 mb-1">MOST Connect Cockpit</h1>
      <p class="text-slate-500 text-sm mb-6">Bitte anmelden</p>

      <label class="block text-sm font-medium text-slate-600 mb-1">E-Mail</label>
      <input v-model="email" type="email" autocomplete="username"
             class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-400" />

      <label class="block text-sm font-medium text-slate-600 mb-1">Passwort</label>
      <input v-model="password" type="password" autocomplete="current-password"
             class="w-full border border-slate-300 rounded-lg px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-400" />

      <p v-if="error" class="text-red-600 text-sm mb-3">{{ error }}</p>

      <button type="submit" :disabled="loading"
              class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-medium py-2 rounded-lg transition">
        {{ loading ? 'Anmelden…' : 'Anmelden' }}
      </button>
    </form>
  </div>
</template>
