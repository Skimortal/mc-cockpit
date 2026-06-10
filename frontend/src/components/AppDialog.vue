<script setup lang="ts">
import { nextTick, watch } from 'vue'
import { dialog, dialogOk, dialogCancel } from '../composables/dialog'

watch(() => dialog.open, async (open) => {
  if (open) {
    await nextTick()
    document.querySelector<HTMLElement>('[data-dialog-focus]')?.focus()
  }
})
</script>

<template>
  <transition name="dlg">
    <div v-if="dialog.open" class="fixed inset-0 z-[100] bg-ebony/40 flex items-center justify-center p-4"
      @click.self="dialogCancel" @keydown.escape="dialogCancel">
      <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-5">
        <h3 class="font-head text-[16px] text-ebony tracking-wide mb-2">{{ dialog.title }}</h3>
        <p v-if="dialog.message" class="text-[13px] text-neutral-600 whitespace-pre-wrap mb-3">{{ dialog.message }}</p>

        <div v-if="dialog.kind === 'prompt'" class="grid gap-2 mb-4">
          <label v-for="(f, i) in dialog.fields" :key="f.key" class="text-[12px] text-neutral-600">
            {{ f.label }}
            <textarea v-if="f.type === 'textarea'" v-model="dialog.values[f.key]" rows="3"
              :data-dialog-focus="i === 0 ? '' : undefined"
              class="w-full border border-[#e0d2cd] rounded-lg px-2.5 py-2 text-[13px] text-ebony mt-0.5 focus:outline-none focus:ring-2 focus:ring-coral/40 focus:border-coral"></textarea>
            <input v-else :type="f.type || 'text'" v-model="dialog.values[f.key]" @keyup.enter="dialogOk"
              :data-dialog-focus="i === 0 ? '' : undefined"
              class="w-full border border-[#e0d2cd] rounded-lg px-2.5 py-2 text-[13px] text-ebony mt-0.5 focus:outline-none focus:ring-2 focus:ring-coral/40 focus:border-coral" />
          </label>
        </div>

        <div class="flex justify-end gap-2" :class="dialog.kind === 'prompt' ? '' : 'mt-4'">
          <button v-if="dialog.kind !== 'alert'" @click="dialogCancel" class="text-[13px] px-3 py-1.5 rounded-lg text-neutral-600 hover:bg-beige">{{ dialog.cancelText }}</button>
          <button @click="dialogOk" class="text-[13px] px-3 py-1.5 rounded-lg text-white font-medium"
            :class="dialog.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-coral hover:bg-coral-dark'">{{ dialog.okText }}</button>
        </div>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.dlg-enter-active, .dlg-leave-active { transition: opacity 0.15s ease; }
.dlg-enter-from, .dlg-leave-to { opacity: 0; }
</style>
