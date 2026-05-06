import {
  computed,
  getCurrentScope,
  onScopeDispose,
  ref,
  watch,
  type MaybeRefOrGetter,
  toValue,
} from 'vue';
import type { PlayerSubtitleTrack } from '~/types';
import { request } from '~/utils';

type AssRendererInstance = {
  destroy(): unknown;
  show(): unknown;
};

type AssRendererConstructor = new (
  content: string,
  video: HTMLVideoElement,
  options: { container: HTMLElement; resampling: 'video_height' },
) => AssRendererInstance;

type UsePlayerSubtitlesOptions = {
  tracks: MaybeRefOrGetter<Array<PlayerSubtitleTrack>>;
  selectedTrackId: MaybeRefOrGetter<string | null>;
  canPlay: MaybeRefOrGetter<boolean>;
  shouldRender: MaybeRefOrGetter<boolean>;
  assLayoutVersion?: MaybeRefOrGetter<number>;
  video: MaybeRefOrGetter<HTMLVideoElement | null>;
  overlay: MaybeRefOrGetter<HTMLElement | null>;
  fetchText?: (url: string) => Promise<string>;
  loadRenderer?: () => Promise<AssRendererConstructor>;
};

async function defaultFetchSubtitleText(url: string): Promise<string> {
  const res = await request(url, {
    no_prefix: true,
    headers: { Accept: 'text/plain, text/vtt, text/x-ssa' },
  });
  if (false === res.ok) {
    throw new Error('Subtitle fetch failed');
  }

  return res.text();
}

async function defaultLoadAssRenderer(): Promise<AssRendererConstructor> {
  const mod = await import('assjs');
  return mod.default as AssRendererConstructor;
}

export function usePlayerSubtitles(options: UsePlayerSubtitlesOptions) {
  const fetchText = options.fetchText || defaultFetchSubtitleText;
  const loadRenderer = options.loadRenderer || defaultLoadAssRenderer;
  const subtitleLoadError = ref('');
  const subtitleEnabled = ref(true);
  const selectedTrack = computed<PlayerSubtitleTrack | null>(() => {
    const selectedTrackId = toValue(options.selectedTrackId);
    if (selectedTrackId === undefined || selectedTrackId === null || '' === selectedTrackId) {
      return null;
    }

    const tracks = toValue(options.tracks);
    return tracks.find((track) => track.id === selectedTrackId) || null;
  });
  const nativeSubtitleTrack = computed(() => {
    const track = selectedTrack.value;
    return true === subtitleEnabled.value && 'native' === track?.renderer ? track : null;
  });
  const usesAssTrack = computed(() => 'assjs' === selectedTrack.value?.renderer);
  const hasSubtitles = computed(() => toValue(options.tracks).length > 0);

  let assRenderer: AssRendererInstance | null = null;
  let assRequestId = 0;
  let cachedAssSubtitleUrl = '';
  let cachedAssSubtitleContent = '';

  function destroyAssRenderer() {
    assRenderer?.destroy();
    assRenderer = null;
  }

  async function syncAssRenderer() {
    const track = selectedTrack.value;
    const canPlay = toValue(options.canPlay);
    const shouldRender = toValue(options.shouldRender);
    const video = toValue(options.video);
    const overlay = toValue(options.overlay);
    const requestId = ++assRequestId;

    destroyAssRenderer();

    if (
      !track ||
      'assjs' !== track.renderer ||
      false === subtitleEnabled.value ||
      false === canPlay ||
      false === shouldRender ||
      !video ||
      !overlay
    ) {
      return;
    }

    try {
      const subtitleContent =
        cachedAssSubtitleUrl === track.url ? cachedAssSubtitleContent : await fetchText(track.url);
      if (requestId !== assRequestId) {
        return;
      }

      if (cachedAssSubtitleUrl !== track.url) {
        cachedAssSubtitleUrl = track.url;
        cachedAssSubtitleContent = subtitleContent;
      }

      const Ass = await loadRenderer();
      if (requestId !== assRequestId) {
        return;
      }

      assRenderer = new Ass(subtitleContent, video, {
        container: overlay,
        resampling: 'video_height',
      }) as AssRendererInstance;
      assRenderer.show();
      video.dispatchEvent(new Event('seeking'));
      if (false === video.paused) {
        video.dispatchEvent(new Event('playing'));
      }
      subtitleLoadError.value = '';
    } catch {
      if (requestId === assRequestId) {
        subtitleLoadError.value = 'Failed to render ASS subtitles in the browser.';
      }

      destroyAssRenderer();
    }
  }

  watch(
    () => [
      selectedTrack.value?.id ?? '',
      selectedTrack.value?.url ?? '',
      selectedTrack.value?.renderer ?? '',
      subtitleEnabled.value,
      toValue(options.canPlay),
      toValue(options.shouldRender),
      toValue(options.assLayoutVersion) || 0,
      toValue(options.video),
      toValue(options.overlay),
    ],
    () => {
      void syncAssRenderer();
    },
    { immediate: true },
  );

  if (getCurrentScope()) {
    onScopeDispose(() => {
      assRequestId += 1;
      destroyAssRenderer();
    });
  }

  return {
    subtitleLoadError,
    subtitleEnabled,
    selectedSubtitleTrack: selectedTrack,
    nativeSubtitleTrack,
    usesAssSubtitleTrack: usesAssTrack,
    hasSubtitles,
  };
}
