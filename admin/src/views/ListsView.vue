<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, type ListPage, type Owner } from '../api'
import AccessInfo from '../components/AccessInfo.vue'
import ColorDot from '../components/ColorDot.vue'
import {
  AGES,
  DEFAULT_FILTER,
  defaultDirection,
  filterFromQuery,
  filterToApiParams,
  filterToQuery,
  isFiltered,
  PAGE_SIZE,
  type Age,
  type ListFilter,
  type Sort,
} from '../filters'
import { ENCRYPTION_LABELS, formatDateTime, plural, relativeAge } from '../format'

const route = useRoute()
const router = useRouter()

const filter = computed<ListFilter>(() => filterFromQuery(route.query))
const page = ref<ListPage | null>(null)
const loading = ref(false)
const error = ref('')

// Suchfeld lokal, damit nicht jeder Tastendruck eine Anfrage ausloest.
const search = ref(filter.value.q)
let searchTimer: ReturnType<typeof setTimeout> | undefined

// Alle bekannten Eigentuemer fuer das Drop-down; einmal je Aufruf der Seite.
const owners = ref<Owner[]>([])
onMounted(async () => {
  try {
    owners.value = (await api.owners()).owners
  } catch {
    // Fehler zeigt schon die Listenabfrage; ohne Liste bleibt nur "alle".
  }
})

const ownerOptions = computed(() => {
  const options = owners.value.map((owner) => ({
    owner: owner.owner,
    label: `${owner.owner} · ${plural(owner.listCount, 'Liste', 'Listen')}${
      owner.activeListCount < owner.listCount ? ` (${owner.activeListCount} aktiv)` : ''
    } · ${ENCRYPTION_LABELS[owner.encryptionState]}`,
  }))
  // Ein Kuerzel aus der URL, das (noch) nicht in der Liste steht – etwa ein Anfang –,
  // bleibt waehlbar, sonst zeigte das Drop-down "alle" bei aktivem Filter.
  const current = filter.value.owner
  if (current !== '' && !options.some((option) => option.owner === current)) {
    options.unshift({ owner: current, label: `${current} …` })
  }
  return options
})

let requestId = 0
async function load(current: ListFilter): Promise<void> {
  const id = ++requestId
  loading.value = true
  error.value = ''
  try {
    const result = await api.lists(filterToApiParams(current))
    if (id === requestId) page.value = result
  } catch (e) {
    if (id === requestId) error.value = (e as Error).message
  } finally {
    if (id === requestId) loading.value = false
  }
}

watch(
  filter,
  (current) => {
    search.value = current.q
    void load(current)
  },
  { immediate: true },
)

function update(patch: Partial<ListFilter>, replace = false): void {
  const next = { ...filter.value, page: 1, ...patch }
  const location = { name: 'lists', query: filterToQuery(next) }
  void (replace ? router.replace(location) : router.push(location))
}

watch(search, (value) => {
  clearTimeout(searchTimer)
  if (value === filter.value.q) return
  searchTimer = setTimeout(() => update({ q: value }, true), 300)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

function sortBy(sort: Sort): void {
  const dir = filter.value.sort === sort ? (filter.value.dir === 'asc' ? 'desc' : 'asc') : defaultDirection(sort)
  update({ sort, dir })
}

function ariaSort(sort: Sort): 'ascending' | 'descending' | 'none' {
  if (filter.value.sort !== sort) return 'none'
  return filter.value.dir === 'asc' ? 'ascending' : 'descending'
}

function sortIndicator(sort: Sort): string {
  if (filter.value.sort !== sort) return ''
  return filter.value.dir === 'asc' ? '▲' : '▼'
}

const columns: { sort: Sort; label: string; numeric?: boolean }[] = [
  { sort: 'name', label: 'Liste' },
  { sort: 'owner', label: 'Account' },
  { sort: 'users', label: 'Zugriff' },
  { sort: 'items', label: 'Einträge', numeric: true },
  { sort: 'createdAt', label: 'Erstellt' },
  { sort: 'updatedAt', label: 'Geändert' },
]

const pageCount = computed(() => (page.value ? Math.max(1, Math.ceil(page.value.total / PAGE_SIZE)) : 1))

const summary = computed(() => {
  if (!page.value) return ''
  const { total, ownerCount, offset, lists } = page.value
  let text = `${plural(total, 'Liste', 'Listen')} von ${plural(ownerCount, 'Account', 'Accounts')}`
  if (lists.length > 0) text += ` · angezeigt ${offset + 1}–${offset + lists.length}`
  return text
})

function goToPage(target: number): void {
  void router.push({ name: 'lists', query: filterToQuery({ ...filter.value, page: target }) })
}

function reset(): void {
  void router.push({ name: 'lists', query: filterToQuery({ ...DEFAULT_FILTER, sort: filter.value.sort, dir: filter.value.dir }) })
}

const ageOptions = Object.entries(AGES) as [Age, { label: string }][]
</script>

<template>
  <form class="filters" role="search" @submit.prevent>
    <label class="field grow">
      <span>Suche</span>
      <input v-model="search" type="search" maxlength="100" placeholder="Listenname oder Account-Kürzel" autocomplete="off">
    </label>
    <label class="field">
      <span>Status</span>
      <select :value="filter.status" @change="update({ status: ($event.target as HTMLSelectElement).value as ListFilter['status'] })">
        <option value="all">alle</option>
        <option value="active">aktiv</option>
        <option value="deleted">gelöscht</option>
      </select>
    </label>
    <label class="field">
      <span>Eigentümer (Account-Kürzel)</span>
      <select class="mono" :value="filter.owner" @change="update({ owner: ($event.target as HTMLSelectElement).value })">
        <option value="">alle ({{ owners.length }})</option>
        <option v-for="owner in ownerOptions" :key="owner.owner" :value="owner.owner">
          {{ owner.label }}
        </option>
      </select>
    </label>
    <label class="field">
      <span>Verschlüsselung (Konto)</span>
      <select :value="filter.encryption" @change="update({ encryption: ($event.target as HTMLSelectElement).value as ListFilter['encryption'] })">
        <option value="">alle</option>
        <option value="plaintext">unverschlüsselt</option>
        <option value="migrating">wird umgestellt</option>
        <option value="encrypted">verschlüsselt</option>
      </select>
    </label>
    <label class="field">
      <span>Alter</span>
      <select :value="filter.age" @change="update({ age: ($event.target as HTMLSelectElement).value as Age })">
        <option v-for="[key, option] in ageOptions" :key="key" :value="key">{{ option.label }}</option>
      </select>
    </label>
    <label class="field">
      <span>gemessen an</span>
      <select
        :value="filter.ageField"
        :disabled="filter.age === ''"
        @change="update({ ageField: ($event.target as HTMLSelectElement).value as ListFilter['ageField'] })"
      >
        <option value="created">Erstellung</option>
        <option value="updated">letzter Änderung</option>
      </select>
    </label>
    <button v-if="isFiltered(filter)" type="button" class="secondary reset" @click="reset">Filter zurücksetzen</button>
  </form>

  <p class="muted" aria-live="polite">
    <template v-if="loading && !page">Lade …</template>
    <template v-else>{{ summary }}</template>
  </p>
  <p v-if="error" class="error" role="alert">{{ error }}</p>

  <div v-if="page && page.lists.length > 0" class="table-wrap" :class="{ loading }">
    <table>
      <caption class="visually-hidden">Listen aller Accounts</caption>
      <thead>
        <tr>
          <th v-for="column in columns" :key="column.sort" scope="col" :aria-sort="ariaSort(column.sort)" :class="{ num: column.numeric }">
            <button type="button" class="sort" @click="sortBy(column.sort)">
              {{ column.label }}<span class="indicator" aria-hidden="true">{{ sortIndicator(column.sort) }}</span>
            </button>
          </th>
          <th scope="col">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="list in page.lists" :key="list.id" :class="{ deleted: list.deletedAt !== null }">
          <td>
            <RouterLink :to="{ name: 'list', params: { id: list.id } }" class="name">
              <ColorDot :color="list.color" />
              <span v-if="list.encrypted" class="label encrypted">🔒 verschlüsselt</span>
              <span v-else class="label">{{ list.name }}</span>
            </RouterLink>
          </td>
          <td>
            <button
              type="button"
              class="owner"
              :title="`Nur Listen dieses Accounts zeigen`"
              @click="update({ owner: list.owner })"
            >
              <code>{{ list.owner }}</code>
            </button>
            <span class="sub">
              <span v-if="list.encryptionState === 'migrating'" class="tag">{{ ENCRYPTION_LABELS.migrating }}</span>
              <template v-else>{{ ENCRYPTION_LABELS[list.encryptionState] }}</template>
            </span>
          </td>
          <td><AccessInfo :list="list" /></td>
          <td class="num">{{ list.activeItemCount }} / {{ list.itemCount }}</td>
          <td>
            <time :datetime="list.createdAt" :title="list.createdAt">{{ formatDateTime(list.createdAt) }}</time>
            <span class="sub">{{ relativeAge(list.createdAt) }}</span>
          </td>
          <td>
            <time :datetime="list.updatedAt" :title="list.updatedAt">{{ formatDateTime(list.updatedAt) }}</time>
            <span class="sub">{{ relativeAge(list.updatedAt) }}</span>
          </td>
          <td>
            <span v-if="list.deletedAt" class="status deleted">gelöscht</span>
            <span v-else class="status">aktiv</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
  <p v-else-if="page && !loading" class="muted">Keine Listen gefunden.</p>

  <nav v-if="page && pageCount > 1" class="pager" aria-label="Seiten">
    <button type="button" class="link" :disabled="filter.page <= 1" @click="goToPage(filter.page - 1)">Zurück</button>
    <span>Seite {{ filter.page }} von {{ pageCount }}</span>
    <button type="button" class="link" :disabled="filter.page >= pageCount" @click="goToPage(filter.page + 1)">Weiter</button>
  </nav>

  <p class="muted note">
    Das Account-Kürzel ist ein gekürzter Hash der Account-UUID. Die UUID selbst ist das Zugangs-Secret der Nutzer und
    wird hier nie angezeigt. Listennamen, Einträge und Notizen sind Nutzerinhalt.
  </p>
</template>
