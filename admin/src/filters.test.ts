import { describe, expect, it } from 'vitest'
import { DEFAULT_FILTER, filterFromQuery, filterToApiParams, filterToQuery, isFiltered } from './filters'

describe('filterFromQuery', () => {
  it('liefert ohne Parameter den Standard', () => {
    expect(filterFromQuery({})).toEqual(DEFAULT_FILTER)
  })

  it('verwirft unbekannte Werte statt sie an die API zu geben', () => {
    const filter = filterFromQuery({
      status: 'archived',
      sort: 'l.id; DROP TABLE',
      dir: 'up',
      owner: 'xyz',
      age: 'gt9000',
      page: '-3',
    })
    expect(filter).toEqual(DEFAULT_FILTER)
  })

  it('normalisiert das Account-Kuerzel auf Kleinbuchstaben', () => {
    expect(filterFromQuery({ owner: 'AB12' }).owner).toBe('ab12')
  })

  it('nimmt bei mehrfachen Parametern den ersten', () => {
    expect(filterFromQuery({ q: ['milch', 'brot'] }).q).toBe('milch')
  })
})

describe('filterToQuery', () => {
  it('schreibt nur Abweichungen vom Standard', () => {
    expect(filterToQuery(DEFAULT_FILTER)).toEqual({})
    expect(filterToQuery({ ...DEFAULT_FILTER, status: 'deleted', page: 2 })).toEqual({ status: 'deleted', page: '2' })
  })

  it('laesst das Bezugsfeld ohne Altersfilter weg', () => {
    expect(filterToQuery({ ...DEFAULT_FILTER, ageField: 'updated' })).toEqual({})
    expect(filterToQuery({ ...DEFAULT_FILTER, age: 'gt30', ageField: 'updated' })).toEqual({
      age: 'gt30',
      ageField: 'updated',
    })
  })

  it('ist die Umkehrung von filterFromQuery', () => {
    const filter = { ...DEFAULT_FILTER, q: 'milch', owner: 'abc', encryption: 'encrypted' as const, sort: 'name' as const, dir: 'asc' as const, page: 3 }
    const query = filterToQuery(filter) as Record<string, string>
    expect(filterFromQuery(query)).toEqual(filter)
  })
})

describe('filterToApiParams', () => {
  it('rechnet Seite in Versatz um', () => {
    const params = filterToApiParams({ ...DEFAULT_FILTER, page: 3 }, 50)
    expect(params.get('limit')).toBe('50')
    expect(params.get('offset')).toBe('100')
    expect(params.get('status')).toBe('all')
    expect(params.has('q')).toBe(false)
  })

  it('setzt die Altersgrenzen der Stufe', () => {
    const older = filterToApiParams({ ...DEFAULT_FILTER, age: 'gt90', ageField: 'updated' })
    expect(older.get('minAgeDays')).toBe('90')
    expect(older.has('maxAgeDays')).toBe(false)
    expect(older.get('ageField')).toBe('updated')

    const younger = filterToApiParams({ ...DEFAULT_FILTER, age: 'lt7' })
    expect(younger.get('maxAgeDays')).toBe('7')
    expect(younger.get('ageField')).toBe('created')
  })

  it('reicht Suche, Konto und Verschluesselung durch', () => {
    const params = filterToApiParams({ ...DEFAULT_FILTER, q: '  milch ', owner: 'ab12', encryption: 'migrating' })
    expect(params.get('q')).toBe('milch')
    expect(params.get('owner')).toBe('ab12')
    expect(params.get('encryption')).toBe('migrating')
  })
})

describe('isFiltered', () => {
  it('erkennt Filter, aber nicht Sortierung oder Seite', () => {
    expect(isFiltered(DEFAULT_FILTER)).toBe(false)
    expect(isFiltered({ ...DEFAULT_FILTER, sort: 'name', page: 4 })).toBe(false)
    expect(isFiltered({ ...DEFAULT_FILTER, owner: 'ab' })).toBe(true)
  })
})
