<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import api from '../api'

const props = defineProps<{ kind: 'document' | 'attachment'; id: number }>()
const el = ref<HTMLElement | null>(null)
const url = ref('')
const done = ref(false) // fertig (mit oder ohne Bild)
let obs: IntersectionObserver | null = null
let blobUrl = ''

async function load() {
  try {
    const base = props.kind === 'attachment' ? 'attachments' : 'documents'
    const r = await api.get(`/api/${base}/${props.id}/thumb`, { responseType: 'blob' })
    const b = r.data as Blob
    if (b && b.size > 0) {
      blobUrl = URL.createObjectURL(b)
      url.value = blobUrl
    }
  } catch {
    /* kein Thumbnail -> Fallback (Slot) */
  } finally {
    done.value = true
  }
}
onMounted(() => {
  obs = new IntersectionObserver((entries) => {
    if (entries[0]?.isIntersecting) {
      obs?.disconnect()
      load()
    }
  }, { rootMargin: '200px' })
  if (el.value) obs.observe(el.value)
})
onUnmounted(() => {
  obs?.disconnect()
  if (blobUrl) URL.revokeObjectURL(blobUrl)
})
</script>

<template>
  <div ref="el" class="shrink-0 rounded border border-[#e6dad6] bg-beige-soft overflow-hidden grid place-items-center">
    <img v-if="url" :src="url" class="w-full h-full object-cover object-top" alt="" />
    <slot v-else />
  </div>
</template>
