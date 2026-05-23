import { describe, expect, it } from 'bun:test'

import { parseLogLine } from '~/utils/logs'

describe('logs', () => {
  it('maps structured state and remote ids', () => {
    const parsed = parseLogLine(
      JSON.stringify({
        id: 'log-id',
        datetime: '2026-05-20T12:00:00.123+00:00',
        level: 'notice',
        logger: 'app',
        message: 'Processing webhook',
        fields: {
          state_id: 12,
          remote_id: 'abc-123',
          user: 'main',
          backend: 'plex',
        },
      }),
    )

    expect(parsed.state_id).toBe('12')
    expect(parsed.remote_id).toBe('abc-123')
  })

  it('maps nested structured ids', () => {
    const parsed = parseLogLine(
      JSON.stringify({
        id: 'log-id',
        datetime: '2026-05-20T12:00:00.123+00:00',
        level: 'notice',
        logger: 'app',
        message: 'Processing webhook',
        fields: {
          attributes: {
            item: {
              state_id: 12,
              remote_id: 'abc-123',
            },
          },
          user: {
            name: 'main',
          },
          backend: {
            name: 'plex',
          },
        },
      }),
    )

    expect(parsed.state_id).toBe('12')
    expect(parsed.remote_id).toBe('abc-123')
    expect(parsed.user).toBe('main')
    expect(parsed.backend).toBe('plex')
  })
})
