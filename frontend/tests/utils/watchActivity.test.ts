import { describe, expect, it } from 'bun:test'
import { buildWatchActivity } from '~/utils/watchActivity'

describe('watch activity', () => {
  it('week across years', () => {
    const chart = buildWatchActivity([
      { date: '2025-12-28', movies: 8, episodes: 8 },
      { date: '2025-12-29', movies: 1, episodes: 0 },
      { date: '2026-01-04', movies: 0, episodes: 2 },
      { date: '2026-01-05', movies: 8, episodes: 8 },
    ], 'week', 0, Date.UTC(2026, 0, 1))

    expect(chart.buckets).toHaveLength(7)
    expect(chart.buckets[0]?.date).toBe('2025-12-29')
    expect(chart.buckets[6]?.date).toBe('2026-01-04')
    expect(chart.buckets[1]?.movies).toBe(0)
    expect(chart.movies).toBe(1)
    expect(chart.episodes).toBe(2)
    expect(chart.hasEarlier).toBe(true)
  })

  it('previous leap month', () => {
    const chart = buildWatchActivity([
      { date: '2024-02-29', movies: 1, episodes: 2 },
      { date: '2024-03-01', movies: 3, episodes: 4 },
    ], 'month', -1, Date.UTC(2024, 2, 31))

    expect(chart.buckets).toHaveLength(29)
    expect(chart.buckets[28]?.date).toBe('2024-02-29')
    expect(chart.movies).toBe(1)
    expect(chart.episodes).toBe(2)
    expect(chart.hasEarlier).toBe(false)
  })

  it('partial quarter weeks', () => {
    const chart = buildWatchActivity([
      { date: '2026-03-31', movies: 8, episodes: 8 },
      { date: '2026-04-01', movies: 1, episodes: 2 },
      { date: '2026-04-05', movies: 2, episodes: 1 },
      { date: '2026-04-06', movies: 0, episodes: 4 },
      { date: '2026-06-30', movies: 1, episodes: 0 },
      { date: '2026-07-01', movies: 8, episodes: 8 },
    ], 'quarter', 0, Date.UTC(2026, 5, 30))

    expect(chart.buckets[0]).toMatchObject({ date: '2026-04-01', movies: 3, episodes: 3 })
    expect(chart.buckets[1]).toMatchObject({ date: '2026-04-06', episodes: 4 })
    expect(chart.buckets.at(-1)).toMatchObject({ date: '2026-06-29', movies: 1, episodes: 0 })
    expect(chart.movies).toBe(4)
    expect(chart.episodes).toBe(7)
  })

  it('year by month', () => {
    const chart = buildWatchActivity([
      { date: '2025-12-31', movies: 1, episodes: 0 },
      { date: '2026-01-01', movies: 1, episodes: 2 },
      { date: '2026-01-31', movies: 3, episodes: 4 },
      { date: '2026-12-31', movies: 1, episodes: 0 },
      { date: '2027-01-01', movies: 1, episodes: 0 },
    ], 'year', -1, Date.UTC(2027, 0, 1))

    expect(chart.buckets).toHaveLength(12)
    expect(chart.buckets[0]).toMatchObject({ date: '2026-01-01', movies: 4, episodes: 6 })
    expect(chart.buckets[1]).toMatchObject({ date: '2026-02-01', movies: 0, episodes: 0 })
    expect(chart.buckets[11]).toMatchObject({ date: '2026-12-01', movies: 1, episodes: 0 })
    expect(chart.movies).toBe(5)
  })
})
