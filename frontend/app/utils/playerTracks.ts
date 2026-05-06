import type { PlayerSubtitleTrack } from '~/types';

type TrackState = {
  id: string | null;
  enabled: boolean;
};

const normalizeSubtitleTrackId = (trackId: string | null | undefined): string | null => {
  return trackId !== undefined && trackId !== null && '' !== trackId ? trackId : null;
};

const resolveSelectedSubtitleTrackId = (
  tracks: Array<PlayerSubtitleTrack>,
  preferredTrackId: string | null | undefined,
): string | null => {
  const nextId = normalizeSubtitleTrackId(preferredTrackId);
  if (nextId !== null && tracks.some((track) => track.id === nextId)) {
    return nextId;
  }

  const firstTrack = tracks[0];
  return firstTrack ? firstTrack.id : null;
};

const syncTrackState = (
  tracks: Array<PlayerSubtitleTrack>,
  currentTrackId: string | null,
  preferredTrackId: string | null | undefined,
  enabled: boolean,
): TrackState => {
  const currentId = normalizeSubtitleTrackId(currentTrackId);
  if (currentId !== null && tracks.some((track) => track.id === currentId)) {
    return { id: currentId, enabled };
  }

  if (0 === tracks.length) {
    return { id: null, enabled: false };
  }

  const preferredId = normalizeSubtitleTrackId(preferredTrackId);
  if (null === preferredId) {
    return { id: null, enabled: false };
  }

  return {
    id: resolveSelectedSubtitleTrackId(tracks, preferredId),
    enabled: true,
  };
};

export { normalizeSubtitleTrackId, resolveSelectedSubtitleTrackId, syncTrackState };
