import { afterEach, beforeEach, describe, expect, it, mock, spyOn } from 'bun:test'
import { effectScope, ref } from 'vue'
import type { EffectScope } from 'vue'
import type { HistoryListItem, RequestOptions } from '~/types'

const pending: Array<{
  url: string
  options: RequestOptions
  resolve: (response: Response) => void
}> = []
const notices: Array<string> = []

mock.module('~/utils', () => ({
  request: (url: string, options: RequestOptions = {}): Promise<Response> =>
    new Promise((resolve) => {
      pending.push({ url, options, resolve })
    }),
  parse_api_response: async (response: Response): Promise<unknown> => {
    try {
      return await response.json()
    } catch {
      return { error: { code: response.status, message: response.statusText } }
    }
  },
  notification: (_type: string, _title: string, message: string): void => {
    notices.push(message)
  },
}))

const { useHistoryCuration } = await import('~/composables/useHistoryCuration')
let scope: EffectScope
const user = ref('main')
const blocked = ref(false)
const pageFilter = ref('')
const link = { href: '', download: '', click: mock(() => {}), remove: mock(() => {}) }
let originalDocument: PropertyDescriptor | undefined

const record = (id: number, watched = false): HistoryListItem => ({
  id,
  watched,
  title: `Movie ${id}`,
  type: 'movie',
  updated: 100,
  via: 'plex',
  guids: {},
  created_at: 100,
  updated_at: 100,
  reported_by: ['plex'],
  not_reported_by: [],
})

beforeEach(() => {
  scope = effectScope()
  pending.length = 0
  notices.length = 0
  user.value = 'main'
  blocked.value = false
  pageFilter.value = ''
  link.click.mockClear()
  link.remove.mockClear()
  originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document')
  Object.defineProperty(globalThis, 'document', {
    configurable: true,
    value: { createElement: () => link, body: { appendChild: () => {} } },
  })
})

afterEach(() => {
  scope.stop()
  if (originalDocument) {
    Object.defineProperty(globalThis, 'document', originalDocument)
  } else {
    Reflect.deleteProperty(globalThis, 'document')
  }
})

describe('history selection', () => {
  it('query roundtrip', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    const query = state.readQuery({
      'genres[]': ['Drama', 'Comedy'],
      'library[]': 'plex://1',
      'sort[]': ['year:desc', 'title:asc'],
      page: '2',
      perpage: '100',
      filter: 'page only',
      status: 'watched',
      year_min: '2000',
      year_max: '2020',
      metadata: '1',
      key: 'plex.extra.favorite',
      value: '1',
    })
    expect(query).toEqual({
      genres: ['Drama', 'Comedy'],
      library: ['plex://1'],
      sort: ['year:desc', 'title:asc'],
      status: 'watched',
      year_min: '2000',
      year_max: '2020',
      metadata: 'plex.extra.favorite://1',
    })
    expect(state.buildFilters(query)).toEqual({
      genres: ['Drama', 'Comedy'],
      library: ['plex://1'],
      sort: ['year:desc', 'title:asc'],
      status: 'watched',
      year_min: '2000',
      year_max: '2020',
      metadata: '1',
      key: 'plex.extra.favorite',
      value: '1',
    })
    expect(
      state.readQuery({ genres: 'Drama', library: 'plex://1', key: 'parent', q: 'tvdb://123' }),
    ).toEqual({
      genres: 'Drama',
      library: 'plex://1',
      parent: 'tvdb://123',
    })
    expect(state.readQuery({ sort: ['title:asc', 'year:desc'] }).sort).toEqual([
      'title:asc',
      'year:desc',
    ])
  })

  it('JSON filter validation', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    expect(() => state.buildFilters({ metadata: 'invalid' })).toThrow('Invalid search format')
    expect(state.buildFilters({ extra: 'plex.id://scheme://123' })).toEqual({
      extra: '1',
      key: 'plex.id',
      value: 'scheme://123',
    })
  })

  it('pages & refresh', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({ type: 'movie' }, 100, [record(1), record(2)])
    state.selectRecords([record(1)], true)
    state.beginQuery({ type: 'movie' })
    expect(state.selectedIds.value).toEqual([1])
    expect(state.matchingEnabled.value).toBeFalse()
    state.applyQuery({ type: 'movie' }, 100, [record(51)])
    state.selectRecords([record(51)], true)
    expect(state.selectedIds.value).toEqual([1, 51])
    state.applyQuery({ type: 'movie' }, 100, [record(1, true)])
    expect(state.selection.value.map(({ id, watched }) => ({ id, watched }))).toEqual([
      { id: 1, watched: true },
      { id: 51, watched: false },
    ])
  })

  it('unselect page', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 100, [record(1)])
    state.selectRecords([record(1)], true)
    state.applyQuery({}, 100, [record(51), record(52)])
    state.selectRecords([record(51), record(52)], true)
    state.selectRecords([record(51), record(52)], false)
    expect(state.selectedIds.value).toEqual([1])
    state.clearSelection()
    expect(state.selectedIds.value).toEqual([])
  })

  it('sort scope', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({ sort: ['year:desc', 'title:asc'] }, 100, [record(1)])
    state.selectRecords([record(1)], true)
    state.applyQuery({ sort: ['title:asc', 'year:desc'] }, 100, [record(51)])
    expect(state.selectedIds.value).toEqual([1])
  })

  it('query scope', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({ status: 'watched', genres: ['Drama', 'Comedy'] }, 100, [record(1)])
    state.selectRecords([record(1)], true)
    state.applyQuery({ genres: ['Comedy', 'Drama'], status: 'watched' }, 100, [record(51)])
    expect(state.selectedIds.value).toEqual([1])
    state.beginQuery({ status: 'in_progress' })
    expect(state.selectedIds.value).toEqual([])
    expect(state.matchingCount.value).toBe(0)
    expect(state.matchingEnabled.value).toBeFalse()
    expect(state.selectedEnabled.value).toBeFalse()
  })

  it('identity scope', () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 100, [record(1)])
    state.selectRecords([record(1)], true)
    user.value = 'family'
    expect(state.selectedIds.value).toEqual([])
    expect(state.selection.value).toEqual([])
    expect(state.matchingCount.value).toBe(0)
    expect(state.selectedEnabled.value).toBeFalse()
    state.selectRecords([record(1)], true)
    expect(state.selectedIds.value).toEqual([])
  })
})

describe('history exports', () => {
  it('matching scope', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    const filters = {
      type: 'movie',
      status: 'watched',
      genres: ['Drama'],
      library: ['plex://1'],
      year_min: '2000',
      metadata: '1',
      key: 'plex.extra.favorite',
      value: '1',
    }
    state.applyQuery(filters, 1234, [record(1)])
    state.selectRecords([record(1)], true)
    const exported = state.exportHistory({ scope: 'matching', group: 'titles', format: 'markdown' })
    const revoked = spyOn(URL, 'revokeObjectURL')
    expect(pending[0]?.url).toBe('/history/export/markdown')
    expect(pending[0]?.options.method).toBe('POST')
    expect(pending[0]?.options.headers?.['X-User']).toBe('main')
    expect(JSON.parse(String(pending[0]?.options.body))).toEqual({ filters, group: 'titles' })
    pending[0]?.resolve(new Response('# Movies'))
    expect(await exported).toBeTrue()
    expect(link.download).toMatch(/^history-\d+\.md$/)
    expect(link.download).not.toContain('main')
    expect(link.click).toHaveBeenCalledTimes(1)
    expect(link.remove).toHaveBeenCalledTimes(1)
    expect(revoked).toHaveBeenCalledWith(link.href)
    revoked.mockRestore()
    expect(state.exporting.value).toBeFalse()
  })

  it('selected scope', async () => {
    user.value = 'family'
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({ status: 'watched' }, 100, [record(1)])
    state.selectRecords([record(1)], true)
    state.applyQuery({ status: 'watched' }, 100, [record(51)])
    state.selectRecords([record(51)], true)
    const exported = state.exportHistory({ scope: 'selected', group: 'records', format: 'json' })
    expect(pending[0]?.url).toBe('/history/export/json')
    expect(pending[0]?.options.headers?.['X-User']).toBe('family')
    expect(JSON.parse(String(pending[0]?.options.body))).toEqual({
      filters: { status: 'watched' },
      group: 'records',
      ids: [1, 51],
    })
    pending[0]?.resolve(Response.json({ records: [] }))
    expect(await exported).toBeTrue()
    expect(link.download).toMatch(/^history-\d+\.json$/)
    expect(link.download).not.toContain('family')
  })

  it('applied snapshot', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    const filters = { title: 'Applied', genres: ['Drama'] }
    state.applyQuery(filters, 100, [record(1)])
    filters.title = 'Draft'
    filters.genres.push('Comedy')
    const choice = { scope: 'matching', group: 'titles', format: 'markdown' } as const
    const exported = state.exportHistory(choice)
    expect(await state.exportHistory(choice)).toBeFalse()
    expect(pending).toHaveLength(1)
    expect(JSON.parse(String(pending[0]?.options.body)).filters).toEqual({
      title: 'Applied',
      genres: ['Drama'],
    })
    pending[0]?.resolve(new Response('# Applied'))
    expect(await exported).toBeTrue()
  })

  it('empty scopes', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    const matching = { scope: 'matching', group: 'titles', format: 'markdown' } as const
    const selected = { ...matching, scope: 'selected' } as const
    expect(await state.exportHistory(matching)).toBeFalse()
    state.applyQuery({}, 0, [])
    expect(await state.exportHistory(matching)).toBeFalse()
    expect(await state.exportHistory(selected)).toBeFalse()
    state.applyQuery({}, 1, [record(1)])
    state.selectRecords([record(1)], true)
    state.applyQuery({}, 0, [])
    expect(await state.exportHistory(selected)).toBeFalse()
    expect(pending).toHaveLength(0)
  })

  it('draft & loading guard', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({ title: 'Applied' }, 100, [record(1)])
    state.selectRecords([record(1)], true)
    blocked.value = true
    expect(state.matchingEnabled.value).toBeFalse()
    expect(state.selectedEnabled.value).toBeFalse()
    expect(
      await state.exportHistory({ scope: 'selected', group: 'titles', format: 'markdown' }),
    ).toBeFalse()
    expect(pending).toHaveLength(0)
  })

  it('page filter guard', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 100, [record(1)])
    state.selectRecords([record(1)], true)
    pageFilter.value = 'Movie 1'
    expect(state.matchingEnabled.value).toBeFalse()
    expect(state.selectedEnabled.value).toBeTrue()
    expect(
      await state.exportHistory({ scope: 'matching', group: 'titles', format: 'markdown' }),
    ).toBeFalse()
    const exported = state.exportHistory({ scope: 'selected', group: 'titles', format: 'markdown' })
    pending[0]?.resolve(new Response('# Selection'))
    expect(await exported).toBeTrue()
    expect(JSON.parse(String(pending[0]?.options.body)).ids).toEqual([1])
  })

  it('identity race', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 100, [record(1)])
    state.selectRecords([record(1)], true)
    const exported = state.exportHistory({ scope: 'selected', group: 'titles', format: 'markdown' })
    user.value = 'family'
    user.value = 'main'
    expect(pending[0]?.options.signal?.aborted).toBeTrue()
    pending[0]?.resolve(new Response('# Old identity'))
    expect(await exported).toBeFalse()
    expect(link.click).not.toHaveBeenCalled()
    expect(state.selectedIds.value).toEqual([])
    expect(state.matchingCount.value).toBe(0)
    expect(notices).toHaveLength(0)
  })

  it('query race', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({ status: 'watched' }, 100, [record(1)])
    const exported = state.exportHistory({ scope: 'matching', group: 'titles', format: 'json' })
    state.beginQuery({ status: 'not_started' })
    expect(pending[0]?.options.signal?.aborted).toBeTrue()
    pending[0]?.resolve(Response.json({ records: [] }))
    expect(await exported).toBeFalse()
    expect(link.click).not.toHaveBeenCalled()
  })

  it('errors', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 1, [record(1)])
    const exported = state.exportHistory({ scope: 'matching', group: 'titles', format: 'json' })
    pending[0]?.resolve(
      Response.json({ error: { code: 400, message: 'Invalid filters' } }, { status: 400 }),
    )
    expect(await exported).toBeFalse()
    expect(notices).toEqual(["Couldn't download the History export. HTTP 400: Invalid filters"])
    expect(state.exporting.value).toBeFalse()
    expect(link.click).not.toHaveBeenCalled()
  })

  it.each([
    [
      Response.json({ error: { message: '  ' } }, { status: 500 }),
      'HTTP 500: The server returned no error details.',
    ],
    [new Response('', { status: 500 }), 'HTTP 500: The server returned no error details.'],
    [
      new Response('<html>Proxy error</html>', { status: 502, statusText: 'Bad Gateway' }),
      'HTTP 502: Bad Gateway',
    ],
  ])('missing error details %#', async (response, reason) => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 1, [record(1)])
    const exported = state.exportHistory({ scope: 'matching', group: 'titles', format: 'json' })
    pending[0]?.resolve(response)

    expect(await exported).toBeFalse()
    expect(notices).toEqual([`Couldn't download the History export. ${reason}`])
    expect(state.exporting.value).toBeFalse()
    expect(link.click).not.toHaveBeenCalled()
  })

  it('dispose', async () => {
    const state = scope.run(() => useHistoryCuration(user, blocked, pageFilter))!
    state.applyQuery({}, 1, [record(1)])
    const exported = state.exportHistory({ scope: 'matching', group: 'titles', format: 'markdown' })
    scope.stop()
    expect(pending[0]?.options.signal?.aborted).toBeTrue()
    pending[0]?.resolve(new Response('# Disposed'))
    expect(await exported).toBeFalse()
    expect(link.click).not.toHaveBeenCalled()
  })
})
