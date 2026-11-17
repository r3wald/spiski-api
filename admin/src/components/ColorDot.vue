<script setup lang="ts">
import { computed } from 'vue'
import { colorHex } from '../format'

// SVG mit fill-Attribut statt style: braucht keine Ausnahme in der Content-Security-Policy.
const props = defineProps<{ color: number }>()
const fill = computed(() => colorHex(props.color))
</script>

<template>
  <svg class="dot" viewBox="0 0 10 10" aria-hidden="true">
    <circle cx="5" cy="5" r="4.5" :fill="fill" stroke="currentColor" stroke-opacity=".3" />
  </svg>
</template>
