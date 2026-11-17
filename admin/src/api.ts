import { reactive } from 'vue'

/**
 * Zugriff auf /v1/admin. Das Admin-Token geht bei jedem Request im Header
 * `Authorization: Bearer` mit, nie in der URL. Es liegt nur im sessionStorage dieses
 * Tabs und ist mit dem Schliessen weg. Ohne gespeichertes Token geht der Request ohne
 * Header raus: Eine Header-Erweiterung (ModHeader o. ae.) kann ihn dann ergaenzen.
 */

export type EncryptionState = 'plaintext' | 'migrating' | 'encrypted'

export interface AdminItem {
  id: string
  encrypted: boolean
  name: string | null
  quantity: number | null
  unit: string | null
  isChecked: boolean
  note: string | null
  sortOrder: number
  createdAt: string
  updatedAt: string
  deletedAt: string | null
  movedAt: string | null
}

export interface AdminList {
  id: string
  /** Gekuerztes SHA-256 der Account-UUID; die UUID selbst ist ein Secret und kommt nie an. */
  owner: string
  encryptionState: EncryptionState
  encrypted: boolean
  name: string | null
  color: number
  sortOrder: number
  createdAt: string
  updatedAt: string
  deletedAt: string | null
  itemCount: number
  activeItemCount: number
  shared: boolean
  memberCount: number
  userCount: number
  shareCodeActive: boolean
}

export interface AdminListDetail extends AdminList {
  ownerListCount: number
  items: AdminItem[]
}

export interface ListPage {
  total: number
  ownerCount: number
  limit: number
  offset: number
  lists: AdminList[]
}

export interface Owner {
  owner: string
  encryptionState: EncryptionState
  listCount: number
  activeListCount: number
}

export type DeleteResult = 'deleted' | 'already-deleted'

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message)
  }
}

const TOKEN_KEY = 'spiski-admin-token'

function readStoredToken(): string | null {
  try {
    return sessionStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export const auth = reactive({
  token: readStoredToken(),
  /** true, sobald die API 401 meldet: die App zeigt dann die Anmeldung. */
  required: false,
})

export function setToken(token: string | null): void {
  auth.token = token
  try {
    if (token === null) sessionStorage.removeItem(TOKEN_KEY)
    else sessionStorage.setItem(TOKEN_KEY, token)
  } catch {
    // Ohne Speicher gilt das Token nur, bis die Seite neu geladen wird.
  }
}

async function request<T>(method: 'GET' | 'DELETE', path: string, params?: URLSearchParams): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (auth.token) headers.Authorization = `Bearer ${auth.token}`

  const query = params && params.toString() !== '' ? `?${params.toString()}` : ''
  let response: Response
  try {
    response = await fetch(`/v1/admin${path}${query}`, {
      method,
      headers,
      cache: 'no-store',
      credentials: 'omit',
      referrerPolicy: 'no-referrer',
    })
  } catch {
    throw new ApiError(0, 'Der Server ist nicht erreichbar.')
  }

  if (response.status === 401) {
    auth.required = true
  }

  if (!response.ok) {
    throw new ApiError(response.status, await problemDetail(response))
  }

  return (await response.json()) as T
}

/** Fehlertext aus RFC-9457-Problem-Details, sonst eine allgemeine Meldung. */
async function problemDetail(response: Response): Promise<string> {
  try {
    const body = (await response.json()) as { detail?: unknown; title?: unknown }
    if (typeof body.detail === 'string' && body.detail !== '') {
      const retry = response.headers.get('Retry-After')
      return retry ? `${body.detail} Erneut versuchen in ${retry} s.` : body.detail
    }
    if (typeof body.title === 'string') return body.title
  } catch {
    // kein JSON
  }
  return `Fehler ${response.status}`
}

export const api = {
  lists: (params: URLSearchParams) => request<ListPage>('GET', '/lists', params),
  owners: () => request<{ owners: Owner[] }>('GET', '/owners'),
  list: (id: string) => request<AdminListDetail>('GET', `/lists/${encodeURIComponent(id)}`),
  deleteList: (id: string) =>
    request<{ result: DeleteResult }>('DELETE', `/lists/${encodeURIComponent(id)}`),
  deleteItem: (id: string) =>
    request<{ result: DeleteResult }>('DELETE', `/items/${encodeURIComponent(id)}`),
}
