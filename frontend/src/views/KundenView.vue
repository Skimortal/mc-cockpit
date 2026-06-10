<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../api'
import { useAuth } from '../stores/auth'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'

const auth = useAuth()
const route = useRoute()

interface Field { label: string; value: string }
interface Contact { id: number; department: string; name: string; email: string | null; phone: string | null }
interface Doc { id: number; name: string; type: string; date: string }
interface CompanyListItem { id: number; name: string; subtitle: string | null; kind: string; tags: string[]; contactCount: number; docCount: number }
interface CompanyDetail { id: number; name: string; subtitle: string | null; kind: string; tags: string[]; note: string | null; fields: Field[]; contacts: Contact[]; documents: Doc[] }

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
function addField() {
  const label = prompt('Feldname (z. B. „USt-ID DE", „GLN", „Zahlungsziel")')
  if (!label || !detail.value) return
  const value = prompt('Wert') || ''
  patch({ customFields: [...detail.value.fields, { label, value }] })
}
function editField(i: number) {
  if (!detail.value) return
  const f = detail.value.fields[i]
  const v = prompt(f.label, f.value)
  if (v === null) return
  const fields = detail.value.fields.slice()
  fields[i] = { label: f.label, value: v }
  patch({ customFields: fields })
}
function addTag() {
  const t = prompt('Tag')
  if (t && detail.value) patch({ tags: [...detail.value.tags, t] })
}
function editNote() {
  if (!detail.value) return
  const n = prompt('Notiz', detail.value.note || '')
  if (n !== null) patch({ note: n })
}
async function addContact() {
  const department = prompt('Abteilung (Direktor / Logistik / Finanzen / Einkauf / QM …)')
  if (!department || !selId.value) return
  const name = prompt('Name') || ''
  const email = prompt('E-Mail') || ''
  await api.post(`/api/companies/${selId.value}/contacts`, { department, name, email })
  await select(selId.value)
  await loadList()
}
async function addDoc() {
  const name = prompt('Dokumentname')
  if (!name || !selId.value) return
  await api.post(`/api/companies/${selId.value}/documents`, { name, type: 'PDF' })
  await select(selId.value)
  await loadList()
}
async function addCompany() {
  const name = prompt('Firmenname (Hersteller)')
  if (!name) return
  const { data } = await api.post('/api/companies', { name, kind: 'hersteller' })
  await loadList()
  await select(data.id)
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
            <span class="ml-auto text-[10px] px-1.5 py-0.5 rounded" style="background:#414c6522;color:#414c65">Kunde (Hersteller)</span>
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
                <div v-for="k in ks" :key="k.id" class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2">
                  <div class="text-[13px] text-ebony font-medium">{{ k.name }}</div>
                  <div class="text-[11px] text-neutral-500">{{ k.email }}</div>
                  <div v-if="k.phone" class="text-[11px] text-neutral-400">{{ k.phone }}</div>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="text-[12px] text-neutral-400 mt-1">Noch keine Ansprechpartner.</div>

          <!-- Dokumente -->
          <div class="mt-6 flex items-center justify-between">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Dokumente</h3>
            <button @click="addDoc" class="text-[11px] text-coral font-medium">+ Dokument</button>
          </div>
          <div class="mt-2 space-y-1.5">
            <div v-for="d in detail.documents" :key="d.id" class="flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2">
              <span class="w-7 h-7 rounded bg-coral/10 text-coral text-[10px] flex items-center justify-center font-semibold">{{ d.type }}</span>
              <span class="text-[13px] text-ebony">{{ d.name }}</span>
              <span class="ml-auto text-[11px] text-neutral-400">{{ d.date }}</span>
            </div>
            <div v-if="!detail.documents.length" class="text-[12px] text-neutral-400">Noch keine Dokumente — „+ Dokument".</div>
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
