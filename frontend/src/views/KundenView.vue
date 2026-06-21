<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useAuth } from '../stores/auth'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'
import { confirmDialog, promptDialog } from '../composables/dialog'

const auth = useAuth()
const route = useRoute()
const router = useRouter()

interface Field { label: string; value: string }
interface Contact { id: number; department: string; name: string; email: string | null; phone: string | null }
interface Doc { id: number; name: string; type: string; date: string; size: number; hasFile: boolean; preview: boolean }
interface CompanyListItem { id: number; name: string; subtitle: string | null; kind: string; tags: string[]; contactCount: number; docCount: number }
interface RelTask { id: number; title: string; status: string; conversationId: number | null; dueDate: string | null; overdue: boolean; assignee: string | null }
interface RelConv { id: number; subject: string; from: string | null; date: string | null }
interface CompanyDetail { id: number; name: string; subtitle: string | null; kind: string; tags: string[]; note: string | null; fields: Field[]; contacts: Contact[]; documents: Doc[]; tasks: RelTask[]; conversations: RelConv[] }

const companies = ref<CompanyListItem[]>([])
const query = ref('')
const selId = ref<number | null>(null)
const detail = ref<CompanyDetail | null>(null)

const filtered = computed(() => {
  const q = query.value.toLowerCase()
  return companies.value.filter((c) => !q || (c.name + (c.subtitle || '') + c.tags.join(' ')).toLowerCase().includes(q))
})
const groupedContacts = computed(() => {
  const g: Record<string, Contact[]> = {}
  for (const k of detail.value?.contacts || []) (g[k.department] = g[k.department] || []).push(k)
  return g
})

async function loadList() {
  companies.value = (await api.get('/api/companies')).data
  if (!selId.value && companies.value.length) select(companies.value[0].id)
}
async function select(id: number) {
  selId.value = id
  detail.value = (await api.get(`/api/companies/${id}`)).data
}
async function patch(body: Record<string, unknown>) {
  if (!selId.value) return
  detail.value = (await api.patch(`/api/companies/${selId.value}`, body)).data
  await loadList()
}
async function addField() {
  if (!detail.value) return
  const r = await promptDialog([{ key: 'label', label: 'Feldname (z. B. USt-ID DE, GLN, Zahlungsziel)' }, { key: 'value', label: 'Wert' }], { title: 'Feld hinzufügen' })
  if (!r || !r.label) return
  patch({ customFields: [...detail.value.fields, { label: r.label, value: r.value }] })
}
async function editField(i: number) {
  if (!detail.value) return
  const f = detail.value.fields[i]
  const r = await promptDialog([{ key: 'value', label: f.label, value: f.value }], { title: 'Feld bearbeiten' })
  if (!r) return
  const fields = detail.value.fields.slice()
  fields[i] = { label: f.label, value: r.value }
  patch({ customFields: fields })
}
async function addTag() {
  if (!detail.value) return
  const r = await promptDialog([{ key: 'tag', label: 'Tag' }], { title: 'Tag hinzufügen' })
  if (r && r.tag) patch({ tags: [...detail.value.tags, r.tag] })
}
async function editNote() {
  if (!detail.value) return
  const r = await promptDialog([{ key: 'note', label: 'Notiz', value: detail.value.note || '', type: 'textarea' }], { title: 'Notiz bearbeiten' })
  if (r) patch({ note: r.note })
}
async function addContact() {
  if (!selId.value) return
  const r = await promptDialog([
    { key: 'department', label: 'Abteilung (Direktor / Logistik / Finanzen / Einkauf / QM …)' },
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'E-Mail' },
    { key: 'phone', label: 'Telefon' },
  ], { title: 'Ansprechpartner hinzufügen' })
  if (!r) return
  await api.post(`/api/companies/${selId.value}/contacts`, r)
  await select(selId.value)
  await loadList()
}
const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
interface UpItem { name: string; pct: number; error?: boolean }
const uploads = ref<UpItem[]>([])
const uploading = computed(() => uploads.value.some((u) => u.pct < 100 && !u.error))

function triggerUpload() {
  if (fileInput.value) fileInput.value.value = ''
  fileInput.value?.click()
}
function onFilePicked(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files?.length) uploadFiles(Array.from(input.files))
}
function onDrop(e: DragEvent) {
  dragOver.value = false
  const files = e.dataTransfer?.files
  if (files?.length) uploadFiles(Array.from(files))
}
async function uploadFiles(files: File[]) {
  if (!selId.value) return
  const cid = selId.value
  const items: UpItem[] = files.map((f) => ({ name: f.name, pct: 0 }))
  uploads.value = items
  for (let i = 0; i < files.length; i++) {
    const fd = new FormData()
    fd.append('file', files[i])
    fd.append('name', files[i].name)
    try {
      await api.post(`/api/companies/${cid}/documents`, fd, {
        onUploadProgress: (ev) => { items[i].pct = ev.total ? Math.round((ev.loaded / ev.total) * 100) : 0 },
      })
      items[i].pct = 100
    } catch {
      items[i].error = true
    }
  }
  await select(cid)
  await loadList()
  // Erfolgreiche Liste nach kurzem Moment ausblenden; Fehler bleiben sichtbar.
  setTimeout(() => { if (!uploads.value.some((u) => u.error)) uploads.value = [] }, 1800)
}
function fmtSize(n: number): string {
  if (!n) return ''
  if (n >= 1024 * 1024) return (n / 1024 / 1024).toFixed(1) + ' MB'
  if (n >= 1024) return Math.round(n / 1024) + ' KB'
  return n + ' B'
}
async function openDoc(d: Doc) {
  if (!d.hasFile) return
  const win = d.preview ? window.open('', '_blank') : null
  try {
    const res = await api.get(`/api/documents/${d.id}/${d.preview ? 'preview' : 'download'}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
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
async function addCompany() {
  const r = await promptDialog([{ key: 'name', label: 'Firmenname (Hersteller)' }], { title: 'Kunde anlegen' })
  if (!r || !r.name) return
  const { data } = await api.post('/api/companies', { name: r.name, kind: 'hersteller' })
  await loadList()
  await select(data.id)
}
async function delCompany() {
  if (!detail.value) return
  if (!(await confirmDialog(`Kunde „${detail.value.name}" wirklich löschen?`, { title: 'Kunde löschen', danger: true, okText: 'Löschen' }))) return
  await api.delete(`/api/companies/${detail.value.id}`)
  selId.value = null
  detail.value = null
  await loadList()
}
async function patchContact(id: number, body: Record<string, unknown>) {
  detail.value = (await api.patch(`/api/contacts/${id}`, body)).data
  await loadList()
}
async function editContact(k: Contact) {
  const r = await promptDialog([
    { key: 'name', label: 'Name', value: k.name },
    { key: 'department', label: 'Abteilung', value: k.department },
    { key: 'email', label: 'E-Mail', value: k.email || '' },
    { key: 'phone', label: 'Telefon', value: k.phone || '' },
  ], { title: 'Kontakt bearbeiten' })
  if (!r) return
  patchContact(k.id, r)
}
async function delContact(k: Contact) {
  if (!(await confirmDialog(`Kontakt „${k.name}" löschen?`, { title: 'Kontakt löschen', danger: true, okText: 'Löschen' }))) return
  detail.value = (await api.delete(`/api/contacts/${k.id}`)).data
  await loadList()
}
async function delDoc(d: Doc) {
  if (!(await confirmDialog(`Dokument „${d.name}" löschen?`, { title: 'Dokument löschen', danger: true, okText: 'Löschen' }))) return
  detail.value = (await api.delete(`/api/documents/${d.id}`)).data
  await loadList()
}

watch(() => route.query.company, (v) => { if (v) select(Number(v)) })
onMounted(async () => {
  if (!auth.me) await auth.fetchMe().catch(() => {})
  await loadList()
  if (route.query.company) select(Number(route.query.company))
})
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar />

    <div class="flex-1 flex min-h-0">
      <!-- Liste -->
      <div class="w-[300px] shrink-0 bg-beige-soft border-r border-[#e6dad6] flex flex-col">
        <div class="px-3 pt-3 pb-2">
          <div class="text-[10px] uppercase tracking-wider text-neutral-400 mb-1.5">Kunden (Hersteller)</div>
          <div class="flex items-center gap-1.5 bg-white border border-[#e6dad6] rounded-lg px-2 py-1">
            <Icon name="search" class="w-3.5 h-3.5 text-neutral-400" />
            <input v-model="query" placeholder="Filtern…" class="bg-transparent text-[12px] outline-none w-full text-ebony" />
          </div>
        </div>
        <div class="overflow-y-auto flex-1 px-2.5 pb-2.5">
          <div v-for="c in filtered" :key="c.id" @click="select(c.id)"
            class="mb-2.5 bg-white border rounded-xl p-3 shadow-sm hover:shadow-md cursor-pointer"
            :class="selId === c.id ? 'border-coral ring-2 ring-coral/30' : 'border-[#e6dad6]'">
            <div class="flex items-center gap-2">
              <span class="w-8 h-8 rounded-lg flex items-center justify-center text-[11px] font-bold text-white" style="background:#414c65">{{ c.name.slice(0, 2).toUpperCase() }}</span>
              <div class="min-w-0">
                <div class="text-[13px] font-semibold text-navy truncate">{{ c.name }}</div>
                <div class="text-[10px] text-neutral-400 truncate">{{ c.subtitle }}</div>
              </div>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
              <span v-for="t in c.tags.slice(0, 3)" :key="t" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f18;color:#b23b2e">{{ t }}</span>
              <span class="ml-auto text-[10px] text-neutral-400">{{ c.contactCount }} Kontakte · {{ c.docCount }} Dok.</span>
            </div>
          </div>
          <div v-if="!filtered.length" class="text-xs text-neutral-400 p-3">Keine Treffer.</div>
          <button @click="addCompany" class="w-full mt-1 py-2 rounded-xl border-2 border-dashed border-[#e0d2cd] text-[12px] text-neutral-400 hover:border-coral hover:text-coral">+ Kunde anlegen</button>
        </div>
      </div>

      <!-- Detail -->
      <div class="flex-1 min-w-0 overflow-y-auto">
        <div v-if="!detail" class="h-full flex items-center justify-center text-neutral-400 text-sm">Wähle links einen Kunden.</div>
        <div v-else class="px-6 py-5 max-w-3xl">
          <div class="flex items-center gap-3">
            <span class="w-12 h-12 rounded-xl flex items-center justify-center text-[15px] font-bold text-white" style="background:#414c65">{{ detail.name.slice(0, 2).toUpperCase() }}</span>
            <div>
              <div class="font-head text-[20px] text-ebony tracking-wide">{{ detail.name }}</div>
              <div class="text-[12px] text-neutral-500">{{ detail.subtitle }}</div>
            </div>
            <div class="ml-auto flex items-center gap-2">
              <span class="text-[10px] px-1.5 py-0.5 rounded" style="background:#414c6522;color:#414c65">Kunde (Hersteller)</span>
              <button @click="delCompany" title="Kunde löschen" class="w-8 h-8 grid place-items-center rounded-lg text-neutral-400 hover:bg-red-50 hover:text-red-600"><Icon name="trash" class="w-4 h-4" /></button>
            </div>
          </div>
          <div class="mt-2 flex flex-wrap gap-1">
            <span v-for="t in detail.tags" :key="t" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f18;color:#b23b2e">{{ t }}</span>
            <button @click="addTag" class="text-[10px] px-1.5 py-0.5 rounded" style="background:#f0e7e3;color:#b7a9a3">+ Tag</button>
          </div>

          <!-- Stammdaten -->
          <div class="mt-5 flex items-center justify-between">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Stammdaten</h3>
            <button @click="addField" class="text-[11px] text-coral font-medium">+ Feld</button>
          </div>
          <div class="mt-2 grid grid-cols-2 gap-2">
            <div v-for="(f, i) in detail.fields" :key="i" @click="editField(i)" class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2 cursor-pointer hover:border-coral">
              <div class="text-[10px] uppercase tracking-wide text-neutral-400">{{ f.label }}</div>
              <div class="text-[13px] text-ebony">{{ f.value || '—' }}</div>
            </div>
            <div v-if="!detail.fields.length" class="text-[12px] text-neutral-400 col-span-2">Noch keine Felder — „+ Feld".</div>
          </div>

          <!-- Ansprechpartner -->
          <div class="mt-6 flex items-center justify-between">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Ansprechpartner</h3>
            <button @click="addContact" class="text-[11px] text-coral font-medium">+ Ansprechpartner</button>
          </div>
          <div v-if="detail.contacts.length">
            <div v-for="(ks, dept) in groupedContacts" :key="dept" class="mt-2">
              <div class="text-[11px] font-semibold text-navy mb-1">{{ dept }}</div>
              <div class="grid grid-cols-2 gap-2">
                <div v-for="k in ks" :key="k.id" class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2 flex items-start gap-2">
                  <div class="min-w-0">
                    <div class="text-[13px] text-ebony font-medium">{{ k.name }}</div>
                    <div class="text-[11px] text-neutral-500">{{ k.email }}</div>
                    <div v-if="k.phone" class="text-[11px] text-neutral-400">{{ k.phone }}</div>
                  </div>
                  <div class="ml-auto flex gap-1">
                    <button @click="editContact(k)" title="Bearbeiten" class="w-7 h-7 grid place-items-center rounded text-neutral-400 hover:bg-beige hover:text-navy"><Icon name="pencil" class="w-3.5 h-3.5" /></button>
                    <button @click="delContact(k)" title="Löschen" class="w-7 h-7 grid place-items-center rounded text-neutral-400 hover:bg-red-50 hover:text-red-600"><Icon name="trash" class="w-3.5 h-3.5" /></button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="text-[12px] text-neutral-400 mt-1">Noch keine Ansprechpartner.</div>

          <!-- Dokumente -->
          <div class="mt-6 flex items-center justify-between">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Dokumente</h3>
            <button @click="triggerUpload" :disabled="uploading" class="text-[11px] text-coral font-medium disabled:opacity-50">{{ uploading ? 'Lädt hoch…' : '+ Dateien hochladen' }}</button>
            <input ref="fileInput" type="file" multiple class="hidden" @change="onFilePicked" />
          </div>
          <div class="mt-2 space-y-1.5"
            @dragover.prevent="dragOver = true" @dragenter.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false" @drop.prevent="onDrop">
            <div v-for="d in detail.documents" :key="d.id" class="flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2 hover:border-coral group">
              <span class="w-7 h-7 rounded bg-coral/10 text-coral text-[10px] flex items-center justify-center font-semibold uppercase">{{ d.type }}</span>
              <button @click="openDoc(d)" :disabled="!d.hasFile" class="text-[13px] text-ebony text-left hover:text-coral truncate disabled:cursor-default" :title="d.preview ? 'Vorschau öffnen' : 'Herunterladen'">{{ d.name }}</button>
              <span v-if="d.size" class="text-[11px] text-neutral-300">{{ fmtSize(d.size) }}</span>
              <span class="ml-auto text-[11px] text-neutral-400">{{ d.date }}</span>
              <button v-if="d.hasFile" @click="openDoc(d)" :title="d.preview ? 'Vorschau' : 'Herunterladen'" class="w-7 h-7 grid place-items-center rounded text-neutral-400 hover:bg-beige hover:text-navy"><Icon :name="d.preview ? 'eye' : 'download'" class="w-3.5 h-3.5" /></button>
              <button @click="delDoc(d)" title="Löschen" class="w-7 h-7 grid place-items-center rounded text-neutral-400 hover:bg-red-50 hover:text-red-600"><Icon name="trash" class="w-3.5 h-3.5" /></button>
            </div>

            <!-- Upload-Fortschritt -->
            <div v-for="(u, i) in uploads" :key="'up' + i" class="flex items-center gap-2 bg-white border rounded-lg px-3 py-2"
              :class="u.error ? 'border-red-300' : 'border-[#e6dad6]'">
              <Icon name="paperclip" class="w-3.5 h-3.5 text-neutral-300 shrink-0" />
              <span class="text-[13px] text-ebony truncate flex-1">{{ u.name }}</span>
              <span v-if="u.error" class="text-[11px] text-red-600 shrink-0">Fehler</span>
              <template v-else>
                <div class="w-24 h-1.5 rounded-full bg-beige overflow-hidden shrink-0">
                  <div class="h-full bg-coral transition-all" :style="{ width: u.pct + '%' }"></div>
                </div>
                <span class="text-[11px] text-neutral-400 w-9 text-right shrink-0">{{ u.pct }}%</span>
              </template>
            </div>

            <!-- Drop-Zone / Leerzustand -->
            <button type="button" @click="triggerUpload"
              class="w-full rounded-xl border-2 border-dashed px-4 py-6 flex flex-col items-center gap-1.5 transition-colors cursor-pointer"
              :class="dragOver ? 'border-coral bg-coral/10 text-coral scale-[1.01]' : 'border-[#d8c7c1] text-neutral-500 hover:border-coral hover:text-coral hover:bg-coral/5'">
              <Icon name="upload" class="w-6 h-6" :class="dragOver ? 'text-coral' : 'text-neutral-400'" />
              <span class="text-[13px] font-medium">{{ dragOver ? 'Jetzt loslassen zum Hochladen' : 'Dateien hierher ziehen' }}</span>
              <span v-if="!dragOver" class="text-[11px] text-neutral-400">oder klicken zum Auswählen · mehrere möglich</span>
            </button>
          </div>

          <!-- Verknüpfte Aufgaben -->
          <div class="mt-6">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Aufgaben <span class="text-coral">{{ detail.tasks.length }}</span></h3>
            <div class="mt-2 space-y-1.5">
              <button v-for="t in detail.tasks" :key="t.id" @click="t.conversationId && router.push({ path: '/aufgaben', query: { conv: t.conversationId } })"
                class="w-full text-left flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2 hover:border-coral">
                <span class="w-2 h-2 rounded-full shrink-0" :style="t.status === 'done' ? 'background:#3f9d6b' : 'background:#eb5d4f'"></span>
                <span class="text-[13px] text-ebony truncate flex-1">{{ t.title }}</span>
                <span v-if="t.assignee" class="text-[11px] text-neutral-400 shrink-0">{{ t.assignee }}</span>
                <span v-if="t.dueDate" class="text-[11px] shrink-0" :class="t.overdue ? 'text-coral font-semibold' : 'text-neutral-400'">⏱ {{ t.dueDate.slice(5) }}</span>
              </button>
              <div v-if="!detail.tasks.length" class="text-[12px] text-neutral-400">Keine verknüpften Aufgaben.</div>
            </div>
          </div>

          <!-- Verknüpfte Mails -->
          <div class="mt-6">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Mails <span class="text-coral">{{ detail.conversations.length }}</span></h3>
            <div class="mt-2 space-y-1.5">
              <button v-for="m in detail.conversations" :key="m.id" @click="router.push({ path: '/aufgaben', query: { conv: m.id } })"
                class="w-full text-left flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2 hover:border-coral">
                <Icon name="envelope" class="w-4 h-4 text-navy shrink-0" />
                <span class="text-[13px] text-ebony truncate flex-1">{{ m.subject || '(ohne Betreff)' }}</span>
                <span class="text-[11px] text-neutral-400 shrink-0">{{ m.date }}</span>
              </button>
              <div v-if="!detail.conversations.length" class="text-[12px] text-neutral-400">Keine verknüpften Mails.</div>
            </div>
          </div>

          <!-- Notiz -->
          <div class="mt-6 flex items-center justify-between">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Notiz</h3>
            <button @click="editNote" class="text-[11px] text-coral font-medium">bearbeiten</button>
          </div>
          <div class="mt-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2 text-[13px] text-neutral-600 min-h-[44px] whitespace-pre-wrap">{{ detail.note || '—' }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
