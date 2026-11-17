<script setup lang="ts">
import { ref, watch } from 'vue'

/**
 * Bestaetigung vor dem Loeschen, als natives <dialog> (Fokus, Escape und
 * Hintergrund-Sperre liefert der Browser).
 */
const props = defineProps<{ open: boolean; title: string; confirmLabel: string; busy?: boolean }>()
const emit = defineEmits<{ confirm: []; cancel: [] }>()

const dialog = ref<HTMLDialogElement | null>(null)

watch(
  () => props.open,
  (open) => {
    if (open && !dialog.value?.open) dialog.value?.showModal()
    if (!open && dialog.value?.open) dialog.value.close()
  },
  { flush: 'post' },
)
</script>

<template>
  <dialog ref="dialog" class="confirm" aria-labelledby="confirm-title" @cancel.prevent="emit('cancel')">
    <h2 id="confirm-title">{{ title }}</h2>
    <slot />
    <div class="actions">
      <button type="button" class="danger" :disabled="busy" @click="emit('confirm')">
        {{ busy ? 'Lösche …' : confirmLabel }}
      </button>
      <button type="button" class="secondary" :disabled="busy" autofocus @click="emit('cancel')">Abbrechen</button>
    </div>
  </dialog>
</template>
