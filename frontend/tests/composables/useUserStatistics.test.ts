import { afterEach, beforeEach, describe, expect, it, mock } from 'bun:test'
import { effectScope, ref } from 'vue'
import type { EffectScope } from 'vue'
import type { RequestOptions, UserStatisticsReport } from '~/types'

type PendingRequest = {
  url: string
  options: RequestOptions
  resolve: (response: Response) => void
}
const pending: Array<PendingRequest> = []
const notices: Array<string> = []

mock.module('~/utils', () => ({
  formatCommandEcho: (_prefix: string | undefined, _exitCode: number, command: string): string =>
    command,
  request: (url: string, options: RequestOptions = {}): Promise<Response> =>
    new Promise((resolve) => {
      pending.push({ url, options, resolve })
    }),
  parse_api_response: async (response: Response): Promise<unknown> => response.json(),
  notification: (_type: string, _title: string, message: string): void => {
    notices.push(message)
  },
}))

const { useUserStatistics } = await import('~/composables/useUserStatistics')
let scope: EffectScope

const snapshot = (title: string): UserStatisticsReport => ({
  version: 1,
  generated_at: 100,
  completed_at: 101,
  summary: {
    movies: { total: 1, watched: 1, in_progress: 0 },
    episodes: { total: 0, watched: 0, in_progress: 0 },
    shows: { total: 0, started: 0, in_progress: 0, fully_watched: 0 },
  },
  genres: [],
  shows: [],
  in_progress: { movies: [], episodes: [] },
  recent: [
    {
      title,
      year: null,
      type: 'movie',
      season: null,
      episode: null,
      played_at: 100,
      history_id: 1,
    },
  ],
})

const respond = async (index: number, report: UserStatisticsReport | null): Promise<void> => {
  pending[index]?.resolve(
    Response.json({ report, queued: false, queued_event: null, stale: false }),
  )
  await Bun.sleep(0)
}

beforeEach(() => {
  scope = effectScope()
  pending.length = 0
  notices.length = 0
})

afterEach(() => scope.stop())

describe('statistics requests', () => {
  it('identity header', async () => {
    const statistics = scope.run(() => useUserStatistics(ref('family')))!
    expect(pending[0]?.url).toBe('/state/user-statistics')
    expect(pending[0]?.options.headers?.['X-User']).toBe('family')
    expect(statistics.isLoading.value).toBeTrue()
    await respond(0, snapshot('Family movie'))
    expect(statistics.report.value?.recent[0]?.title).toBe('Family movie')
    expect(statistics.isLoading.value).toBeFalse()
  })

  it('identity reset', async () => {
    const user = ref('main')
    const statistics = scope.run(() => useUserStatistics(user))!
    await respond(0, snapshot('Main movie'))
    user.value = 'family'
    expect(statistics.report.value).toBeNull()
    expect(statistics.isLoading.value).toBeTrue()
    expect(pending[0]?.options.signal?.aborted).toBeTrue()
    expect(pending[1]?.options.headers?.['X-User']).toBe('family')
    await respond(1, null)
    expect(statistics.report.value).toBeNull()
  })

  it('identity race', async () => {
    const user = ref('main')
    const statistics = scope.run(() => useUserStatistics(user))!
    user.value = 'family'
    user.value = 'main'
    await respond(2, snapshot('New main movie'))
    await respond(0, snapshot('Old main movie'))
    await respond(1, snapshot('Family movie'))
    expect(statistics.report.value?.recent[0]?.title).toBe('New main movie')
  })

  it('reload race', async () => {
    const statistics = scope.run(() => useUserStatistics(ref('main')))!
    const reload = statistics.loadReport()
    await respond(1, snapshot('Latest movie'))
    await reload
    await respond(0, snapshot('Old movie'))
    expect(statistics.report.value?.recent[0]?.title).toBe('Latest movie')
  })

  it('load errors', async () => {
    const statistics = scope.run(() => useUserStatistics(ref('main')))!
    pending[0]?.resolve(
      Response.json({ error: { code: 503, message: 'Unavailable' } }, { status: 503 }),
    )
    await Bun.sleep(0)
    expect(statistics.error.value).toBe('503: Unavailable')
    expect(statistics.isLoading.value).toBeFalse()
    expect(statistics.report.value).toBeNull()
    expect(notices).toContain('503: Unavailable')
  })

  it('queue race', async () => {
    const user = ref('main')
    const statistics = scope.run(() => useUserStatistics(user))!
    await respond(0, null)
    const queued = statistics.queueReport()
    expect(pending[1]?.url).toBe('/state/user-statistics/run')
    expect(pending[1]?.options.method).toBe('POST')
    user.value = 'family'
    pending[1]?.resolve(
      Response.json({
        queued: true,
        running: false,
        event_id: 'event-main',
        message: 'Queued for main',
      }),
    )
    await queued
    expect(statistics.isQueueing.value).toBeFalse()
    expect(statistics.state.value).toBeNull()
    expect(notices).toHaveLength(0)
    expect(pending).toHaveLength(3)
  })

  it('queued snapshot', async () => {
    const statistics = scope.run(() => useUserStatistics(ref('main')))!
    await respond(0, snapshot('Cached movie'))
    const queued = statistics.queueReport()
    pending[1]?.resolve(
      Response.json({ queued: true, running: false, event_id: 'event-main', message: 'Queued' }),
    )
    await Bun.sleep(0)
    expect(statistics.state.value?.queued).toBeTrue()
    expect(statistics.report.value?.recent[0]?.title).toBe('Cached movie')
    pending[2]?.resolve(
      Response.json({
        report: snapshot('Cached movie'),
        queued: true,
        queued_event: 'event-main',
        stale: true,
      }),
    )
    await queued
    expect(statistics.state.value?.stale).toBeTrue()
    expect(statistics.state.value?.queued_event).toBe('event-main')
    expect(statistics.isQueueing.value).toBeFalse()
  })

  it('export errors', async () => {
    const statistics = scope.run(() => useUserStatistics(ref('family')))!
    await respond(0, snapshot('Family movie'))
    const exported = statistics.exportReport('markdown')
    expect(pending[1]?.url).toBe('/state/user-statistics/export/markdown')
    expect(pending[1]?.options.headers?.['X-User']).toBe('family')
    pending[1]?.resolve(
      Response.json({ error: { code: 404, message: 'Snapshot not found' } }, { status: 404 }),
    )
    await exported
    expect(notices).toContain('404: Snapshot not found')
    expect(statistics.exporting.value).toBeNull()
  })

  it('export race', async () => {
    const user = ref('main')
    const statistics = scope.run(() => useUserStatistics(user))!
    await respond(0, snapshot('Main movie'))
    const exported = statistics.exportReport('json')
    user.value = 'family'
    pending[1]?.resolve(Response.json(snapshot('Main movie')))
    await exported
    expect(statistics.exporting.value).toBeNull()
    expect(statistics.report.value).toBeNull()
    expect(notices).toHaveLength(0)
  })

  it('snapshot download', async () => {
    const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document')
    const link = { href: '', download: '', click: mock(() => {}), remove: mock(() => {}) }
    const appendChild = mock(() => {})
    Object.defineProperty(globalThis, 'document', {
      configurable: true,
      value: { createElement: () => link, body: { appendChild } },
    })

    try {
      const statistics = scope.run(() => useUserStatistics(ref('family')))!
      await respond(0, snapshot('Family movie'))
      const exported = statistics.exportReport('markdown')
      pending[1]?.resolve(
        new Response('# Full snapshot', { headers: { 'Content-Type': 'text/markdown' } }),
      )
      await exported

      expect(pending[1]?.url).toBe('/state/user-statistics/export/markdown')
      expect(link.download).toBe('user-statistics-100.md')
      expect(link.download).not.toContain('family')
      expect(link.href.startsWith('blob:')).toBeTrue()
      expect(appendChild).toHaveBeenCalledWith(link)
      expect(link.click).toHaveBeenCalledTimes(1)
      expect(link.remove).toHaveBeenCalledTimes(1)
      expect(statistics.exporting.value).toBeNull()
      expect(notices).toHaveLength(0)
    } finally {
      if (originalDocument) {
        Object.defineProperty(globalThis, 'document', originalDocument)
      } else {
        Reflect.deleteProperty(globalThis, 'document')
      }
    }
  })

  it('dispose', () => {
    scope.run(() => useUserStatistics(ref('main')))
    scope.stop()
    expect(pending[0]?.options.signal?.aborted).toBeTrue()
  })
})
