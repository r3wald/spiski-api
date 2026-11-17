<script setup lang="ts">
import { auth, setToken } from './api'
import LoginForm from './components/LoginForm.vue'

function logout(): void {
  setToken(null)
  auth.required = true
}
</script>

<template>
  <header class="bar">
    <h1><RouterLink :to="{ name: 'lists' }">spiski Admin</RouterLink></h1>
    <button v-if="auth.token && !auth.required" type="button" class="link" @click="logout">Abmelden</button>
  </header>
  <main>
    <LoginForm v-if="auth.required" />
    <RouterView v-else />
  </main>
</template>
