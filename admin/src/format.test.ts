import { describe, expect, it } from 'vitest'
import { colorHex, formatQuantity, itemText, plural, relativeAge } from './format'

describe('formatQuantity', () => {
  it('formatiert wie die App', () => {
    expect(formatQuantity(2)).toBe('2')
    expect(formatQuantity(0.5)).toBe('0,5')
    expect(formatQuantity(1.25)).toBe('1,25')
    expect(formatQuantity(1 / 3)).toBe('0,333')
    expect(formatQuantity(null)).toBe('')
  })
})

describe('itemText', () => {
  it('setzt Menge, Einheit und Name zusammen und laesst Leeres weg', () => {
    expect(itemText({ quantity: 0.5, unit: 'kg', name: 'Mehl' })).toBe('0,5 kg Mehl')
    expect(itemText({ quantity: 2, unit: null, name: 'Zucker' })).toBe('2 Zucker')
    expect(itemText({ quantity: null, unit: null, name: 'Brot' })).toBe('Brot')
  })
})

describe('colorHex', () => {
  it('ignoriert das Alpha-Byte (Werte ueber INT32_MAX)', () => {
    expect(colorHex(4283215696)).toBe('#4caf50')
    expect(colorHex(0xff000000)).toBe('#000000')
  })
})

describe('relativeAge', () => {
  const now = new Date('2026-11-17T12:00:00Z')

  it('nennt Tage, Monate und Jahre', () => {
    expect(relativeAge('2026-11-17T11:59:30Z', now)).toBe('gerade eben')
    expect(relativeAge('2026-11-14T12:00:00Z', now)).toBe('vor 3 Tagen')
    expect(relativeAge('2026-05-17T12:00:00Z', now)).toBe('vor 6 Monaten')
    expect(relativeAge('2023-11-17T12:00:00Z', now)).toBe('vor 3 Jahren')
  })
})

describe('plural', () => {
  it('waehlt die Form nach der Zahl', () => {
    expect(plural(1, 'Liste', 'Listen')).toBe('1 Liste')
    expect(plural(0, 'Liste', 'Listen')).toBe('0 Listen')
  })
})
