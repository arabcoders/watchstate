import { describe, expect, it } from 'bun:test'

import type { PlayerSubtitleTrack } from '~/types'
import { syncTrackState } from '~/utils/playerTracks'

const tracks: Array<PlayerSubtitleTrack> = [
  {
    id: 'i:2',
    source: 'internal',
    kind: 'internal',
    label: 'English',
    name: 'English',
    lang: 'en',
    renderer: 'native',
    delivery_format: 'vtt',
    url: '/sub/2.vtt',
    isText: true,
    isBitmap: false,
  },
  {
    id: 'x:0',
    source: 'external',
    kind: 'external',
    label: 'Arabic',
    name: 'Arabic',
    lang: 'ar',
    renderer: 'assjs',
    delivery_format: 'ass',
    url: '/sub/0.ass',
    isText: true,
    isBitmap: false,
  },
]

describe('playerTracks', () => {
  it('keeps subtitle none when preferred track is empty', () => {
    expect(syncTrackState(tracks, null, null, false)).toEqual({ id: null, enabled: false })
  })

  it('keeps an explicit off state while tracks refresh', () => {
    expect(syncTrackState(tracks, 'x:0', null, false)).toEqual({ id: 'x:0', enabled: false })
  })

  it('restores the preferred track when the current one disappears', () => {
    expect(syncTrackState(tracks, 'x:9', 'i:2', false)).toEqual({ id: 'i:2', enabled: true })
  })
})
