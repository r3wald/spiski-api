import type { AdminItem, EncryptionState } from './api'

/** Wie die App: ganze Zahlen ohne Nachkommastellen, sonst mit Komma (`0,5`), hoechstens 3 Stellen. */
export function formatQuantity(value: number | null): string {
  if (value === null || !Number.isFinite(value)) return ''
  if (Number.isInteger(value)) return String(value)
  return value.toFixed(3).replace(/0+$/, '').replace(/\.$/, '').replace('.', ',')
}

/** Menge, Einheit und Name wie in der App, z. B. `0,5 kg Mehl`. */
export function itemText(item: Pick<AdminItem, 'quantity' | 'unit' | 'name'>): string {
  return [formatQuantity(item.quantity), item.unit ?? '', item.name ?? ''].filter((part) => part !== '').join(' ')
}

/** Farbpunkt: ARGB aus Flutter, das Alpha-Byte bleibt aussen vor. */
export function colorHex(argb: number): string {
  return `#${(argb % 0x1000000).toString(16).padStart(6, '0')}`
}

const dateTime = new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium', timeStyle: 'short' })

/** Zeitpunkt in der lokalen Zeitzone des Browsers. */
export function formatDateTime(iso: string): string {
  return dateTime.format(new Date(iso))
}

const relative = new Intl.RelativeTimeFormat('de-DE', { numeric: 'auto' })

/** Alter als "vor 3 Tagen", "heute", "vor 2 Jahren". */
export function relativeAge(iso: string, now: Date = new Date()): string {
  const days = Math.floor((now.getTime() - new Date(iso).getTime()) / 86_400_000)
  if (days < 1) {
    const hours = Math.floor((now.getTime() - new Date(iso).getTime()) / 3_600_000)
    return hours < 1 ? 'gerade eben' : relative.format(-hours, 'hour')
  }
  if (days < 60) return relative.format(-days, 'day')
  if (days < 730) return relative.format(-Math.floor(days / 30.44), 'month')
  return relative.format(-Math.floor(days / 365.25), 'year')
}

export function plural(count: number, singular: string, pluralForm: string): string {
  return `${count} ${count === 1 ? singular : pluralForm}`
}

export const ENCRYPTION_LABELS: Record<EncryptionState, string> = {
  plaintext: 'unverschlüsselt',
  migrating: 'wird umgestellt',
  encrypted: 'verschlüsselt',
}
