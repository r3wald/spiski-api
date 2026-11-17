<script setup lang="ts">
import type { AdminList } from '../api'

// Geteilt = mindestens ein Mitglied. Zugriff haben Besitzer + Mitglieder. Der
// Teilen-Code selbst kommt nie an, nur ob er aktiv ist.
defineProps<{ list: Pick<AdminList, 'memberCount' | 'userCount' | 'shareCodeActive'> }>()
</script>

<template>
  <span v-if="list.memberCount > 0">
    <span class="tag shared">geteilt</span> <strong>{{ list.userCount }} Nutzer</strong>
    <span class="sub">
      Besitzer + {{ list.memberCount }} {{ list.memberCount === 1 ? 'Mitglied' : 'Mitglieder'
      }}<template v-if="list.shareCodeActive"> · Code aktiv</template>
    </span>
  </span>
  <span v-else-if="list.shareCodeActive" class="muted-inline">Code aktiv, noch niemand beigetreten</span>
  <span v-else class="muted-inline">nicht geteilt</span>
</template>
