import { afterEach, beforeEach, describe, expect, it, mock } from 'bun:test'
import type { FetchEventSourceInit } from '@microsoft/fetch-event-source'
import { ref } from 'vue'

let streamOptions: FetchEventSourceInit | null = null

mock.module('@vueuse/core', () => ({
  useStorage: <T>(_key: string, initialValue: T) => ref(initialValue),
}))

mock.module('@microsoft/fetch-event-source', () => ({
  fetchEventSource: (_input: RequestInfo, options: FetchEventSourceInit): Promise<void> => {
    streamOptions = options
    return new Promise(() => {})
  },
}))

mock.module('~/utils', () => ({
  formatCommandEcho: (_prefix: string | undefined, _exitCode: number, command: string) => command,
  parse_api_response: async (response: Response): Promise<unknown> => response.json(),
  request: async (): Promise<Response> => new Response('{}'),
}))

mock.module('~/utils/signBody', () => ({
  signBody: async (): Promise<string> => 'signature',
}))

const { useConsoleStream } = await import('~/composables/useConsoleStream')

class MemoryStorage implements Storage {
  readonly writes: Array<string> = []
  failWrites = false
  private readonly values = new Map<string, string>()

  get length(): number {
    return this.values.size
  }

  clear(): void {
    this.values.clear()
  }

  getItem(key: string): string | null {
    return this.values.get(key) ?? null
  }

  key(index: number): string | null {
    return Array.from(this.values.keys())[index] ?? null
  }

  removeItem(key: string): void {
    this.values.delete(key)
  }

  seed(key: string, value: unknown): void {
    this.values.set(key, JSON.stringify(value))
  }

  setItem(key: string, value: string): void {
    if (this.failWrites) {
      throw new DOMException('Storage quota exceeded.', 'QuotaExceededError')
    }

    this.writes.push(key)
    this.values.set(key, value)
  }
}

let storage: MemoryStorage
let consoleStream: ReturnType<typeof useConsoleStream> | null = null

beforeEach(() => {
  storage = new MemoryStorage()
  storage.seed('consoleActiveSession', {
    token: 'session-token',
    command: 'console system:tasks',
    displayCommand: 'console system:tasks',
    lastSequence: 4,
    resumeSequence: 4,
  })
  Object.defineProperty(globalThis, 'window', {
    configurable: true,
    value: { localStorage: storage },
  })
  streamOptions = null
})

afterEach(() => {
  consoleStream?.closeStreamView()
  consoleStream = null
  Reflect.deleteProperty(globalThis, 'window')
})

describe('console stream', () => {
  it('keeps metadata events active', async () => {
    consoleStream = useConsoleStream()
    expect(await consoleStream.restoreRun()).toBeTrue()
    expect(streamOptions?.headers?.['Last-Event-ID']).toBe('4')

    await streamOptions?.onopen?.(new Response(null, { status: 200 }))
    streamOptions?.onmessage?.({ id: '5', event: 'cmd', data: '["bin/console"]' })
    streamOptions?.onmessage?.({ id: '6', event: 'cwd', data: '/tmp' })

    expect(consoleStream.state.value.status).toBe('streaming')
    expect(consoleStream.state.value.lastSequence).toBe(6)
    expect(consoleStream.state.value.token).toBe('session-token')
  })

  it('ignores heartbeat events', async () => {
    consoleStream = useConsoleStream()
    expect(await consoleStream.restoreRun()).toBeTrue()

    await streamOptions?.onopen?.(new Response(null, { status: 200 }))
    streamOptions?.onmessage?.({ id: '', event: '', data: '' })
    streamOptions?.onmessage?.({ id: '5', event: 'data', data: '{"data":"output"}' })

    expect(consoleStream.state.value.status).toBe('streaming')
    expect(consoleStream.state.value.lastSequence).toBe(5)
    expect(consoleStream.state.value.chunks).toEqual(['output'])
  })

  it('rejects malformed events before advancing', async () => {
    consoleStream = useConsoleStream()
    expect(await consoleStream.restoreRun()).toBeTrue()

    expect(() =>
      streamOptions?.onmessage?.({ id: '5', event: 'data', data: '{"data":false}' }),
    ).toThrow('Malformed command output event.')
    expect(consoleStream.state.value.status).toBe('error')
    expect(consoleStream.state.value.lastSequence).toBe(4)
  })

  it('persists output before its checkpoint', async () => {
    consoleStream = useConsoleStream()
    expect(await consoleStream.restoreRun()).toBeTrue()
    storage.writes.length = 0

    streamOptions?.onmessage?.({ id: '5', event: 'data', data: '{"data":"output"}' })
    await new Promise((resolve) => setTimeout(resolve, 300))

    expect(storage.writes).toEqual(['consoleActiveView', 'consoleActiveSession'])
    expect(JSON.parse(storage.getItem('consoleActiveView') ?? '{}').lastSequence).toBe(5)
    expect(JSON.parse(storage.getItem('consoleActiveSession') ?? '{}').lastSequence).toBe(5)
  })

  it('survives storage quota failures', async () => {
    consoleStream = useConsoleStream()
    expect(await consoleStream.restoreRun()).toBeTrue()
    storage.failWrites = true

    streamOptions?.onmessage?.({ id: '5', event: 'data', data: '{"data":"output"}' })
    await new Promise((resolve) => setTimeout(resolve, 300))

    expect(consoleStream.state.value.lastSequence).toBe(5)
    expect(consoleStream.state.value.lastConnectionError).toBe(
      'Browser storage is unavailable; server replay remains available.',
    )
  })
})
