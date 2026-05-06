<template>
  <div ref="playerRoot" class="space-y-4">
    <div
      ref="playerContainer"
      class="relative flex w-full overflow-hidden rounded-sm bg-black"
      :class="
        isFullscreen
          ? 'h-screen w-screen max-h-screen max-w-none items-center justify-center rounded-none'
          : 'min-h-72 h-[min(70vh,56rem)] max-h-224 max-w-full items-center justify-center sm:min-h-88 sm:h-[min(72vh,56rem)]'
      "
    >
      <button
        v-if="!active"
        type="button"
        class="group absolute inset-0 z-40 block min-h-72 overflow-hidden bg-black text-left sm:min-h-88"
        @click="activatePlayer"
      >
        <img
          v-if="posterUrl"
          :src="posterUrl"
          :alt="`${displayTitle} preview`"
          class="block h-full w-full bg-black object-contain opacity-90 transition duration-200 group-hover:opacity-100"
          @error="handlePosterError"
        />
        <div
          v-else
          class="flex h-full w-full items-center justify-center bg-black/90 text-white/80"
        >
          <UIcon :name="isAudio ? 'i-lucide-disc-3' : 'i-lucide-film'" class="size-12" />
        </div>
        <div
          class="pointer-events-none absolute inset-0 bg-linear-to-t from-black/70 via-transparent to-black/20"
        />
        <div
          class="pointer-events-none absolute inset-x-0 bottom-0 flex items-center justify-between gap-4 px-4 py-4 sm:px-6"
        >
          <div class="min-w-0">
            <div class="text-xs uppercase tracking-[0.2em] text-white/70">Click to play</div>
            <div class="mt-1 truncate text-lg font-semibold text-white">
              {{ displayTitle }}
            </div>
          </div>
          <div
            class="flex size-16 shrink-0 items-center justify-center rounded-full bg-white/12 text-white backdrop-blur ring-1 ring-white/25"
          >
            <UIcon name="i-lucide-play" class="ml-1 size-8" />
          </div>
        </div>
      </button>

      <video
        ref="videoElement"
        class="dash-video-element block bg-black object-contain"
        :class="
          isFullscreen
            ? 'h-full w-full max-h-screen max-w-screen'
            : 'h-full min-h-72 w-full max-w-full max-h-224 sm:min-h-88'
        "
        playsinline
        webkit-playsinline
        preload="metadata"
        crossorigin="anonymous"
        :poster="posterUrl || undefined"
        @error="handleMediaError"
        @loadeddata="handleVideoLoadedData"
        @loadedmetadata="handleVideoLoadedMetadata"
        @timeupdate="handleVideoTimeUpdate"
        @play="handleVideoPlay"
        @pause="handleVideoPause"
        @click="handleVideoClick"
        @dblclick="handleVideoDoubleClick"
        @pointermove="handlePointerMove"
        @resize="scheduleAssLayoutRefresh"
        @volumechange="handleMediaVolumeChange"
        @webkitbeginfullscreen="handleVideoWebkitBeginFullscreen"
        @webkitendfullscreen="handleVideoWebkitEndFullscreen"
      >
        <source
          v-for="source in sources"
          :key="source.src"
          :src="source.src"
          :type="source.type"
          @error="source.onerror"
        />
        <track
          v-if="nativeSubtitleTrack && subtitleEnabled"
          :key="nativeSubtitleTrack.url"
          kind="subtitles"
          :srclang="nativeSubtitleTrack.lang || 'und'"
          :label="nativeSubtitleTrack.name || 'Subtitles'"
          default
          :src="nativeSubtitleTrack.url"
          @load="handleNativeTrackLoad"
        />
        Your browser does not support the video tag.
      </video>

      <button
        v-if="active && isTouchDevice"
        type="button"
        class="absolute inset-0 z-10"
        :aria-label="controlsVisible ? 'Hide controls' : 'Show controls'"
        @click="toggleControls"
      />

      <div
        v-if="usesAssSubtitleTrack && subtitleEnabled"
        ref="assOverlayElement"
        class="pointer-events-none absolute inset-0 z-20 overflow-hidden"
        aria-hidden="true"
      />

      <div
        v-if="active"
        class="absolute inset-x-0 bottom-0 z-30 bg-linear-to-t from-black/60 via-black/22 to-transparent px-3 pb-3 pt-10 text-white transition-opacity duration-150"
        :class="controlsVisible ? 'opacity-100' : 'pointer-events-none opacity-0'"
        @click.self="toggleControls"
        @pointermove="showControls"
      >
        <div
          class="rounded-sm border border-white/8 bg-black/18 p-2.5 shadow-lg backdrop-blur-sm sm:p-3"
        >
          <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:gap-3">
            <div class="sm:min-w-0 sm:flex-1">
              <input
                :value="progress"
                type="range"
                min="0"
                max="1000"
                step="1"
                class="h-1.5 w-full accent-white opacity-70 transition-opacity hover:opacity-100"
                aria-label="Seek video"
                @input="handleSeekInput"
              />
            </div>
            <div class="flex items-center justify-between gap-2 sm:shrink-0 sm:justify-end">
              <div class="flex min-w-0 items-center gap-2">
                <UButton
                  color="neutral"
                  variant="soft"
                  size="sm"
                  class="opacity-75 transition-opacity hover:opacity-100 focus-visible:opacity-100"
                  :icon="paused ? 'i-lucide-play' : 'i-lucide-pause'"
                  :aria-label="paused ? 'Play video' : 'Pause video'"
                  @click="togglePlayback"
                />
                <div class="min-w-0 whitespace-nowrap text-xs font-medium text-white/70">
                  {{ timeLabel }}
                </div>
              </div>
              <div class="flex items-center gap-2">
                <UTooltip v-if="canSwitchToHls" text="Trouble playing? switch to HLS stream.">
                  <UButton
                    color="neutral"
                    variant="soft"
                    size="sm"
                    class="opacity-75 transition-opacity hover:opacity-100 focus-visible:opacity-100"
                    icon="i-lucide-refresh-cw"
                    aria-label="Switch to HLS stream"
                    @click="forceSwitchToHls"
                  />
                </UTooltip>
                <USelect
                  v-if="hasSubtitles"
                  v-model="subtitleSelectValue"
                  v-model:open="subtitleSelectOpen"
                  :items="subtitleSelectItems"
                  value-key="value"
                  label-key="label"
                  color="neutral"
                  variant="soft"
                  size="sm"
                  trailing-icon=""
                  :content="subtitleSelectContent"
                  :portal="subtitleMenuPortal"
                  :ui="subtitleSelectUi"
                  class="opacity-75 transition-opacity hover:opacity-100 focus-visible:opacity-100"
                  :aria-label="subtitleButtonLabel"
                >
                  <template #default>
                    <span class="sr-only">{{ subtitleButtonLabel }}</span>
                    <UIcon
                      :name="subtitleEnabled ? 'i-lucide-captions' : 'i-lucide-captions-off'"
                      class="size-4 shrink-0"
                    />
                  </template>
                </USelect>
                <UButton
                  color="neutral"
                  variant="soft"
                  size="sm"
                  class="opacity-75 transition-opacity hover:opacity-100 focus-visible:opacity-100"
                  :icon="effectiveVolume <= 0 ? 'i-lucide-volume-x' : 'i-lucide-volume-2'"
                  :aria-label="effectiveVolume <= 0 ? 'Unmute video' : 'Mute video'"
                  @click="toggleMute"
                />
                <input
                  v-if="!isTouchDevice"
                  :value="Math.round(effectiveVolume * 100)"
                  type="range"
                  min="0"
                  max="100"
                  step="1"
                  class="w-16 accent-white opacity-70 transition-opacity hover:opacity-100 sm:w-20"
                  aria-label="Video volume"
                  @input="handleVolumeInput"
                />
                <UButton
                  color="neutral"
                  variant="soft"
                  size="sm"
                  class="opacity-75 transition-opacity hover:opacity-100 focus-visible:opacity-100"
                  :icon="isFullscreen ? 'i-lucide-minimize' : 'i-lucide-maximize'"
                  :aria-label="isFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'"
                  @click="toggleFullscreen"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span v-if="subtitleLoadError" class="text-warning">{{ subtitleLoadError }}</span>
      </div>
    </div>

    <UModal
      v-model:open="showHelp"
      title="Keyboard Shortcuts"
      :portal="helpPortal"
      :ui="{ content: 'sm:max-w-3xl' }"
    >
      <template #body>
        <div class="grid gap-5 text-sm sm:grid-cols-2">
          <div class="space-y-3">
            <div class="font-semibold text-highlighted">Playback</div>
            <div class="flex items-center justify-between gap-4">
              <span>Play or pause</span>
              <span class="text-muted">Space, K</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Back 10 seconds</span>
              <span class="text-muted">J</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Forward 10 seconds</span>
              <span class="text-muted">L</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Mute</span>
              <span class="text-muted">M</span>
            </div>
          </div>
          <div class="space-y-3">
            <div class="font-semibold text-highlighted">Navigation</div>
            <div class="flex items-center justify-between gap-4">
              <span>Back 5 seconds</span>
              <span class="text-muted">Left</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Forward 5 seconds</span>
              <span class="text-muted">Right</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Go to start or end</span>
              <span class="text-muted">Home, End</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Jump through the timeline</span>
              <span class="text-muted">0-9</span>
            </div>
          </div>
          <div class="space-y-3">
            <div class="font-semibold text-highlighted">Volume & Speed</div>
            <div class="flex items-center justify-between gap-4">
              <span>Volume up or down</span>
              <span class="text-muted">Up, Down</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Faster</span>
              <span class="text-muted">'</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Slower</span>
              <span class="text-muted">;</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Step frame by frame</span>
              <span class="text-muted">, .</span>
            </div>
          </div>
          <div class="space-y-3">
            <div class="font-semibold text-highlighted">Display</div>
            <div class="flex items-center justify-between gap-4">
              <span>Fullscreen</span>
              <span class="text-muted">F</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Show or hide subtitles</span>
              <span class="text-muted">C</span>
            </div>
            <div class="flex items-center justify-between gap-4">
              <span>Open this help</span>
              <span class="text-muted">?, /</span>
            </div>
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Hls from 'hls.js';
import { disableOpacity, enableOpacity, notification, request } from '~/utils';
import { usePlayerShortcutHelp } from '~/composables/usePlayerShortcutHelp';
import { usePlayerShortcuts } from '~/composables/usePlayerShortcuts';
import { usePlayerSubtitles } from '~/composables/usePlayerSubtitles';
import {
  normalizeSubtitleTrackId,
  resolveSelectedSubtitleTrackId,
  syncTrackState,
} from '~/utils/playerTracks';
import {
  canRequestFullscreen,
  exitDocumentFullscreen,
  getFullscreenElement,
  requestElementFullscreen,
} from '~/utils/fullscreen';
import type { PlayerSubtitleTrack } from '~/types';

interface PlayerSourceElement {
  src: string;
  type?: string;
  onerror?: (event: Event) => void;
}

interface SubtitleSelectItem {
  label: string;
  value: string;
}

interface HlsPlaybackState {
  currentTime: number;
  progress: number | null;
  shouldPlay: boolean;
}

const SUBTITLE_SELECT_OFF_VALUE = '__off__';

interface PlayerProps {
  link: string;
  title?: string;
  poster?: string;
  debug?: boolean;
  directPlay?: boolean;
  m3u8?: string;
  tracks?: Array<PlayerSubtitleTrack>;
  selectedTrackId?: string | null;
  hasVideo?: boolean;
}

type HTMLVideoElementWithFrameCallback = HTMLVideoElement & {
  requestVideoFrameCallback?: (callback: VideoFrameRequestCallback) => number;
};

const props = withDefaults(defineProps<PlayerProps>(), {
  title: 'Untitled media',
  poster: '',
  debug: false,
  directPlay: false,
  m3u8: '',
  tracks: () => [],
  selectedTrackId: null,
  hasVideo: true,
});

const volume = useStorage<number>('player_volume', 1);
const muted = useStorage<boolean>('player_muted', false);
const effectiveVolume = computed(() => {
  return true === muted.value ? 0 : normalizeMediaVolume(volume.value);
});
const showShortcutHelp = usePlayerShortcutHelp();

const playerRoot = ref<HTMLElement | null>(null);
const playerContainer = ref<HTMLElement | null>(null);
const videoElement = ref<HTMLVideoElement | null>(null);
const assOverlayElement = ref<HTMLElement | null>(null);
const sources = ref<Array<PlayerSourceElement>>([]);
const active = ref(false);
const isFullscreen = ref(false);
const assLayoutVersion = ref(0);
const controlsVisible = ref(true);
const currentTime = ref(0);
const duration = ref(0);
const paused = ref(true);
const isTouchDevice = ref(false);
const usingHls = ref(false);
const destroyed = ref(false);
const subtitleSelectOpen = ref(false);
const posterUrl = ref('');
const showHelp = computed({
  get: () => showShortcutHelp.value,
  set: (value: boolean) => {
    showShortcutHelp.value = value;
  },
});
const helpPortal = computed<boolean | HTMLElement>(() => {
  if (true === isFullscreen.value) {
    return playerContainer.value || false;
  }

  return true;
});
const hasVideo = computed(() => false !== props.hasVideo);
const isAudio = computed(() => false === hasVideo.value);
const displayTitle = computed(() => props.title || 'Untitled media');
const canPlay = computed(() => Boolean(props.link));
const shouldRender = computed(() => true === active.value);
const canSwitchToHls = computed(
  () => Boolean(props.m3u8) && false === usingHls.value && true === hasVideo.value,
);
const selectedSubtitleTrackId = ref<string | null>(normalizeSubtitleTrackId(props.selectedTrackId));
const progress = computed(() => {
  if (0 === duration.value) {
    return 0;
  }

  return Math.round((currentTime.value / duration.value) * 1000);
});
const timeLabel = computed(() => {
  const currentLabel = formatDuration(Math.round(currentTime.value));
  const durationLabel = duration.value ? formatDuration(Math.round(duration.value)) : '--:--';
  return `${currentLabel} / ${durationLabel}`;
});
const subtitleButtonLabel = computed(() => {
  if (false === subtitleEnabled.value || !selectedSubtitleTrack.value) {
    return 'Subtitles off';
  }

  return selectedSubtitleTrack.value.name || selectedSubtitleTrack.value.label;
});
const subtitleMenuPortal = computed<boolean | HTMLElement>(() => {
  if (true === isFullscreen.value) {
    return playerContainer.value || false;
  }

  return playerRoot.value || false;
});
const subtitleSelectContent = {
  align: 'end' as const,
} as const;
const subtitleSelectUi = {
  base: 'size-8 min-h-8 min-w-8 justify-center rounded-md px-0',
  value: 'flex items-center justify-center px-0',
  trailing: 'hidden',
  leading: 'hidden',
  content: 'z-40 min-w-56 w-auto max-w-[min(24rem,calc(100vw-2rem))]',
} as const;
const subtitleSelectItems = computed<Array<SubtitleSelectItem>>(() => {
  return [
    { label: 'Off', value: SUBTITLE_SELECT_OFF_VALUE },
    ...props.tracks.map((track) => ({
      label: track.label,
      value: track.id,
    })),
  ];
});
const subtitleSelectValue = computed<string>({
  get: () => {
    if (false === subtitleEnabled.value) {
      return SUBTITLE_SELECT_OFF_VALUE;
    }

    return selectedSubtitleTrackId.value ?? SUBTITLE_SELECT_OFF_VALUE;
  },
  set: (value: string) => {
    if (SUBTITLE_SELECT_OFF_VALUE === value) {
      subtitleEnabled.value = false;
      return;
    }

    selectedSubtitleTrackId.value = value;
    subtitleEnabled.value = true;
  },
});
let assLayoutRefreshFrame = 0;
let controlsHideTimeout = 0;
let pendingVideoClickTimeout = 0;
let unbindMediaSession: null | (() => void) = null;
let hls: Hls | null = null;
let posterObjectUrl = '';
let hlsAttachGeneration = 0;
let directPlayFallbackNotified = false;
let hlsErrorNotified = false;
let suppressMediaErrors = false;
let directPlayFallbackInFlight = false;

const {
  subtitleLoadError,
  subtitleEnabled,
  selectedSubtitleTrack,
  nativeSubtitleTrack,
  usesAssSubtitleTrack,
  hasSubtitles,
} = usePlayerSubtitles({
  tracks: computed(() => props.tracks),
  selectedTrackId: selectedSubtitleTrackId,
  canPlay,
  shouldRender,
  assLayoutVersion,
  video: videoElement,
  overlay: assOverlayElement,
});

watch(
  [volume, muted],
  ([nextVolume]) => {
    const normalizedVolume = normalizeMediaVolume(nextVolume);
    if (normalizedVolume !== nextVolume) {
      volume.value = normalizedVolume;
      return;
    }

    applyStoredMediaState(videoElement.value);
    syncVideoState();
  },
  { immediate: true },
);

watch(
  videoElement,
  (element) => {
    applyStoredMediaState(element);
    syncVideoState();
  },
  { immediate: true },
);

watch(hasSubtitles, (enabled) => {
  if (false === enabled) {
    subtitleEnabled.value = false;
  }
});

watch(selectedSubtitleTrack, () => {
  void nextTick(() => restoreDefaultTextTrack());
});

watch(
  () => props.selectedTrackId,
  (nextTrackId) => {
    const normalizedTrackId = normalizeSubtitleTrackId(nextTrackId);

    if (normalizedTrackId === selectedSubtitleTrackId.value) {
      return;
    }

    selectedSubtitleTrackId.value = normalizedTrackId;
    subtitleEnabled.value = normalizedTrackId !== null;
  },
  { immediate: true },
);

watch(
  () => props.tracks,
  (tracks) => {
    const next = syncTrackState(
      tracks,
      selectedSubtitleTrackId.value,
      props.selectedTrackId,
      subtitleEnabled.value,
    );

    if (next.id === selectedSubtitleTrackId.value && next.enabled === subtitleEnabled.value) {
      return;
    }

    selectedSubtitleTrackId.value = next.id;
    subtitleEnabled.value = next.enabled;
  },
  { immediate: true, deep: true },
);

watch(subtitleEnabled, () => {
  void nextTick(() => restoreDefaultTextTrack());
});

watch(subtitleSelectOpen, (isOpen) => {
  if (true === destroyed.value) {
    return;
  }

  if (true === isOpen) {
    controlsVisible.value = true;
    clearControlsHideTimeout();
    return;
  }

  showControls();
});

watch(
  () => props.poster,
  () => {
    void loadPoster();
  },
  { immediate: true },
);

watch(
  [() => props.link, () => props.directPlay, videoElement],
  async () => {
    if (!videoElement.value || !props.link) {
      return;
    }

    configureSources();
    await nextTick();
    prepareVideoPlayer();
  },
  { immediate: true },
);

function formatDuration(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (hours > 0) {
    return [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
  }

  return [minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
}

function normalizeMediaVolume(nextVolume: number): number {
  if (false === Number.isFinite(nextVolume)) {
    return 1;
  }

  return Math.min(1, Math.max(0, nextVolume));
}

function setVolume(nextVolume: number) {
  const normalizedVolume = normalizeMediaVolume(nextVolume);
  volume.value = normalizedVolume;
  muted.value = normalizedVolume <= 0;
}

function changeVolume(delta: number) {
  setVolume(volume.value + delta);
}

function toggleStoredMute() {
  if (true === muted.value || effectiveVolume.value <= 0) {
    volume.value = volume.value > 0 ? normalizeMediaVolume(volume.value) : 1;
    muted.value = false;
    return;
  }

  muted.value = true;
}

async function loadPoster() {
  cleanupPosterUrl();
  posterUrl.value = '';

  if (!props.poster) {
    return;
  }

  try {
    let response: Response;
    if (true === props.poster.startsWith('/')) {
      response = await request(props.poster, { no_prefix: true, headers: { Accept: 'image/*' } });
    } else {
      response = await fetch(props.poster);
    }

    if (response.ok) {
      posterObjectUrl = URL.createObjectURL(await response.blob());
      posterUrl.value = posterObjectUrl;
      return;
    }
  } catch {}

  posterUrl.value = props.poster;
}

function cleanupPosterUrl() {
  if (!posterObjectUrl) {
    return;
  }

  URL.revokeObjectURL(posterObjectUrl);
  posterObjectUrl = '';
}

function configureSources() {
  hlsAttachGeneration += 1;
  sources.value = [];
  destroyHls();
  usingHls.value = false;
  directPlayFallbackNotified = false;
  hlsErrorNotified = false;

  if (true === props.directPlay) {
    sources.value.push({
      src: props.link,
      onerror: (event: Event) => void src_error(event),
    });
    return;
  }

  if (true === Hls.isSupported()) {
    attach_hls(props.link);
    return;
  }

  sources.value.push({
    src: props.link,
    type: 'application/x-mpegURL',
    onerror: (event: Event) => void src_error(event),
  });
}

function activatePlayer() {
  active.value = true;
  void nextTick(async () => {
    applyStoredMediaState(videoElement.value);
    try {
      await videoElement.value?.play();
    } catch {}
    syncVideoState();
    showControls();
  });
}

function handleVideoLoadedData() {
  syncVideoState();
}

function handleVideoLoadedMetadata() {
  syncVideoState();
  showControls();
  scheduleAssLayoutRefresh();
  if (videoElement.value) {
    updateMediaSessionPosition(videoElement.value);
  }
}

function handleVideoTimeUpdate() {
  syncVideoState();
  if (videoElement.value) {
    updateMediaSessionPosition(videoElement.value);
  }
}

function handleVideoPlay() {
  syncVideoState();
  showControls();
}

function handleVideoPause() {
  syncVideoState();
  clearControlsHideTimeout();
  controlsVisible.value = true;
}

function handleVideoClick() {
  if (true === isTouchDevice.value) {
    return;
  }

  if (false === controlsVisible.value) {
    return;
  }

  clearPendingVideoClickTimeout();
  pendingVideoClickTimeout = window.setTimeout(() => {
    pendingVideoClickTimeout = 0;
    clearControlsHideTimeout();
    controlsVisible.value = false;
  }, 180);
}

function handleVideoDoubleClick() {
  clearPendingVideoClickTimeout();
  void toggleFullscreen();
}

function handleVideoWebkitBeginFullscreen() {
  scheduleAssLayoutRefresh();
}

function handleVideoWebkitEndFullscreen() {
  scheduleAssLayoutRefresh();
}

function handleMediaError(event: Event) {
  void src_error(event);
}

function canAutoSwitchToHls(): boolean {
  return true === props.directPlay && Boolean(props.m3u8) && false === usingHls.value;
}

function switchToHls(playbackState: HlsPlaybackState, notify: boolean): void {
  if (false === canAutoSwitchToHls() || true === directPlayFallbackInFlight) {
    return;
  }

  directPlayFallbackInFlight = true;

  if (true === notify && false === directPlayFallbackNotified) {
    directPlayFallbackNotified = true;
    notification('warning', 'Playback', 'Direct playback failed. Switching to transcoded stream.');
  }

  attach_hls(props.m3u8 || '', playbackState);
}

function handlePosterError() {
  cleanupPosterUrl();
  posterUrl.value = '';
}

function handleMediaVolumeChange(event: Event) {
  const target = event.target as HTMLMediaElement | null;
  if (!target || 'number' !== typeof target.volume) {
    return;
  }

  if (target.muted !== muted.value) {
    muted.value = target.muted;
  }

  const normalizedVolume = normalizeMediaVolume(target.volume);
  if (Math.abs(volume.value - normalizedVolume) > 0.001) {
    volume.value = normalizedVolume;
  }

  syncVideoState();
  updateMediaSessionPosition(target);
}

function handlePointerMove(event: PointerEvent) {
  if (!playerContainer.value || true === isTouchDevice.value) {
    return;
  }

  const rect = playerContainer.value.getBoundingClientRect();
  const y = event.clientY - rect.top;
  const bottomZone = Math.min(Math.max(rect.height * 0.28, 96), 180);

  if (y >= rect.height - bottomZone) {
    showControls();
  }
}

function handleSeekInput(event: Event) {
  const target = event.target as HTMLInputElement | null;
  if (!target || !videoElement.value || 0 === duration.value) {
    return;
  }

  const sliderValue = Number(target.value);
  if (false === Number.isFinite(sliderValue)) {
    return;
  }

  videoElement.value.currentTime = (sliderValue / 1000) * duration.value;
  syncVideoState();
  showControls();
}

function handleVolumeInput(event: Event) {
  const target = event.target as HTMLInputElement | null;
  if (!target || !videoElement.value) {
    return;
  }

  setVolume(Number(target.value) / 100);
  applyStoredMediaState(videoElement.value);
  syncVideoState();
  showControls();
}

async function togglePlayback() {
  if (!videoElement.value) {
    return;
  }

  try {
    if (true === videoElement.value.paused) {
      await videoElement.value.play();
      syncVideoState();
      showControls();
      return;
    }

    videoElement.value.pause();
    syncVideoState();
  } catch {}
}

function toggleMute() {
  toggleStoredMute();
  applyStoredMediaState(videoElement.value);
  syncVideoState();
  showControls();
}

function toggleSubtitleEnabled() {
  if (true === subtitleEnabled.value) {
    subtitleEnabled.value = false;
    void nextTick(() => restoreDefaultTextTrack());
    return;
  }

  if (null === selectedSubtitleTrackId.value) {
    selectedSubtitleTrackId.value = resolveSelectedSubtitleTrackId(
      props.tracks,
      props.selectedTrackId,
    );
  }

  subtitleEnabled.value = selectedSubtitleTrackId.value !== null;
  void nextTick(() => restoreDefaultTextTrack());
}

function applyStoredMediaState(element: HTMLMediaElement | null) {
  if (!element) {
    return;
  }

  const normalizedVolume = normalizeMediaVolume(volume.value);
  if (Math.abs(element.volume - normalizedVolume) > 0.001) {
    element.volume = normalizedVolume;
  }

  if (element.muted !== muted.value) {
    element.muted = muted.value;
  }
}

function syncVideoState() {
  if (true === destroyed.value) {
    return;
  }

  const video = videoElement.value;
  if (!video) {
    currentTime.value = 0;
    duration.value = 0;
    paused.value = true;
    return;
  }

  const nextDuration = Number.isFinite(video.duration) && video.duration > 0 ? video.duration : 0;
  const nextTime =
    Number.isFinite(video.currentTime) && video.currentTime > 0 ? video.currentTime : 0;

  duration.value = nextDuration;
  currentTime.value = nextTime;
  paused.value = video.paused;
}

function scheduleAssLayoutRefresh() {
  if (true === destroyed.value || false === usesAssSubtitleTrack.value) {
    return;
  }

  if (assLayoutRefreshFrame) {
    window.cancelAnimationFrame(assLayoutRefreshFrame);
  }

  void nextTick(() => {
    assLayoutRefreshFrame = window.requestAnimationFrame(() => {
      assLayoutRefreshFrame = 0;
      assLayoutVersion.value += 1;
    });
  });
}

function showControls() {
  if (true === destroyed.value) {
    return;
  }

  controlsVisible.value = true;
  clearControlsHideTimeout();

  if (true === subtitleSelectOpen.value || videoElement.value?.paused) {
    return;
  }

  controlsHideTimeout = window.setTimeout(() => {
    if (true === subtitleSelectOpen.value) {
      controlsVisible.value = true;
      return;
    }

    controlsVisible.value = false;
  }, 2500);
}

function handleNativeTrackLoad() {
  if (true === destroyed.value) {
    return;
  }

  void restoreDefaultTextTrack();
}

function toggleControls() {
  if (false === controlsVisible.value) {
    showControls();
    return;
  }

  if (videoElement.value?.paused) {
    return;
  }

  clearControlsHideTimeout();
  controlsVisible.value = false;
}

function clearControlsHideTimeout() {
  if (controlsHideTimeout) {
    window.clearTimeout(controlsHideTimeout);
    controlsHideTimeout = 0;
  }
}

function clearPendingVideoClickTimeout() {
  if (pendingVideoClickTimeout) {
    window.clearTimeout(pendingVideoClickTimeout);
    pendingVideoClickTimeout = 0;
  }
}

function syncFullscreenState() {
  if (true === destroyed.value) {
    return;
  }

  const fullscreenElement = getFullscreenElement();
  isFullscreen.value = Boolean(
    fullscreenElement && playerContainer.value && fullscreenElement === playerContainer.value,
  );
  scheduleAssLayoutRefresh();
}

async function toggleFullscreen() {
  if (!playerContainer.value || false === canRequestFullscreen(playerContainer.value)) {
    return;
  }

  try {
    if (true === isFullscreen.value) {
      await exitDocumentFullscreen();
    } else {
      await requestElementFullscreen(playerContainer.value);
    }
  } catch {}
}

function bindMediaSessionListeners(element: HTMLVideoElement) {
  const onLoadedMetadata = (event: Event) => updateMediaSessionPosition(event.currentTarget);
  const onTimeUpdate = (event: Event) => updateMediaSessionPosition(event.currentTarget);
  const onRateChange = (event: Event) => updateMediaSessionPosition(event.currentTarget);
  const onSeeked = (event: Event) => updateMediaSessionPosition(event.currentTarget);
  const onPause = async (event: Event) => {
    const target = (event.currentTarget as HTMLVideoElement) ?? null;
    if (!target || true === destroyed.value) {
      return;
    }

    const dataUrl = await captureFrame(target);
    if (dataUrl) {
      cleanupPosterUrl();
      posterUrl.value = dataUrl;
      applyMediaSessionMetadata();
    }
  };

  element.addEventListener('loadedmetadata', onLoadedMetadata);
  element.addEventListener('timeupdate', onTimeUpdate);
  element.addEventListener('ratechange', onRateChange);
  element.addEventListener('seeked', onSeeked);
  element.addEventListener('pause', onPause);

  return () => {
    element.removeEventListener('loadedmetadata', onLoadedMetadata);
    element.removeEventListener('timeupdate', onTimeUpdate);
    element.removeEventListener('ratechange', onRateChange);
    element.removeEventListener('seeked', onSeeked);
    element.removeEventListener('pause', onPause);
  };
}

function updateMediaSessionPosition(target: EventTarget | null) {
  if (false === 'mediaSession' in navigator) {
    return;
  }

  const element = (target as HTMLVideoElement) ?? null;
  if (!element || true === destroyed.value) {
    return;
  }

  const mediaDuration = element.duration;
  if (false === Number.isFinite(mediaDuration) || mediaDuration <= 0) {
    return;
  }

  try {
    navigator.mediaSession.setPositionState({
      duration: mediaDuration,
      playbackRate: element.playbackRate,
      position: element.currentTime,
    });
  } catch {}
}

async function captureFrame(element: HTMLVideoElement): Promise<string> {
  if (
    !element ||
    true === destroyed.value ||
    0 === element.videoWidth ||
    0 === element.videoHeight
  ) {
    return '';
  }

  try {
    const canvas = document.createElement('canvas');
    canvas.width = element.videoWidth;
    canvas.height = element.videoHeight;
    const context = canvas.getContext('2d');
    if (!context) {
      return '';
    }

    context.drawImage(element, 0, 0, element.videoWidth, element.videoHeight);
    return canvas.toDataURL('image/jpeg', 0.86);
  } catch {
    return '';
  }
}

async function captureFirstFramePoster(element: HTMLVideoElement): Promise<void> {
  if (!element || true === destroyed.value || posterUrl.value) {
    return;
  }

  if (0 === element.videoWidth || 0 === element.videoHeight) {
    return;
  }

  const dataUrl = await captureFrame(element);
  if (!dataUrl) {
    return;
  }

  cleanupPosterUrl();
  posterUrl.value = dataUrl;
  applyMediaSessionMetadata();
}

async function restoreDefaultTextTrack() {
  if (true === destroyed.value) {
    return;
  }

  const element = videoElement.value;
  if (!element) {
    return;
  }

  try {
    const tracksList = element.textTracks;
    if (!tracksList || 0 === tracksList.length) {
      return;
    }

    for (let i = 0; i < tracksList.length; i += 1) {
      const track = tracksList[i] as TextTrack | undefined;
      if (track) {
        track.mode = 'disabled';
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 50));

    if (element !== videoElement.value) {
      return;
    }

    if (false === subtitleEnabled.value || null === nativeSubtitleTrack.value) {
      return;
    }

    const activeTracksList = element.textTracks;
    for (let i = 0; i < activeTracksList.length; i += 1) {
      const track = activeTracksList[i] as TextTrack | undefined;
      if (!track) {
        continue;
      }
      track.mode = 0 === i ? 'showing' : 'disabled';
    }
  } catch (error) {
    console.warn('Failed to restore subtitle track state', error);
  }
}

function applyMediaSessionMetadata() {
  if (true === destroyed.value || false === 'mediaSession' in navigator) {
    return;
  }

  const metadata: MediaMetadataInit = { title: displayTitle.value };
  if (posterUrl.value) {
    metadata.artwork = [{ src: posterUrl.value, sizes: '1920x1080', type: 'image/jpeg' }];
  }

  try {
    navigator.mediaSession.metadata = new MediaMetadata(metadata);
  } catch {}
}

function prepareVideoPlayer() {
  if (!videoElement.value || true === destroyed.value) {
    return;
  }

  applyMediaSessionMetadata();
  applyStoredMediaState(videoElement.value);
  void restoreDefaultTextTrack();

  if (true === hasVideo.value) {
    const video = videoElement.value as HTMLVideoElementWithFrameCallback;
    if ('function' === typeof video.requestVideoFrameCallback) {
      video.requestVideoFrameCallback(() => void captureFirstFramePoster(video));
    } else {
      video.addEventListener('loadeddata', () => void captureFirstFramePoster(video), {
        once: true,
      });
    }
  }
}

function captureHlsPlaybackState(
  element: HTMLMediaElement | null,
  shouldPlay: boolean,
): HlsPlaybackState {
  if (!element) {
    return {
      currentTime: 0,
      progress: null,
      shouldPlay,
    };
  }

  const nextCurrentTime =
    Number.isFinite(element.currentTime) && element.currentTime > 0 ? element.currentTime : 0;
  const nextProgress =
    Number.isFinite(element.duration) && element.duration > 0
      ? Math.min(1, Math.max(0, element.currentTime / element.duration))
      : null;

  return {
    currentTime: nextCurrentTime,
    progress: nextProgress,
    shouldPlay,
  };
}

async function restoreHlsPlaybackState(
  element: HTMLMediaElement,
  playbackState: HlsPlaybackState,
): Promise<void> {
  let resumeTime = playbackState.currentTime;

  if (
    playbackState.progress !== null &&
    Number.isFinite(element.duration) &&
    element.duration > 0
  ) {
    resumeTime = Math.min(element.duration, Math.max(0, element.duration * playbackState.progress));
  }

  if (resumeTime > 0) {
    try {
      element.currentTime = resumeTime;
    } catch {}
  }

  if (true === playbackState.shouldPlay) {
    try {
      await element.play();
    } catch {}
  }

  syncVideoState();
  showControls();
}

function isPlayerDestroyed(): boolean {
  return true === destroyed.value;
}

async function src_error(event: Event) {
  if (true === suppressMediaErrors) {
    return;
  }

  if (hls) {
    return;
  }

  await nextTick();
  if (true === isPlayerDestroyed()) {
    return;
  }

  if (videoElement.value?.paused && false === active.value) {
    return;
  }

  if (true === canAutoSwitchToHls()) {
    console.warn('Source failed to load, attempting HLS fallback via hls.js...', event);
    switchToHls(captureHlsPlaybackState(videoElement.value, true), true);
    return;
  }

  if (false === props.directPlay && true === Hls.isSupported()) {
    attach_hls(props.link);
  }
}

function destroyHls() {
  hlsAttachGeneration += 1;

  if (hls) {
    hls.destroy();
    hls = null;
  }

  usingHls.value = false;
}

function resetVideoElementForHls() {
  const element = videoElement.value;
  if (!element) {
    return;
  }

  try {
    element.pause();
  } catch {}

  try {
    element.removeAttribute('src');
    element.querySelectorAll('source').forEach((source) => source.removeAttribute('src'));
    element.load();
  } catch (error) {
    console.warn('Failed to reset video element before HLS attach', error);
  }
}

function attach_hls(link: string, playbackState: HlsPlaybackState | null = null) {
  const element = videoElement.value;
  if (!element || !link) {
    directPlayFallbackInFlight = false;
    return;
  }

  suppressMediaErrors = true;
  sources.value = [];
  destroyHls();
  resetVideoElementForHls();

  const attachGeneration = hlsAttachGeneration;

  try {
    hls = new Hls({
      debug: props.debug,
      enableWorker: true,
      lowLatencyMode: true,
      backBufferLength: 120,
      fragLoadingTimeOut: 200000,
    });

    hls.on(Hls.Events.ERROR, (_event, data) => {
      if (attachGeneration !== hlsAttachGeneration || true === isPlayerDestroyed()) {
        return;
      }

      console.warn(data);

      if (false === data.fatal) {
        return;
      }

      if (true === canAutoSwitchToHls()) {
        switchToHls(captureHlsPlaybackState(videoElement.value, true), true);
        return;
      }

      if (false === hlsErrorNotified) {
        hlsErrorNotified = true;
        notification(
          'warning',
          'Playback',
          'The compatibility stream hit an error during playback.',
        );
      }
    });

    hls.on(Hls.Events.MANIFEST_PARSED, () => applyMediaSessionMetadata());
    hls.on(Hls.Events.MANIFEST_PARSED, async () => {
      if (attachGeneration !== hlsAttachGeneration || true === isPlayerDestroyed()) {
        return;
      }

      await new Promise((resolve) => setTimeout(resolve, 100));

      if (attachGeneration !== hlsAttachGeneration || true === isPlayerDestroyed()) {
        return;
      }

      await restoreDefaultTextTrack();
    });

    if (playbackState) {
      const onLoadedMetadata = () => void restoreHlsPlaybackState(element, playbackState);
      element.addEventListener('loadedmetadata', onLoadedMetadata, { once: true });
    }

    hls.on(Hls.Events.MEDIA_ATTACHED, async () => {
      if (attachGeneration !== hlsAttachGeneration || true === isPlayerDestroyed()) {
        return;
      }

      await new Promise((resolve) => setTimeout(resolve, 200));

      if (attachGeneration !== hlsAttachGeneration || true === isPlayerDestroyed()) {
        return;
      }

      await restoreDefaultTextTrack();

      if (!playbackState && true === active.value) {
        try {
          await element.play();
        } catch {}
      }
    });

    hls.on(Hls.Events.LEVEL_LOADED, () => {
      if (attachGeneration !== hlsAttachGeneration || true === isPlayerDestroyed()) {
        return;
      }

      if (videoElement.value) {
        const video = videoElement.value as HTMLVideoElementWithFrameCallback;
        if ('function' === typeof video.requestVideoFrameCallback) {
          video.requestVideoFrameCallback(() => void captureFirstFramePoster(video));
        } else {
          video.addEventListener('loadeddata', () => void captureFirstFramePoster(video), {
            once: true,
          });
        }
      }
    });

    hls.loadSource(link);
    hls.attachMedia(element);
    usingHls.value = true;
    directPlayFallbackInFlight = false;
  } finally {
    suppressMediaErrors = false;
  }
}

function forceSwitchToHls() {
  if (false === canAutoSwitchToHls()) {
    return;
  }

  if (false === hasVideo.value) {
    notification('warning', 'Playback', 'Cannot switch to HLS: stream has no video track.');
    return;
  }

  switchToHls(captureHlsPlaybackState(videoElement.value, true), false);
}

usePlayerShortcuts({
  enabled: computed(() => true === active.value && Boolean(videoElement.value)),
  media: videoElement,
  video: videoElement,
  adjustVolume: (delta) => {
    changeVolume(delta);
    applyStoredMediaState(videoElement.value);
    syncVideoState();
    showControls();
  },
  canToggleSubs: hasSubtitles,
  helpOpen: showShortcutHelp,
  toggleSubtitles: toggleSubtitleEnabled,
  toggleFullscreen,
  toggleMute,
});

onMounted(() => {
  disableOpacity();
  isTouchDevice.value = window.matchMedia('(pointer: coarse)').matches;
  document.addEventListener('fullscreenchange', syncFullscreenState);
  document.addEventListener('webkitfullscreenchange', syncFullscreenState as EventListener);
  window.addEventListener('resize', scheduleAssLayoutRefresh);
  window.addEventListener('orientationchange', scheduleAssLayoutRefresh);
  syncFullscreenState();

  if (videoElement.value) {
    unbindMediaSession = bindMediaSessionListeners(videoElement.value);
  }
});

onBeforeUnmount(() => {
  destroyed.value = true;
  enableOpacity();
  document.removeEventListener('fullscreenchange', syncFullscreenState);
  document.removeEventListener('webkitfullscreenchange', syncFullscreenState as EventListener);
  window.removeEventListener('resize', scheduleAssLayoutRefresh);
  window.removeEventListener('orientationchange', scheduleAssLayoutRefresh);

  if (assLayoutRefreshFrame) {
    window.cancelAnimationFrame(assLayoutRefreshFrame);
  }

  clearControlsHideTimeout();
  clearPendingVideoClickTimeout();
  destroyHls();

  if (unbindMediaSession) {
    unbindMediaSession();
    unbindMediaSession = null;
  }

  if (videoElement.value) {
    try {
      videoElement.value.pause();
      videoElement.value
        .querySelectorAll('source')
        .forEach((source) => source.removeAttribute('src'));
      videoElement.value.load();
    } catch (error) {
      console.error(error);
    }
  }

  cleanupPosterUrl();
});
</script>

<style scoped>
.dash-video-element::-webkit-media-controls {
  display: none;
}

.dash-video-element::-webkit-media-controls-fullscreen-button {
  display: none;
}
</style>
