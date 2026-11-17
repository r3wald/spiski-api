import type { LocationQuery, LocationQueryRaw } from 'vue-router'

/**
 * Zustand der Listenuebersicht. Er steht in der URL (Query-String der Route), damit
 * Neuladen, Zurueck und Links auf eine gefilterte Ansicht funktionieren. Das Token
 * steht nie darin.
 */

export const STATUSES = ['all', 'active', 'deleted'] as const
export const ENCRYPTIONS = ['', 'plaintext', 'migrating', 'encrypted'] as const
export const AGE_FIELDS = ['created', 'updated'] as const
export const SORTS = ['updatedAt', 'createdAt', 'name', 'owner', 'items', 'users'] as const
export const DIRECTIONS = ['asc', 'desc'] as const

/** Altersfilter als feste Stufen; Werte in Tagen fuer minAgeDays/maxAgeDays. */
export const AGES = {
  '': { label: 'beliebig' },
  'lt1': { label: 'jünger als 1 Tag', maxAgeDays: 1 },
  'lt7': { label: 'jünger als 7 Tage', maxAgeDays: 7 },
  'lt30': { label: 'jünger als 30 Tage', maxAgeDays: 30 },
  'gt30': { label: 'älter als 30 Tage', minAgeDays: 30 },
  'gt90': { label: 'älter als 90 Tage', minAgeDays: 90 },
  'gt365': { label: 'älter als 1 Jahr', minAgeDays: 365 },
} as const satisfies Record<string, { label: string; minAgeDays?: number; maxAgeDays?: number }>

export type Status = (typeof STATUSES)[number]
export type Encryption = (typeof ENCRYPTIONS)[number]
export type AgeField = (typeof AGE_FIELDS)[number]
export type Sort = (typeof SORTS)[number]
export type Direction = (typeof DIRECTIONS)[number]
export type Age = keyof typeof AGES

export interface ListFilter {
  q: string
  status: Status
  owner: string
  encryption: Encryption
  age: Age
  ageField: AgeField
  sort: Sort
  dir: Direction
  page: number
}

export const PAGE_SIZE = 50

export const DEFAULT_FILTER: Readonly<ListFilter> = {
  q: '',
  status: 'all',
  owner: '',
  encryption: '',
  age: '',
  ageField: 'created',
  sort: 'updatedAt',
  dir: 'desc',
  page: 1,
}

/** Sinnvolle Richtung beim ersten Klick auf eine Spalte: Texte aufsteigend, sonst absteigend. */
export function defaultDirection(sort: Sort): Direction {
  return sort === 'name' || sort === 'owner' ? 'asc' : 'desc'
}

function one(query: LocationQuery, key: string): string {
  const value = query[key]
  const first = Array.isArray(value) ? value[0] : value
  return typeof first === 'string' ? first : ''
}

function pick<T extends string>(value: string, allowed: readonly T[], fallback: T): T {
  return (allowed as readonly string[]).includes(value) ? (value as T) : fallback
}

export function filterFromQuery(query: LocationQuery): ListFilter {
  const page = Number.parseInt(one(query, 'page'), 10)
  const owner = one(query, 'owner').toLowerCase()
  return {
    q: one(query, 'q').slice(0, 100),
    status: pick(one(query, 'status'), STATUSES, DEFAULT_FILTER.status),
    owner: /^[0-9a-f]{1,12}$/.test(owner) ? owner : '',
    encryption: pick(one(query, 'encryption'), ENCRYPTIONS, DEFAULT_FILTER.encryption),
    age: pick(one(query, 'age'), Object.keys(AGES) as Age[], DEFAULT_FILTER.age),
    ageField: pick(one(query, 'ageField'), AGE_FIELDS, DEFAULT_FILTER.ageField),
    sort: pick(one(query, 'sort'), SORTS, DEFAULT_FILTER.sort),
    dir: pick(one(query, 'dir'), DIRECTIONS, DEFAULT_FILTER.dir),
    page: Number.isFinite(page) && page > 0 ? page : 1,
  }
}

/** Nur Abweichungen vom Standard, damit die URL kurz bleibt. */
export function filterToQuery(filter: ListFilter): LocationQueryRaw {
  const query: LocationQueryRaw = {}
  for (const key of Object.keys(DEFAULT_FILTER) as (keyof ListFilter)[]) {
    if (filter[key] !== DEFAULT_FILTER[key]) query[key] = String(filter[key])
  }
  // Das Bezugsfeld zaehlt nur zusammen mit einem Altersfilter.
  if (filter.age === '') delete query.ageField
  return query
}

/** Parameter fuer GET /v1/admin/lists. */
export function filterToApiParams(filter: ListFilter, pageSize: number = PAGE_SIZE): URLSearchParams {
  const params = new URLSearchParams()
  params.set('limit', String(pageSize))
  params.set('offset', String((filter.page - 1) * pageSize))
  params.set('status', filter.status)
  params.set('sort', filter.sort)
  params.set('dir', filter.dir)
  const q = filter.q.trim()
  if (q !== '') params.set('q', q)
  if (filter.owner !== '') params.set('owner', filter.owner)
  if (filter.encryption !== '') params.set('encryption', filter.encryption)

  const age = AGES[filter.age]
  if ('minAgeDays' in age) params.set('minAgeDays', String(age.minAgeDays))
  if ('maxAgeDays' in age) params.set('maxAgeDays', String(age.maxAgeDays))
  if (filter.age !== '') params.set('ageField', filter.ageField)

  return params
}

export function isFiltered(filter: ListFilter): boolean {
  return (
    filter.q !== '' ||
    filter.status !== DEFAULT_FILTER.status ||
    filter.owner !== '' ||
    filter.encryption !== '' ||
    filter.age !== ''
  )
}
