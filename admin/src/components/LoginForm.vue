<script setup lang="ts">
import { ref } from 'vue'
import { api, ApiError, auth, setToken } from '../api'

const token = ref('')
const error = ref('')
const busy = ref(false)

async function submit(): Promise<void> {
  const value = token.value.trim()
  if (value === '') return
  busy.value = true
  error.value = ''
  setToken(value)
  try {
    // Probe-Request: erst wenn das Token passt, verschwindet die Anmeldung.
    await api.lists(new URLSearchParams({ limit: '1' }))
    auth.required = false
    token.value = ''
  } catch (e) {
    setToken(null)
    error.value = e instanceof ApiError && e.status === 401 ? 'Das Token ist ungültig.' : String((e as Error).message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="card login">
    <h2>Anmelden</h2>
    <p class="muted">
      Die Admin-Oberfläche braucht das <code>ADMIN_TOKEN</code> des Servers. Es bleibt nur in diesem Tab gespeichert
      und wird bei jeder Anfrage im <code>Authorization</code>-Header mitgeschickt.
    </p>
    <form @submit.prevent="submit">
      <label for="token">Admin-Token</label>
      <input
        id="token"
        v-model="token"
        type="password"
        autocomplete="off"
        spellcheck="false"
        required
        minlength="32"
        :aria-invalid="error !== ''"
        aria-describedby="token-error"
      >
      <p v-if="error" id="token-error" class="error" role="alert">{{ error }}</p>
      <button type="submit" :disabled="busy">{{ busy ? 'Prüfe …' : 'Anmelden' }}</button>
    </form>
  </section>
</template>
