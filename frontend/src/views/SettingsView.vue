<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../api'
import { useAuth } from '../stores/auth'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'
import { confirmDialog, promptDialog, alertDialog } from '../composables/dialog'

const auth = useAuth()
const isAdmin = computed(() => auth.me?.roles?.includes('ROLE_ADMIN') ?? false)
const tab = ref<'profile' | 'mailboxes' | 'users'>('profile')
const profileForm = reactive({ firstName: '', lastName: '', email: '' })
const profileMsg = ref('')
const pwForm = reactive({ newPw: '', repeat: '' })
const showPw = ref(false)
const pwMsg = ref('')

interface MB { id: number; name: string; email: string; scope: string; owner: { id: number; name: string } | null; imapHost: string; imapPort: number; imapEncryption: string; smtpHost: string; smtpPort: number; smtpEncryption: string; username: string; active: boolean; hasPassword: boolean }
interface UserRow { id: number; email: string; firstName: string; lastName: string; name: string; isAdmin: boolean }

const mailboxes = ref<MB[]>([])
const users = ref<UserRow[]>([])
const personalBoxes = computed(() => mailboxes.value.filter((m) => m.scope === 'personal'))
const globalBoxes = computed(() => mailboxes.value.filter((m) => m.scope === 'global'))

const showForm = ref(false)
const formId = ref<number | null>(null)
const form = reactive<any>({})
function blankForm(scope: string) {
  return { scope, name: '', email: '', imapHost: 'mail.world4you.com', imapPort: 993, imapEncryption: 'ssl', smtpHost: 'smtp.world4you.com', smtpPort: 587, smtpEncryption: 'tls', username: '', password: '', active: true }
}
function openNew(scope: string) { formId.value = null; Object.assign(form, blankForm(scope)); showForm.value = true }
function openEdit(m: MB) { formId.value = m.id; Object.assign(form, { ...m, password: '' }); showForm.value = true }
async function saveForm() {
  const body = { ...form }
  if (!body.password) delete body.password
  if (formId.value) await api.patch(`/api/mailboxes/${formId.value}`, body)
  else await api.post('/api/mailboxes', body)
  showForm.value = false
  await loadMailboxes()
}
async function delMailbox(m: MB) {
  if (!(await confirmDialog(`Postfach „${m.name}" löschen?`, { title: 'Postfach löschen', danger: true, okText: 'Löschen' }))) return
  await api.delete(`/api/mailboxes/${m.id}`)
  await loadMailboxes()
}

const showUserForm = ref(false)
const userForm = reactive<any>({ email: '', firstName: '', lastName: '', password: '', admin: false })
async function saveUser() {
  if (!userForm.email || !userForm.password) return
  await api.post('/api/users', { ...userForm })
  Object.assign(userForm, { email: '', firstName: '', lastName: '', password: '', admin: false })
  showUserForm.value = false
  await loadUsers()
}
async function toggleAdmin(u: UserRow) { await api.patch(`/api/users/${u.id}`, { admin: !u.isAdmin }); await loadUsers() }
async function resetPw(u: UserRow) {
  const r = await promptDialog([{ key: 'pw', label: `Neues Passwort für ${u.name}`, type: 'password' }], { title: 'Passwort zurücksetzen' })
  if (r && r.pw) { await api.patch(`/api/users/${u.id}`, { password: r.pw }); await alertDialog('Passwort gesetzt.') }
}
async function delUser(u: UserRow) {
  if (!(await confirmDialog(`Benutzer „${u.name}" löschen?`, { title: 'Benutzer löschen', danger: true, okText: 'Löschen' }))) return
  await api.delete(`/api/users/${u.id}`)
  await loadUsers()
}

async function saveProfile() {
  profileMsg.value = ''
  try {
    const { data } = await api.patch('/api/me', { firstName: profileForm.firstName, lastName: profileForm.lastName, email: profileForm.email })
    auth.me = data
    profileMsg.value = 'Gespeichert.'
  } catch (e: any) {
    profileMsg.value = e?.response?.data?.error ?? 'Fehler beim Speichern.'
  }
}
async function savePassword() {
  pwMsg.value = ''
  if (pwForm.newPw.length < 6) { pwMsg.value = 'Mindestens 6 Zeichen.'; return }
  if (pwForm.newPw !== pwForm.repeat) { pwMsg.value = 'Passwörter stimmen nicht überein.'; return }
  try {
    await api.patch('/api/me', { password: pwForm.newPw })
    pwForm.newPw = ''
    pwForm.repeat = ''
    pwMsg.value = 'Passwort geändert.'
  } catch (e: any) {
    pwMsg.value = e?.response?.data?.error ?? 'Fehler beim Ändern.'
  }
}
async function patchUser(id: number, body: Record<string, unknown>) {
  try { await api.patch(`/api/users/${id}`, body); await loadUsers() } catch (e: any) { await alertDialog(e?.response?.data?.error ?? 'Fehler') }
}
async function editUser(u: UserRow) {
  const r = await promptDialog([
    { key: 'firstName', label: 'Vorname', value: u.firstName },
    { key: 'lastName', label: 'Nachname', value: u.lastName },
    { key: 'email', label: 'E-Mail', value: u.email },
  ], { title: 'Benutzer bearbeiten' })
  if (!r) return
  patchUser(u.id, r)
}
async function loadMailboxes() { mailboxes.value = (await api.get('/api/mailboxes/manage')).data }
async function loadUsers() { if (isAdmin.value) users.value = (await api.get('/api/users')).data }

onMounted(async () => {
  if (!auth.me) await auth.fetchMe().catch(() => {})
  if (auth.me) Object.assign(profileForm, { firstName: auth.me.firstName, lastName: auth.me.lastName, email: auth.me.email })
  await loadMailboxes()
  await loadUsers()
})
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar />
    <div class="flex-1 flex min-h-0">
      <!-- Unter-Navigation -->
      <aside class="w-56 shrink-0 bg-beige-soft border-r border-[#e6dad6] p-3">
        <div class="text-[10px] uppercase tracking-wider text-neutral-400 px-2 mb-2">Einstellungen</div>
        <button @click="tab = 'profile'" class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] mb-1 transition"
          :class="tab === 'profile' ? 'bg-white text-navy font-semibold shadow-sm' : 'text-neutral-500 hover:bg-beige'">
          <Icon name="users" class="w-4 h-4" /> Mein Profil
        </button>
        <button @click="tab = 'mailboxes'" class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] mb-1 transition"
          :class="tab === 'mailboxes' ? 'bg-white text-navy font-semibold shadow-sm' : 'text-neutral-500 hover:bg-beige'">
          <Icon name="envelope" class="w-4 h-4" /> Postfächer
        </button>
        <button v-if="isAdmin" @click="tab = 'users'" class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] transition"
          :class="tab === 'users' ? 'bg-white text-navy font-semibold shadow-sm' : 'text-neutral-500 hover:bg-beige'">
          <Icon name="users" class="w-4 h-4" /> Benutzer
        </button>
      </aside>

      <!-- Inhalt -->
      <div class="flex-1 overflow-y-auto">
        <div class="max-w-3xl mx-auto px-6 py-6">
          <!-- MEIN PROFIL -->
          <template v-if="tab === 'profile'">
            <h1 class="font-head text-[20px] text-ebony tracking-wide mb-4">Mein Profil</h1>
            <div class="bg-white border border-[#e6dad6] rounded-xl p-4 max-w-md grid gap-3">
              <label class="text-[12px] text-neutral-600">Vorname<input v-model="profileForm.firstName" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony mt-0.5" /></label>
              <label class="text-[12px] text-neutral-600">Nachname<input v-model="profileForm.lastName" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony mt-0.5" /></label>
              <label class="text-[12px] text-neutral-600">E-Mail<input v-model="profileForm.email" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony mt-0.5" /></label>
              <div class="flex items-center gap-3 mt-1">
                <button @click="saveProfile" class="text-[13px] px-3 py-1.5 rounded-lg bg-coral text-white font-medium hover:bg-coral-dark">Speichern</button>
                <span class="text-[12px]" :class="profileMsg === 'Gespeichert.' ? 'text-green-600' : 'text-neutral-500'">{{ profileMsg }}</span>
              </div>
            </div>

            <!-- Passwort ändern -->
            <div class="bg-white border border-[#e6dad6] rounded-xl p-4 max-w-md grid gap-3 mt-4">
              <div class="text-[13px] font-semibold text-navy">Passwort ändern</div>
              <label class="text-[12px] text-neutral-600">Neues Passwort
                <div class="relative mt-0.5">
                  <input :type="showPw ? 'text' : 'password'" v-model="pwForm.newPw" autocomplete="new-password" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 pr-9 text-[13px] text-ebony" />
                  <button type="button" @click="showPw = !showPw" :title="showPw ? 'verbergen' : 'anzeigen'" class="absolute right-2 top-1/2 -translate-y-1/2 text-neutral-400 hover:text-navy"><Icon :name="showPw ? 'eye-off' : 'eye'" class="w-4 h-4" /></button>
                </div>
              </label>
              <label class="text-[12px] text-neutral-600">Passwort wiederholen
                <input :type="showPw ? 'text' : 'password'" v-model="pwForm.repeat" autocomplete="new-password" @keyup.enter="savePassword" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony mt-0.5" />
              </label>
              <p v-if="pwForm.newPw && pwForm.repeat && pwForm.newPw !== pwForm.repeat" class="text-[11px] text-red-600 -mt-1">Passwörter stimmen nicht überein.</p>
              <div class="flex items-center gap-3 mt-1">
                <button @click="savePassword" :disabled="!pwForm.newPw || !pwForm.repeat" class="text-[13px] px-3 py-1.5 rounded-lg bg-coral text-white font-medium hover:bg-coral-dark disabled:opacity-50">Passwort ändern</button>
                <span class="text-[12px]" :class="pwMsg === 'Passwort geändert.' ? 'text-green-600' : 'text-red-600'">{{ pwMsg }}</span>
              </div>
            </div>
          </template>

          <!-- POSTFÄCHER -->
          <template v-else-if="tab === 'mailboxes'">
            <h1 class="font-head text-[20px] text-ebony tracking-wide mb-4">Postfächer</h1>

            <section>
              <div class="flex items-center justify-between">
                <h2 class="text-[13px] uppercase tracking-wider text-neutral-500">Meine Postfächer</h2>
                <button @click="openNew('personal')" class="flex items-center gap-1 text-[12px] px-2.5 py-1.5 rounded-lg bg-coral text-white hover:bg-coral-dark"><Icon name="plus" class="w-3.5 h-3.5" /> Postfach</button>
              </div>
              <p class="text-[12px] text-neutral-500 mt-0.5">Nur du siehst diese Postfächer. Aufgaben daraus sieht das ganze Team.</p>
              <div class="mt-3 space-y-2">
                <div v-for="m in personalBoxes" :key="m.id" class="bg-white border border-[#e6dad6] rounded-xl p-3 flex items-center gap-3">
                  <span class="w-9 h-9 rounded-lg grid place-items-center text-white shrink-0" style="background:#eb5d4f"><Icon name="envelope" class="w-4 h-4" /></span>
                  <div class="min-w-0">
                    <div class="text-[13px] font-semibold text-navy">{{ m.name }}</div>
                    <div class="text-[11px] text-neutral-500">{{ m.email }} · IMAP {{ m.imapHost }}:{{ m.imapPort }} · {{ m.hasPassword ? 'Passwort ✓' : 'kein Passwort' }} · {{ m.active ? 'aktiv' : 'inaktiv' }}</div>
                  </div>
                  <div class="ml-auto flex gap-1">
                    <button @click="openEdit(m)" title="Bearbeiten" class="w-8 h-8 grid place-items-center rounded-lg text-neutral-500 hover:bg-beige hover:text-navy"><Icon name="pencil" class="w-4 h-4" /></button>
                    <button @click="delMailbox(m)" title="Löschen" class="w-8 h-8 grid place-items-center rounded-lg text-neutral-400 hover:bg-red-50 hover:text-red-600"><Icon name="trash" class="w-4 h-4" /></button>
                  </div>
                </div>
                <div v-if="!personalBoxes.length" class="text-[12px] text-neutral-400">Noch keine eigenen Postfächer.</div>
              </div>
            </section>

            <section v-if="isAdmin" class="mt-7">
              <div class="flex items-center justify-between">
                <h2 class="text-[13px] uppercase tracking-wider text-neutral-500">Globale Postfächer (Team)</h2>
                <button @click="openNew('global')" class="flex items-center gap-1 text-[12px] px-2.5 py-1.5 rounded-lg bg-navy text-white hover:opacity-90"><Icon name="plus" class="w-3.5 h-3.5" /> Team-Postfach</button>
              </div>
              <p class="text-[12px] text-neutral-500 mt-0.5">Sehen &amp; nutzen alle. Nur Admins verwalten sie.</p>
              <div class="mt-3 space-y-2">
                <div v-for="m in globalBoxes" :key="m.id" class="bg-white border border-[#e6dad6] rounded-xl p-3 flex items-center gap-3">
                  <span class="w-9 h-9 rounded-lg grid place-items-center text-white shrink-0" style="background:#414c65"><Icon name="envelope" class="w-4 h-4" /></span>
                  <div class="min-w-0">
                    <div class="text-[13px] font-semibold text-navy">{{ m.name }}</div>
                    <div class="text-[11px] text-neutral-500">{{ m.email }} · IMAP {{ m.imapHost }}:{{ m.imapPort }} · {{ m.hasPassword ? 'Passwort ✓' : 'kein Passwort' }} · {{ m.active ? 'aktiv' : 'inaktiv' }}</div>
                  </div>
                  <div class="ml-auto flex gap-1">
                    <button @click="openEdit(m)" title="Bearbeiten" class="w-8 h-8 grid place-items-center rounded-lg text-neutral-500 hover:bg-beige hover:text-navy"><Icon name="pencil" class="w-4 h-4" /></button>
                    <button @click="delMailbox(m)" title="Löschen" class="w-8 h-8 grid place-items-center rounded-lg text-neutral-400 hover:bg-red-50 hover:text-red-600"><Icon name="trash" class="w-4 h-4" /></button>
                  </div>
                </div>
                <div v-if="!globalBoxes.length" class="text-[12px] text-neutral-400">Noch keine Team-Postfächer.</div>
              </div>
            </section>
          </template>

          <!-- BENUTZER -->
          <template v-else-if="tab === 'users'">
            <div class="flex items-center justify-between mb-4">
              <h1 class="font-head text-[20px] text-ebony tracking-wide">Benutzer</h1>
              <button @click="showUserForm = !showUserForm" class="flex items-center gap-1 text-[12px] px-2.5 py-1.5 rounded-lg bg-coral text-white hover:bg-coral-dark"><Icon name="plus" class="w-3.5 h-3.5" /> Benutzer</button>
            </div>
            <div v-if="showUserForm" class="mb-3 bg-white border border-[#e6dad6] rounded-xl p-3 grid grid-cols-2 gap-2">
              <input v-model="userForm.email" placeholder="E-Mail" class="border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px]" />
              <input v-model="userForm.password" placeholder="Passwort" class="border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px]" />
              <input v-model="userForm.firstName" placeholder="Vorname" class="border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px]" />
              <input v-model="userForm.lastName" placeholder="Nachname" class="border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px]" />
              <label class="text-[13px] flex items-center gap-2 text-neutral-600"><input type="checkbox" v-model="userForm.admin" /> Admin</label>
              <div class="text-right"><button @click="saveUser" class="text-[13px] px-3 py-1.5 rounded-lg bg-navy text-white">Anlegen</button></div>
            </div>
            <div class="space-y-2">
              <div v-for="u in users" :key="u.id" class="bg-white border border-[#e6dad6] rounded-xl p-3 flex items-center gap-3">
                <span class="w-9 h-9 rounded-full grid place-items-center text-[12px] font-bold text-white" style="background:#414c65">{{ u.name.slice(0, 2).toUpperCase() }}</span>
                <div class="min-w-0">
                  <div class="text-[13px] font-semibold text-navy">{{ u.name }}<span v-if="u.isAdmin" class="ml-2 text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f22;color:#b23b2e">Admin</span></div>
                  <div class="text-[11px] text-neutral-500">{{ u.email }}</div>
                </div>
                <div class="ml-auto flex gap-2 text-[12px]">
                  <button @click="editUser(u)" class="text-navy hover:underline">Bearbeiten</button>
                  <button @click="toggleAdmin(u)" class="text-navy hover:underline">{{ u.isAdmin ? 'Admin entziehen' : 'zum Admin' }}</button>
                  <button @click="resetPw(u)" class="text-navy hover:underline">Passwort</button>
                  <button v-if="u.id !== auth.me?.id" @click="delUser(u)" title="Löschen" class="w-7 h-7 grid place-items-center rounded-lg text-neutral-400 hover:bg-red-50 hover:text-red-600"><Icon name="trash" class="w-4 h-4" /></button>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- Postfach-Formular (Overlay) -->
    <div v-if="showForm" class="fixed inset-0 bg-black/30 flex items-center justify-center z-50" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-5">
        <h3 class="font-head text-[16px] text-ebony mb-3">{{ formId ? 'Postfach bearbeiten' : (form.scope === 'global' ? 'Team-Postfach' : 'Persönliches Postfach') }}</h3>
        <div class="grid grid-cols-2 gap-2">
          <label class="col-span-2 text-[11px] text-neutral-500">Name<input v-model="form.name" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
          <label class="col-span-2 text-[11px] text-neutral-500">E-Mail-Adresse<input v-model="form.email" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
          <label class="text-[11px] text-neutral-500">IMAP-Host<input v-model="form.imapHost" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
          <div class="grid grid-cols-2 gap-2">
            <label class="text-[11px] text-neutral-500">Port<input v-model.number="form.imapPort" type="number" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
            <label class="text-[11px] text-neutral-500">Verschl.<select v-model="form.imapEncryption" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony"><option>ssl</option><option>tls</option><option>none</option></select></label>
          </div>
          <label class="text-[11px] text-neutral-500">SMTP-Host<input v-model="form.smtpHost" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
          <div class="grid grid-cols-2 gap-2">
            <label class="text-[11px] text-neutral-500">Port<input v-model.number="form.smtpPort" type="number" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
            <label class="text-[11px] text-neutral-500">Verschl.<select v-model="form.smtpEncryption" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony"><option>tls</option><option>ssl</option><option>none</option></select></label>
          </div>
          <label class="text-[11px] text-neutral-500">Benutzername (leer = E-Mail)<input v-model="form.username" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
          <label class="text-[11px] text-neutral-500">Passwort{{ formId ? ' (leer = unverändert)' : '' }}<input v-model="form.password" type="password" class="w-full border border-[#e0d2cd] rounded-lg px-2 py-1.5 text-[13px] text-ebony" /></label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="showForm = false" class="text-[13px] px-3 py-1.5 rounded-lg text-neutral-600 hover:bg-beige">Abbrechen</button>
          <button @click="saveForm" class="text-[13px] px-3 py-1.5 rounded-lg bg-coral text-white font-medium">Speichern</button>
        </div>
      </div>
    </div>
  </div>
</template>
