<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import { api, ApiError, type AdminItem, type AdminListDetail } from '../api'
import AccessInfo from '../components/AccessInfo.vue'
import ColorDot from '../components/ColorDot.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { colorHex, ENCRYPTION_LABELS, formatDateTime, itemText, plural, relativeAge } from '../format'

const props = defineProps<{ id: string }>()

const list = ref<AdminListDetail | null>(null)
const loading = ref(false)
const error = ref('')
const notFound = ref(false)
const notice = ref('')
const showArchived = ref(false)

/** Zurueck zur Uebersicht mit demselben Filter, falls von dort gekommen. */
const backTo = computed<RouteLocationRaw>(() => {
  const back: unknown = window.history.state?.back
  return typeof back === 'string' && (back === '/' || back.startsWith('/?')) ? back : { name: 'lists' }
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  notFound.value = false
  try {
    list.value = await api.list(props.id)
  } catch (e) {
    list.value = null
    if (e instanceof ApiError && e.status === 404) notFound.value = true
    else error.value = (e as Error).message
  } finally {
    loading.value = false
  }
}

watch(
  () => props.id,
  () => {
    notice.value = ''
    void load()
  },
  { immediate: true },
)

const items = computed<AdminItem[]>(() =>
  (list.value?.items ?? []).filter((item) => showArchived.value || item.deletedAt === null),
)
const archivedCount = computed(() => (list.value ? list.value.itemCount - list.value.activeItemCount : 0))

// --- Loeschen --------------------------------------------------------------

type Pending = { kind: 'list' } | { kind: 'item'; item: AdminItem }
const pending = ref<Pending | null>(null)
const deleting = ref(false)

async function confirmDelete(): Promise<void> {
  const target = pending.value
  if (!target || !list.value) return
  deleting.value = true
  try {
    if (target.kind === 'list') {
      const { result } = await api.deleteList(list.value.id)
      notice.value =
        result === 'deleted'
          ? 'Die Liste wurde gelöscht. Sie verschwindet beim nächsten Sync von allen Geräten; der Besitzer findet sie im Archiv der App.'
          : 'Die Liste war bereits gelöscht; es wurde nichts geändert.'
    } else {
      const { result } = await api.deleteItem(target.item.id)
      notice.value =
        result === 'deleted'
          ? 'Der Eintrag wurde gelöscht. Er verschwindet beim nächsten Sync von allen Geräten.'
          : 'Der Eintrag war bereits archiviert; es wurde nichts geändert.'
    }
    pending.value = null
    await load()
  } catch (e) {
    pending.value = null
    error.value = (e as Error).message
  } finally {
    deleting.value = false
  }
}

const usersText = computed(() => {
  if (!list.value) return ''
  return list.value.userCount === 1 ? 'des Nutzers' : `aller ${list.value.userCount} Nutzer`
})
</script>

<template>
  <p><RouterLink :to="backTo" class="back">← Alle Listen</RouterLink></p>

  <p v-if="loading && !list" class="muted">Lade …</p>
  <p v-if="notFound" class="muted">Diese Liste gibt es nicht (mehr).</p>
  <p v-if="error" class="error" role="alert">{{ error }}</p>
  <p v-if="notice" class="notice" role="status">{{ notice }}</p>

  <template v-if="list">
    <section class="card">
      <div class="head">
        <h2 class="name">
          <ColorDot :color="list.color" />
          <span v-if="list.encrypted" class="label encrypted">🔒 verschlüsselt</span>
          <span v-else class="label" :class="{ struck: list.deletedAt }">{{ list.name }}</span>
          <span v-if="list.deletedAt" class="tag danger">gelöscht</span>
        </h2>
        <button v-if="!list.deletedAt" type="button" class="danger" @click="pending = { kind: 'list' }">Liste löschen</button>
      </div>

      <dl class="facts">
        <dt>Account</dt>
        <dd>
          <code>{{ list.owner }}</code>
          <RouterLink :to="{ name: 'lists', query: { owner: list.owner } }">
            {{ plural(list.ownerListCount, 'Liste', 'Listen') }} dieses Accounts
          </RouterLink>
        </dd>
        <dt>Verschlüsselung</dt>
        <dd>
          Konto {{ ENCRYPTION_LABELS[list.encryptionState] }}
          <span class="muted-inline">· Liste {{ list.encrypted ? 'verschlüsselt' : 'im Klartext' }}</span>
        </dd>
        <dt>Zugriff</dt>
        <dd><AccessInfo :list="list" /></dd>
        <dt>Einträge</dt>
        <dd>
          {{ list.activeItemCount }} aktiv
          <span v-if="archivedCount > 0" class="muted-inline">(+ {{ archivedCount }} archiviert)</span>
        </dd>
        <dt>Erstellt</dt>
        <dd>
          <time :datetime="list.createdAt">{{ formatDateTime(list.createdAt) }}</time>
          <span class="muted-inline">{{ relativeAge(list.createdAt) }}</span>
        </dd>
        <dt>Geändert</dt>
        <dd>
          <time :datetime="list.updatedAt">{{ formatDateTime(list.updatedAt) }}</time>
          <span class="muted-inline">{{ relativeAge(list.updatedAt) }}</span>
        </dd>
        <template v-if="list.deletedAt">
          <dt>Gelöscht</dt>
          <dd>
            <time :datetime="list.deletedAt">{{ formatDateTime(list.deletedAt) }}</time>
            <span class="muted-inline">{{ relativeAge(list.deletedAt) }}</span>
          </dd>
        </template>
        <dt>Farbe</dt>
        <dd><code>{{ colorHex(list.color) }}</code></dd>
        <dt>Position</dt>
        <dd>{{ list.sortOrder }}</dd>
        <dt>ID</dt>
        <dd><code>{{ list.id }}</code></dd>
      </dl>
    </section>

    <section class="card">
      <div class="head">
        <h2>Einträge</h2>
        <label v-if="archivedCount > 0" class="check">
          <input v-model="showArchived" type="checkbox"> Archivierte anzeigen ({{ archivedCount }})
        </label>
      </div>

      <p v-if="items.length === 0" class="muted">
        Keine {{ showArchived ? '' : 'aktiven ' }}Einträge<template v-if="!showArchived && archivedCount > 0"> · {{ archivedCount }} archiviert, ausgeblendet</template>.
      </p>
      <ul v-else class="items">
        <li v-for="item in items" :key="item.id" class="item" :class="{ archived: item.deletedAt, done: item.isChecked }">
          <span class="box" aria-hidden="true">{{ item.isChecked ? '☑' : '☐' }}</span>
          <span class="visually-hidden">{{ item.isChecked ? 'erledigt: ' : 'offen: ' }}</span>
          <span v-if="item.encrypted" class="label encrypted">🔒 verschlüsselt</span>
          <span v-else class="label">{{ itemText(item) }}</span>
          <span v-if="item.isChecked" class="tag">erledigt</span>
          <span v-if="item.movedAt" class="tag">verschoben</span>
          <span v-if="item.deletedAt" class="tag danger">archiviert {{ formatDateTime(item.deletedAt) }}</span>
          <button v-if="!item.deletedAt" type="button" class="link danger-text delete" @click="pending = { kind: 'item', item }">
            Löschen<span class="visually-hidden">: {{ item.encrypted ? 'verschlüsselter Eintrag' : itemText(item) }}</span>
          </button>
          <span v-if="item.note" class="note-text">Notiz: {{ item.note }}</span>
        </li>
      </ul>
    </section>

    <ConfirmDialog
      :open="pending?.kind === 'list'"
      title="Diese Liste wirklich löschen?"
      confirm-label="Ja, Liste löschen"
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="pending = null"
    >
      <p>
        Die Liste verschwindet samt {{ list.activeItemCount === 1 ? 'ihrem Eintrag' : `ihren ${list.activeItemCount} Einträgen` }}
        auf allen Geräten {{ usersText }}, sobald sie synchronisieren – so, als hätte der Besitzer sie in der App gelöscht.
        Der Besitzer findet sie danach im Archiv der App und kann sie dort wiederherstellen.
        <template v-if="list.memberCount > 0">Das Teilen bleibt bestehen; nach einer Wiederherstellung sehen die Mitglieder sie wieder.</template>
      </p>
    </ConfirmDialog>

    <ConfirmDialog
      :open="pending?.kind === 'item'"
      title="Diesen Eintrag wirklich löschen?"
      confirm-label="Ja, Eintrag löschen"
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="pending = null"
    >
      <p v-if="pending?.kind === 'item'">
        <strong v-if="pending.item.encrypted">🔒 verschlüsselter Eintrag</strong>
        <strong v-else>{{ itemText(pending.item) }}</strong>
      </p>
      <p>
        Der Eintrag wird auf allen Geräten {{ usersText }} entfernt, sobald sie synchronisieren – so, als hätte ihn jemand
        in der App gelöscht. Auf dem Server bleibt er als archivierter Eintrag erhalten.
        <strong v-if="list.memberCount > 0">Die Liste ist geteilt: {{ list.userCount }} Nutzer sind betroffen.</strong>
      </p>
    </ConfirmDialog>
  </template>
</template>
