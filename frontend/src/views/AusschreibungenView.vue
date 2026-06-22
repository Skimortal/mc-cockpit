<script setup lang="ts">
import { computed, ref } from 'vue'
import AppTopbar from '../components/AppTopbar.vue'
import Icon from '../components/Icon.vue'

// ⚠️ MOCK — Beispieldaten, noch kein Backend. Dient nur zur Abstimmung von Layout/Idee.
interface Position { produkt: string; variante: string; ean: string; artnr: string; menge: string; preis: string }
interface Aufgabe { title: string; status: string }
interface Tender {
  id: number; title: string; haendler: string; laender: string[]; kanal: string; incoterm: string
  phase: string; frist: string; wert: string; hersteller: string; note: string
  positionen: Position[]; aufgaben: Aufgabe[]; dokumente: string[]
}

const PHASES = ['Angefragt', 'Angebot abgegeben', 'Verhandlung', 'Gewonnen', 'Verloren']
function phaseStyle(p: string) {
  return p === 'Gewonnen' ? 'background:#3f9d6b;color:#fff'
    : p === 'Verloren' ? 'background:#9aa7b8;color:#fff'
    : p === 'Verhandlung' ? 'background:#eb5d4f;color:#fff'
    : p === 'Angebot abgegeben' ? 'background:#e8a33d;color:#fff'
    : 'background:#414c65;color:#fff'
}

const tenders = ref<Tender[]>([
  {
    id: 1, title: 'ACG 02 – Trockenbackhefe 6×7 g', haendler: 'Aldi Süd', laender: ['DE', 'AT'], kanal: 'Eigenmarke', incoterm: 'DDP',
    phase: 'Verhandlung', frist: '2026-07-15', wert: '€ 420.000', hersteller: 'Mladegs Austria GmbH',
    note: 'PPWR-Konformität noch offen; Logistikbewertung läuft.',
    positionen: [{ produkt: 'Backfee Trockenbackhefe', variante: '42 g (6×7 g)', ean: '9012345000012', artnr: 'TBH-42', menge: '120.000 VE', preis: '€ 0,46' }],
    aufgaben: [{ title: 'PPWR-Qualitätsangaben nachreichen', status: 'in_progress' }, { title: 'Logistik-Sheet ausfüllen', status: 'open' }],
    dokumente: ['IFS Food V8 Zertifikat', 'Spezifikation Dry Yeast 42 g', 'HACCP Zertifikat'],
  },
  {
    id: 2, title: 'Tomato Ketchup 1 kg', haendler: 'Hofer', laender: ['AT'], kanal: 'No Brand', incoterm: 'FCA',
    phase: 'Angebot abgegeben', frist: '2026-06-30', wert: '€ 180.000', hersteller: 'Mladegs Austria GmbH',
    note: 'Muster versendet, Rückmeldung Einkauf ausstehend.',
    positionen: [{ produkt: 'Le Gusto Ketchup mild', variante: '1 kg PET', ean: '9012345000050', artnr: 'KE-1000', menge: '90.000 Stk', preis: '€ 1,12' }],
    aufgaben: [{ title: 'Datenblatt Flasche PET freigeben', status: 'done' }],
    dokumente: ['Bottle Spec PET', 'Analyse GA379960'],
  },
  {
    id: 3, title: 'Backzutaten-Sortiment 2026', haendler: 'Edeka', laender: ['DE'], kanal: 'Eigenmarke', incoterm: 'DDP',
    phase: 'Angefragt', frist: '2026-08-01', wert: '€ 950.000', hersteller: 'Mladegs Austria GmbH',
    note: 'Großes Sortiment (12 Artikel) – Vollständige Produktanforderungen NH v12 angefragt.',
    positionen: [
      { produkt: 'Backfee Vanillinzucker', variante: '120 g', ean: '9012345000101', artnr: 'VZ-120', menge: '300.000 Stk', preis: '€ 0,19' },
      { produkt: 'Backfee Backpulver', variante: '150 g', ean: '9012345000118', artnr: 'BP-150', menge: '250.000 Stk', preis: '€ 0,22' },
      { produkt: 'Backfee Puddingpulver Vanille', variante: '185 g', ean: '9012345000125', artnr: 'PP-185', menge: '180.000 Stk', preis: '€ 0,34' },
    ],
    aufgaben: [{ title: 'Sortimentsliste + Preise kalkulieren', status: 'open' }],
    dokumente: ['EDK Design Manual Tray'],
  },
  {
    id: 4, title: 'Wäsche-Kollektion AT/SI', haendler: 'Lidl', laender: ['AT', 'SI'], kanal: 'Aktion', incoterm: 'DAP',
    phase: 'Gewonnen', frist: '2026-05-20', wert: '€ 1.250.000', hersteller: 'Alma Ras',
    note: 'Zuschlag erhalten – Produktionsplanung startet.',
    positionen: [{ produkt: 'Damen-Wäsche Set', variante: '3er-Pack', ean: '3870000000027', artnr: 'AR-W3', menge: '60.000 Pack', preis: '€ 4,90' }],
    aufgaben: [{ title: 'Liefertermine bestätigen', status: 'in_progress' }],
    dokumente: [],
  },
])

const filter = ref<string>('alle')
const filtered = computed(() => filter.value === 'alle' ? tenders.value : tenders.value.filter((t) => t.phase === filter.value))
const selId = ref<number>(1)
const sel = computed(() => tenders.value.find((t) => t.id === selId.value) || null)
const countByPhase = (p: string) => tenders.value.filter((t) => t.phase === p).length
function fmtStatus(s: string) { return s === 'done' ? 'erledigt' : s === 'in_progress' ? 'in Arbeit' : 'offen' }
</script>

<template>
  <div class="h-screen flex flex-col">
    <AppTopbar />

    <!-- Mock-Hinweis -->
    <div class="bg-amber-100 text-amber-800 text-[12px] px-4 py-1.5 text-center border-b border-amber-200">
      ⚠️ Mock / Beispieldaten — noch kein echtes Backend. Nur zur Abstimmung von Layout &amp; Idee.
    </div>

    <div class="flex-1 flex min-h-0">
      <!-- Liste -->
      <div class="w-[340px] shrink-0 bg-beige-soft border-r border-[#e6dad6] flex flex-col">
        <div class="px-3 pt-3 pb-2">
          <div class="flex items-center justify-between">
            <div class="text-[10px] uppercase tracking-wider text-neutral-400">Ausschreibungen</div>
            <button class="text-[11px] text-coral font-medium">+ Neu</button>
          </div>
          <div class="flex flex-wrap gap-1 mt-2 text-[11px]">
            <button @click="filter = 'alle'" class="px-2 py-0.5 rounded-lg" :class="filter === 'alle' ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'">Alle {{ tenders.length }}</button>
            <button v-for="p in PHASES" :key="p" @click="filter = p" class="px-2 py-0.5 rounded-lg" :class="filter === p ? 'bg-navy text-white' : 'text-neutral-500 hover:bg-beige'">{{ p }} {{ countByPhase(p) }}</button>
          </div>
        </div>
        <div class="overflow-y-auto flex-1 px-2.5 pb-2.5">
          <div v-for="t in filtered" :key="t.id" @click="selId = t.id"
            class="mb-2.5 bg-white border rounded-xl p-3 shadow-sm hover:shadow-md cursor-pointer"
            :class="selId === t.id ? 'border-coral ring-2 ring-coral/30' : 'border-[#e6dad6]'">
            <div class="flex items-start gap-2">
              <div class="min-w-0 flex-1">
                <div class="text-[13px] font-semibold text-navy truncate">{{ t.title }}</div>
                <div class="text-[11px] text-neutral-500 mt-0.5 truncate">{{ t.haendler }} · {{ t.hersteller }}</div>
              </div>
              <span class="text-[9px] px-1.5 py-0.5 rounded shrink-0" :style="phaseStyle(t.phase)">{{ t.phase }}</span>
            </div>
            <div class="mt-2 flex items-center gap-1.5 flex-wrap">
              <span v-for="l in t.laender" :key="l" class="text-[10px] px-1.5 py-0.5 rounded bg-navy/10 text-navy">{{ l }}</span>
              <span class="text-[10px] px-1.5 py-0.5 rounded" style="background:#eb5d4f18;color:#b23b2e">{{ t.kanal }}</span>
              <span class="ml-auto text-[11px] font-semibold text-ebony">{{ t.wert }}</span>
            </div>
            <div class="mt-1 text-[10px] text-neutral-400">Frist: {{ t.frist }}</div>
          </div>
        </div>
      </div>

      <!-- Detail -->
      <div class="flex-1 min-w-0 overflow-y-auto">
        <div v-if="!sel" class="h-full flex items-center justify-center text-neutral-400 text-sm">Wähle links eine Ausschreibung.</div>
        <div v-else class="px-6 py-5 max-w-4xl">
          <div class="flex items-start gap-3">
            <span class="w-12 h-12 rounded-xl flex items-center justify-center text-white shrink-0" style="background:#414c65"><Icon name="briefcase" class="w-6 h-6" /></span>
            <div class="min-w-0">
              <div class="font-head text-[20px] text-ebony tracking-wide">{{ sel.title }}</div>
              <div class="text-[12px] text-neutral-500">{{ sel.haendler }} · Hersteller: {{ sel.hersteller }}</div>
            </div>
            <span class="ml-auto text-[11px] px-2 py-1 rounded-lg shrink-0" :style="phaseStyle(sel.phase)">{{ sel.phase }}</span>
          </div>

          <!-- Phase-Pipeline -->
          <div class="mt-4 flex items-center gap-1 flex-wrap">
            <template v-for="(p, i) in PHASES.slice(0,3)" :key="p">
              <button @click="sel.phase = p" class="text-[11px] px-2.5 py-1 rounded-lg" :class="sel.phase === p ? 'text-white' : 'bg-white text-neutral-600 border border-[#e6dad6]'" :style="sel.phase === p ? phaseStyle(p) : ''">{{ p }}</button>
              <span v-if="i < 2" class="text-neutral-300">→</span>
            </template>
            <span class="mx-1 text-neutral-300">|</span>
            <button @click="sel.phase = 'Gewonnen'" class="text-[11px] px-2.5 py-1 rounded-lg" :class="sel.phase === 'Gewonnen' ? 'text-white' : 'bg-white text-neutral-600 border border-[#e6dad6]'" :style="sel.phase === 'Gewonnen' ? phaseStyle('Gewonnen') : ''">✓ Gewonnen</button>
            <button @click="sel.phase = 'Verloren'" class="text-[11px] px-2.5 py-1 rounded-lg" :class="sel.phase === 'Verloren' ? 'text-white' : 'bg-white text-neutral-600 border border-[#e6dad6]'" :style="sel.phase === 'Verloren' ? phaseStyle('Verloren') : ''">Verloren</button>
          </div>

          <!-- Eckdaten -->
          <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <div class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-neutral-400">Frist</div><div class="text-[13px] text-ebony">{{ sel.frist }}</div></div>
            <div class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-neutral-400">Wert</div><div class="text-[13px] text-ebony font-semibold">{{ sel.wert }}</div></div>
            <div class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-neutral-400">Kanal</div><div class="text-[13px] text-ebony">{{ sel.kanal }}</div></div>
            <div class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2"><div class="text-[10px] uppercase tracking-wide text-neutral-400">Incoterm · Länder</div><div class="text-[13px] text-ebony">{{ sel.incoterm }} · {{ sel.laender.join(', ') }}</div></div>
          </div>

          <!-- Positionen -->
          <div class="mt-5 flex items-center justify-between">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400">Positionen (Produkte / Varianten)</h3>
            <button class="text-[11px] text-coral font-medium">+ Position</button>
          </div>
          <div class="mt-2 bg-white border border-[#e6dad6] rounded-xl overflow-hidden">
            <table class="w-full text-[12px]">
              <thead class="bg-beige-soft text-neutral-500 text-[10px] uppercase tracking-wide">
                <tr><th class="text-left px-3 py-1.5">Produkt</th><th class="text-left px-3 py-1.5">Variante</th><th class="text-left px-3 py-1.5">EAN</th><th class="text-left px-3 py-1.5">Art-Nr.</th><th class="text-right px-3 py-1.5">Menge</th><th class="text-right px-3 py-1.5">Preis</th></tr>
              </thead>
              <tbody class="divide-y divide-[#f0e7e3]">
                <tr v-for="(p, i) in sel.positionen" :key="i" class="hover:bg-beige-soft">
                  <td class="px-3 py-2 text-ebony font-medium">{{ p.produkt }}</td>
                  <td class="px-3 py-2 text-neutral-600">{{ p.variante }}</td>
                  <td class="px-3 py-2 text-neutral-500 font-mono text-[11px]">{{ p.ean }}</td>
                  <td class="px-3 py-2 text-neutral-500">{{ p.artnr }}</td>
                  <td class="px-3 py-2 text-right text-neutral-600">{{ p.menge }}</td>
                  <td class="px-3 py-2 text-right text-ebony font-semibold">{{ p.preis }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-5 grid sm:grid-cols-2 gap-4">
            <!-- Aufgaben -->
            <div>
              <h3 class="text-[12px] uppercase tracking-wider text-neutral-400 mb-2">Aufgaben <span class="text-coral">{{ sel.aufgaben.length }}</span></h3>
              <div class="space-y-1.5">
                <div v-for="(a, i) in sel.aufgaben" :key="i" class="flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2">
                  <span class="w-2 h-2 rounded-full shrink-0" :style="a.status === 'done' ? 'background:#3f9d6b' : 'background:#eb5d4f'"></span>
                  <span class="text-[13px] text-ebony truncate flex-1">{{ a.title }}</span>
                  <span class="text-[11px] text-neutral-400">{{ fmtStatus(a.status) }}</span>
                </div>
                <div v-if="!sel.aufgaben.length" class="text-[12px] text-neutral-400">Keine Aufgaben.</div>
              </div>
            </div>
            <!-- Dokumente -->
            <div>
              <h3 class="text-[12px] uppercase tracking-wider text-neutral-400 mb-2">Dokumente <span class="text-coral">{{ sel.dokumente.length }}</span></h3>
              <div class="space-y-1.5">
                <div v-for="(d, i) in sel.dokumente" :key="i" class="flex items-center gap-2 bg-white border border-[#e6dad6] rounded-lg px-3 py-2">
                  <Icon name="paperclip" class="w-3.5 h-3.5 text-neutral-300 shrink-0" />
                  <span class="text-[13px] text-ebony truncate">{{ d }}</span>
                </div>
                <div v-if="!sel.dokumente.length" class="text-[12px] text-neutral-400">Keine Dokumente.</div>
                <button class="text-[11px] text-coral font-medium">+ Dokument</button>
              </div>
            </div>
          </div>

          <!-- Notiz -->
          <div class="mt-5">
            <h3 class="text-[12px] uppercase tracking-wider text-neutral-400 mb-1">Notiz</h3>
            <div class="bg-white border border-[#e6dad6] rounded-lg px-3 py-2 text-[13px] text-neutral-600">{{ sel.note }}</div>
          </div>

          <!-- künftig: Ariba/Excel -->
          <div class="mt-5 rounded-xl border-2 border-dashed border-[#d8c7c1] px-4 py-3 text-[12px] text-neutral-400 flex items-center gap-2">
            <Icon name="download" class="w-4 h-4" /> Später hier: „Ariba/Excel-Formular befüllen" — KI füllt aus diesen Positionen + Hersteller-Stammdaten das Händler-Template.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
