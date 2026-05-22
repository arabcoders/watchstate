<template>
  <div class="space-y-6 p-4 sm:p-5">
    <div
      v-if="item.content_title || isLoaded"
      class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
    >
      <div class="min-w-0 flex-1">
        <p v-if="item.content_title" class="wrap-break-word text-sm text-toned">
          {{ item.content_title }}
        </p>
      </div>

      <div v-if="isLoaded" class="flex shrink-0 flex-wrap items-center justify-end gap-2">
        <UTooltip v-if="isPlaying" text="Return to playback settings">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-arrow-left"
            @click="closeStream"
          >
            Back
          </UButton>
        </UTooltip>

        <UTooltip text="Toggle watch state">
          <UButton
            color="neutral"
            :variant="item.watched ? 'soft' : 'outline'"
            size="sm"
            :icon="item.watched ? 'i-lucide-eye-off' : 'i-lucide-eye'"
            @click="toggleWatched"
          >
            {{ item.watched ? 'Unwatched' : 'Watched' }}
          </UButton>
        </UTooltip>
      </div>
    </div>

    <template v-if="!isPlaying">
      <UAlert
        v-if="isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading data. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="isLoaded && (item.files?.length ?? 0) < 1"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Warning"
        description="No video URLs were found."
      />
    </template>

    <Player
      v-if="isPlaying"
      :title="displayName"
      :link="playUrl"
      :directPlay="playbackDirect"
      :tracks="playerTracks"
      :selectedTrackId="selectedPlayerTrackId"
      :m3u8="m3u8Url"
      :debug="session_debug"
      :poster="posterUrl"
      :hasVideo="hasVideo"
    />

    <template v-else-if="isLoaded && (item.files?.length ?? 0) > 0">
      <div class="space-y-5">
        <div class="text-base font-semibold text-highlighted">Select playback settings.</div>

        <UFormField label="Source file" name="play_source">
          <USelect
            v-model="selectedPath"
            :items="fileItems"
            value-key="value"
            placeholder="Select source file..."
            icon="i-lucide-file-video"
            class="w-full"
            @update:model-value="(value: string | number | undefined) => selectFile(String(value))"
          />
        </UFormField>

        <UFormField v-if="audioItems.length > 1" label="Audio" name="play_audio">
          <USelect
            v-model="selectedAudio"
            :items="audioItems"
            value-key="value"
            placeholder="Select audio stream..."
            icon="i-lucide-file-audio"
            class="w-full"
          />
        </UFormField>

        <UFormField
          v-if="subtitleItems.length > 0"
          label="Subtitle"
          name="play_subtitle"
          description="Choose subtitle."
        >
          <USelect
            v-model="selectedSubtitleValue"
            :items="subtitleItems"
            value-key="value"
            placeholder="Select subtitle..."
            icon="i-lucide-captions"
            class="w-full"
          />
        </UFormField>

        <UFormField
          v-if="showSubtitleDeliveryMode"
          label="Subtitle delivery"
          name="subtitle_delivery_mode"
          description="Choose whether subtitles stay selectable in the player or are added into the video."
        >
          <USelect
            v-model="subtitle_delivery_mode"
            :items="subtitleDeliveryItems"
            value-key="value"
            class="w-full"
          />
        </UFormField>

        <UAlert
          v-if="selectedSubtitleRequiresTranscode"
          color="warning"
          variant="soft"
          icon="i-lucide-image"
          title="This subtitle needs compatibility playback"
          description="This subtitle format needs to be added into the video before playback starts."
        />

        <UAlert
          v-if="selectedSubtitleRequiresTranscode"
          color="info"
          variant="soft"
          icon="i-lucide-refresh-cw"
          title="Switched to compatibility playback"
          description="Your subtitle choice needs a more compatible playback mode, so the player will prepare a version that works reliably in the browser."
        />

        <UAlert
          v-if="selectedNonDefaultAudio"
          color="info"
          variant="soft"
          icon="i-lucide-audio-lines"
          title="Switched to compatibility playback"
          description="Choosing a different audio track uses the browser-compatible playback mode so the selected track plays correctly."
        />

        <template v-if="showAdvanced">
          <UFormField
            label="Video transcoding codec"
            name="video_codec"
            description="We do not pre-check codec support, so hardware options may still fail on hosts that do not support them."
          >
            <USelect
              v-model="video_codec"
              :items="codecItems"
              value-key="value"
              placeholder="Select codec..."
              icon="i-lucide-monitor-cog"
              class="w-full"
              @update:model-value="
                (value: string | number | undefined) => updateHwAccel(String(value))
              "
            />
          </UFormField>

          <UFormField
            v-if="'h264_vaapi' === video_codec"
            label="VAAPI device"
            name="vaapi_device"
            description="Used only when VAAPI transcoding is selected."
          >
            <USelect
              v-model="vaapi_device"
              :items="deviceItems"
              value-key="value"
              placeholder="Select device..."
              icon="i-lucide-cpu"
              class="w-full"
            />
          </UFormField>

          <div class="rounded-md border border-default bg-elevated/30 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">
                  Include debug information in response headers
                </div>
                <p class="mt-1 text-sm text-toned">
                  Useful for reviewing ffmpeg options and the active transcode path.
                </p>
              </div>

              <USwitch id="debug" v-model="session_debug" color="neutral" />
            </div>
          </div>
        </template>
        <div v-if="selectedPath" class="flex flex-row justify-end gap-2">
          <UButton
            color="neutral"
            :variant="showAdvanced ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-settings"
            @click="showAdvanced = !showAdvanced"
          >
            Advanced settings
          </UButton>

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-play"
            :loading="isGenerating"
            :disabled="isGenerating"
            @click="generateToken"
          >
            Play
          </UButton>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Player from '~/components/Player.vue';
import { useDialog } from '~/composables/useDialog';
import type { PlayerSubtitleDeliveryMode, PlayerSubtitleTrack } from '~/types';
import {
  api_error_message,
  basename,
  encodePath,
  notification,
  parse_api_response,
  request,
  ucFirst,
} from '~/utils';

type SelectItem = {
  label: string;
  value?: string | number;
  type?: 'label' | 'item';
};

type PlayStream = {
  index: number;
  codec_type: 'video' | 'audio' | 'subtitle';
  codec_name: string;
  tags?: {
    title?: string;
    language?: string;
  };
  disposition?: {
    default?: number;
  };
};

type PlayMediaFile = {
  path: string;
  source: Array<string>;
  subtitles: Array<string>;
  ffprobe?: {
    streams?: Array<PlayStream>;
  };
};

type PlayItem = {
  id: string | number;
  type: string;
  title: string;
  watched: boolean;
  content_title?: string;
  files?: Array<PlayMediaFile>;
  hardware?: {
    codecs?: Array<{ codec: string; name: string; hwaccel: boolean }>;
    devices?: Array<string>;
  };
  year?: number;
  season?: number;
  episode?: number;
};

type PlayNameInfo = {
  title?: string;
  year?: number;
  type?: string;
  season?: number;
  episode?: number;
};

type SubtitleOption = {
  id: string;
  kind: 'internal' | 'external';
  name: string;
  label: string;
  lang: string;
  codec: string;
  isText: boolean;
  renderer: 'native' | 'assjs';
  default: boolean;
  index?: number;
  path?: string;
};

const SUBTITLE_NONE_VALUE = '__none__';

const props = defineProps<{
  id: string | number;
}>();

const emit = defineEmits<{
  (e: 'watched-change', value: boolean): void;
}>();

const dialog = useDialog();
const historyId = computed(() => String(props.id));

const item = ref<PlayItem>({
  id: historyId.value,
  type: 'movie',
  title: '',
  watched: false,
});
const playNameInfo = ref<PlayNameInfo>({
  title: '',
  type: 'movie',
});
const isLoading = ref(false);
const isLoaded = ref(false);
const isGenerating = ref(false);
const showAdvanced = useStorage('play_showAdvanced', false);
const video_codec = useStorage('play_vcodec', 'libx264');
const vaapi_device = useStorage('play_vaapi_device', '');
const session_debug = useStorage('play_debug', false);
const subtitle_delivery_mode = useStorage<PlayerSubtitleDeliveryMode>(
  'play_subtitle_delivery_mode',
  'soft',
);

const selectedPath = ref('');
const selectedAudio = ref<number | undefined>(undefined);
const selectedSubtitleId = ref<string | null>(null);
const hwaccel = ref(false);
const selectedItem = ref<PlayMediaFile | null>(null);
const playbackToken = ref('');
const playbackDirect = ref(false);
const sessionVersion = ref(0);

const subtitleDeliveryItems: Array<SelectItem> = [
  { label: 'Soft subtitles', value: 'soft' },
  { label: 'Burn into video', value: 'burned' },
];

const formatPlayName = (value: PlayNameInfo): string => {
  const title = value.title || '??';
  const year = value.year ?? '0000';
  const type = value.type || 'movie';

  if (['show', 'movie'].includes(type)) {
    return `${title} (${year})`;
  }

  const season = String(value.season ?? 0).padStart(2, '0');
  const episode = String(value.episode ?? 0).padStart(3, '0');

  return `${title} (${year}) - ${season}x${episode}`;
};

const displayName = computed((): string => {
  if (playNameInfo.value.title) {
    return formatPlayName(playNameInfo.value);
  }

  return historyId.value;
});

const posterUrl = computed(() => `/v1/api/history/${historyId.value}/images/poster`);

const fileItems = computed(() => {
  const files = (item.value.files ?? []) as Array<{ path: string; source: Array<string> }>;

  return files.map((file) => {
    return [
      { label: `In: ${file.source.join(', ')}`, type: 'label' as const },
      { label: basename(file.path), value: file.path, type: 'item' as const },
    ];
  });
});

const audioItems = computed<Array<SelectItem>>(() =>
  filterStreams('audio').map((stream) => ({
    value: stream.index,
    label: `${stream.index} - ${String(stream.codec_name).toUpperCase()}${stream.tags?.title ? ` - ${ucFirst(String(stream.tags.title))}` : ''}${stream.tags?.language ? ` - (${String(stream.tags.language).toUpperCase()})` : ''}`,
  })),
);

const subtitleOptions = computed<Array<SubtitleOption>>(() => {
  if (!selectedItem.value) {
    return [];
  }

  const options: Array<SubtitleOption> = [];

  for (const stream of filterStreams('subtitle')) {
    options.push(toInternalSubtitleOption(stream));
  }

  for (const [index, subtitlePath] of (selectedItem.value.subtitles ?? []).entries()) {
    options.push(toExternalSubtitleOption(subtitlePath, index));
  }

  return options;
});

const subtitleItems = computed<Array<Array<SelectItem>>>(() => {
  const groups: Array<Array<SelectItem>> = [
    [{ label: 'None', value: SUBTITLE_NONE_VALUE, type: 'item' }],
  ];

  const internals = subtitleOptions.value.filter((item) => 'internal' === item.kind);
  const externals = subtitleOptions.value.filter((item) => 'external' === item.kind);

  if (internals.length > 0) {
    groups.push([
      { label: 'Internal Subtitles', type: 'label' },
      ...internals.map((item) => ({ label: item.label, value: item.id, type: 'item' as const })),
    ]);
  }

  if (externals.length > 0) {
    groups.push([
      { label: 'External Subtitles', type: 'label' },
      ...externals.map((item) => ({ label: item.label, value: item.id, type: 'item' as const })),
    ]);
  }

  return groups;
});

const selectedSubtitleValue = computed<string>({
  get: () => selectedSubtitleId.value ?? SUBTITLE_NONE_VALUE,
  set: (value) => {
    selectedSubtitleId.value = SUBTITLE_NONE_VALUE === value ? null : value;
  },
});

const selectedSubtitle = computed<SubtitleOption | null>(() => {
  if (!selectedSubtitleId.value) {
    return null;
  }

  return subtitleOptions.value.find((item) => item.id === selectedSubtitleId.value) || null;
});

const selectedSubtitleRequiresTranscode = computed<boolean>(() => {
  return selectedSubtitle.value !== null && false === selectedSubtitle.value.isText;
});

const selectedDefaultAudio = computed<number | undefined>(() => {
  const audioStreams = filterStreams('audio');
  const defaultAudio = audioStreams.find(
    (stream) => 1 === Number(stream.disposition?.default ?? 0),
  );

  return defaultAudio?.index ?? audioStreams[0]?.index;
});

const selectedNonDefaultAudio = computed<boolean>(() => {
  return selectedAudio.value !== undefined && selectedDefaultAudio.value !== selectedAudio.value;
});

const effectiveDirectPlay = computed<boolean>(() => {
  return (
    false === selectedSubtitleRequiresTranscode.value && false === selectedNonDefaultAudio.value
  );
});

const showSubtitleDeliveryMode = computed<boolean>(() => {
  return true === Boolean(selectedSubtitle.value?.isText) && false === effectiveDirectPlay.value;
});

const canRenderSoftSubtitles = computed<boolean>(() => {
  if (true === effectiveDirectPlay.value) {
    return true;
  }

  if (null === selectedSubtitle.value) {
    return true;
  }

  if (false === selectedSubtitle.value.isText) {
    return false;
  }

  return 'soft' === subtitle_delivery_mode.value;
});

const selectedPlayerTrackId = computed<string | null>(() => {
  if (false === canRenderSoftSubtitles.value || null === selectedSubtitle.value) {
    return null;
  }

  if (false === selectedSubtitle.value.isText) {
    return null;
  }

  return selectedSubtitle.value.id;
});

const playerTracks = computed<Array<PlayerSubtitleTrack>>(() => {
  if (!playbackToken.value || false === canRenderSoftSubtitles.value) {
    return [];
  }

  return subtitleOptions.value
    .filter((item) => true === item.isText)
    .map((item) => toPlayerSubtitleTrack(item, playbackToken.value))
    .filter((item): item is PlayerSubtitleTrack => item !== null);
});

const codecItems = computed<Array<SelectItem>>(() =>
  (item.value.hardware?.codecs ?? []).map((codec) => ({ label: codec.name, value: codec.codec })),
);

const deviceItems = computed<Array<SelectItem>>(() =>
  (item.value.hardware?.devices ?? []).map((device) => ({
    label: basename(device),
    value: device,
  })),
);

const m3u8Url = computed(() =>
  playbackToken.value ? `/v1/api/player/playlist/${playbackToken.value}/master.m3u8` : '',
);

const streamUrl = computed(() => {
  if (!playbackToken.value || !selectedPath.value) {
    return '';
  }

  const encodedPath = encodePath(selectedPath.value)?.replace(/^\/+/, '');
  return `/v1/api/player/stream/${playbackToken.value}/${encodedPath ?? ''}`;
});

const playUrl = computed(() => (true === playbackDirect.value ? streamUrl.value : m3u8Url.value));

const isPlaying = computed<boolean>(() => {
  return playbackToken.value.length > 0 && selectedItem.value !== null;
});

const hasVideo = computed<boolean>(() => {
  return filterStreams('video').length > 0;
});

const formatStreamLabel = (title?: string, language?: string, codec?: string): string => {
  const name = title && title.length > 0 ? title : (language ?? '').toUpperCase();
  return [name, language && title ? language : '', codec ?? ''].filter(Boolean).join(' - ');
};

const isTextSubtitleCodec = (codec: string): boolean => {
  return ['ass', 'ssa', 'subrip', 'srt', 'vtt', 'webvtt'].includes(codec.toLowerCase());
};

const parseSubtitleLanguage = (path: string): string => {
  const match = path.match(/\.([a-z]{2,3})\.[^.]+$/i);
  return match?.[1]?.toLowerCase() ?? 'und';
};

const toInternalSubtitleOption = (stream: PlayStream): SubtitleOption => {
  const codec = String(stream.codec_name || '').toLowerCase();
  const isText = isTextSubtitleCodec(codec);
  const language = String(stream.tags?.language || 'und').toLowerCase();
  const name = stream.tags?.title || stream.tags?.language || 'Subtitle';

  return {
    id: `i:${stream.index}`,
    kind: 'internal',
    name,
    label: formatStreamLabel(stream.tags?.title, stream.tags?.language, stream.codec_name),
    lang: language,
    codec,
    isText,
    renderer: 'ass' === codec || 'ssa' === codec ? 'assjs' : 'native',
    default: 1 === Number(stream.disposition?.default ?? 0),
    index: stream.index,
  };
};

const toExternalSubtitleOption = (path: string, index: number): SubtitleOption => {
  const codec = String(path.split('.').pop() || '').toLowerCase();
  const language = parseSubtitleLanguage(path);
  const name = basename(path);

  return {
    id: `x:${index}`,
    kind: 'external',
    name,
    label: formatStreamLabel(name, language, codec.toUpperCase()),
    lang: language,
    codec,
    isText: isTextSubtitleCodec(codec),
    renderer: 'ass' === codec || 'ssa' === codec ? 'assjs' : 'native',
    default: false,
    path,
  };
};

const toPlayerSubtitleTrack = (
  subtitle: SubtitleOption,
  token: string,
): PlayerSubtitleTrack | null => {
  if (false === subtitle.isText) {
    return null;
  }

  const delivery_format = 'assjs' === subtitle.renderer ? 'ass' : 'vtt';
  const source = subtitle.id.startsWith('x:') ? 'x' : 'i';
  const refId = subtitle.id.split(':')[1];
  if (!refId) {
    return null;
  }

  return {
    id: subtitle.id,
    source: 'x' === source ? 'external' : 'internal',
    kind: subtitle.kind,
    label: subtitle.label,
    name: subtitle.name,
    lang: subtitle.lang,
    renderer: subtitle.renderer,
    delivery_format,
    url: `/v1/api/player/subtitle/${token}/${source}${refId}.${delivery_format}`,
    isText: true,
    isBitmap: false,
  };
};

const filterStreams = (
  type?: PlayStream['codec_type'] | Array<PlayStream['codec_type']>,
): Array<PlayStream> => {
  const streams = selectedItem.value?.ffprobe?.streams ?? [];
  if (!type) {
    return streams;
  }

  const types = Array.isArray(type) ? type : [type];
  return streams.filter((stream) => types.includes(stream.codec_type));
};

const resolveDefaultSubtitleId = (file: PlayMediaFile): string | null => {
  const streams = file.ffprobe?.streams ?? [];
  const subtitles = streams
    .filter((stream) => 'subtitle' === stream.codec_type)
    .map((stream) => toInternalSubtitleOption(stream))
    .filter((stream) => true === stream.default && true === stream.isText);

  return subtitles[0]?.id ?? null;
};

const selectFile = (path: string, preserveSubtitle: boolean = false): void => {
  selectedPath.value = path;
  selectedItem.value = (item.value.files ?? []).find((file) => file.path === path) ?? null;

  if (!selectedItem.value) {
    selectedAudio.value = undefined;
    selectedSubtitleId.value = null;
    return;
  }

  const audioStreams = (selectedItem.value.ffprobe?.streams ?? []).filter(
    (stream) => 'audio' === stream.codec_type,
  );
  const defaultAudio = audioStreams.find(
    (stream) => 1 === Number(stream.disposition?.default ?? 0),
  );
  selectedAudio.value = defaultAudio?.index ?? audioStreams[0]?.index;

  if (false === preserveSubtitle) {
    selectedSubtitleId.value = resolveDefaultSubtitleId(selectedItem.value);
  }
};

const updateHwAccel = (codec: string): void => {
  const codecInfo = item.value.hardware?.codecs?.find((entry) => entry.codec === codec);
  if (!codecInfo) {
    hwaccel.value = false;
    return;
  }

  hwaccel.value = Boolean(codecInfo.hwaccel);
  video_codec.value = codec;
};

const makeConfig = (): Record<string, unknown> => {
  const config: Record<string, unknown> = {
    browser_subtitles: true,
    debug: Boolean(session_debug.value),
  };

  if (selectedAudio.value !== undefined) {
    config.audio = selectedAudio.value;
  }

  if (true === hwaccel.value) {
    config.hwaccel = true;
    config.video_codec = video_codec.value;
    if (vaapi_device.value && 'h264_vaapi' === video_codec.value) {
      config.vaapi_device = vaapi_device.value;
    }
  } else {
    config.hwaccel = false;
    config.video_codec = 'libx264';
  }

  if (true === effectiveDirectPlay.value) {
    config.direct_play = true;
  }

  if (!selectedSubtitle.value) {
    return config;
  }

  if (true === selectedSubtitle.value.isText) {
    const wantsSoft = true === effectiveDirectPlay.value || 'soft' === subtitle_delivery_mode.value;
    if (true === wantsSoft) {
      config.subtitle_mode = 'soft';
      return config;
    }
  }

  config.subtitle_mode = 'burned';
  if ('internal' === selectedSubtitle.value.kind && selectedSubtitle.value.index !== undefined) {
    config.subtitle = selectedSubtitle.value.index;
  }

  if ('external' === selectedSubtitle.value.kind && selectedSubtitle.value.path) {
    config.external = selectedSubtitle.value.path;
  }

  return config;
};

const clearPlaybackState = (): void => {
  playbackToken.value = '';
  playbackDirect.value = false;
};

const resetSession = (): void => {
  sessionVersion.value += 1;
  item.value = {
    id: historyId.value,
    type: 'movie',
    title: '',
    watched: false,
  };
  playNameInfo.value = {
    title: '',
    type: 'movie',
  };
  isLoaded.value = false;
  isLoading.value = false;
  isGenerating.value = false;
  selectedPath.value = '';
  selectedAudio.value = undefined;
  selectedSubtitleId.value = null;
  selectedItem.value = null;
  clearPlaybackState();
};

const loadContent = async (): Promise<void> => {
  const currentId = historyId.value;
  const currentSession = sessionVersion.value;

  isLoading.value = true;
  try {
    const response = await request(`/history/${currentId}?files=true`);
    const json = await parse_api_response<PlayItem>(response);
    if (currentId !== historyId.value || currentSession !== sessionVersion.value) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `Failed to load item. ${api_error_message(json, response)}`);
      return;
    }

    item.value = json;
    playNameInfo.value = {
      title: json.title,
      year: json.year,
      type: json.type,
      season: json.season,
      episode: json.episode,
    };

    if (1 === (json.files?.length ?? 0) && json.files?.[0]) {
      selectFile(json.files[0].path);
    }

    isLoaded.value = true;
    updateHwAccel(video_codec.value);
  } catch (error: unknown) {
    console.error(error);
    notification('error', 'Error', `Failed to load item. ${String(error)}`);
  } finally {
    if (currentId === historyId.value && currentSession === sessionVersion.value) {
      isLoading.value = false;
    }
  }
};

const generateToken = async (): Promise<void> => {
  if (!selectedPath.value) {
    notification('warning', 'Playback', 'Select a file first.');
    return;
  }

  const currentId = historyId.value;
  const currentSession = sessionVersion.value;

  isGenerating.value = true;
  try {
    const response = await request(`/system/sign/${currentId}`, {
      method: 'POST',
      body: JSON.stringify({
        path: selectedPath.value,
        config: makeConfig(),
      }),
    });

    const json = await parse_api_response<{ token: string }>(response);
    if (currentId !== historyId.value || currentSession !== sessionVersion.value) {
      return;
    }

    if ('error' in json) {
      notification(
        'error',
        'Token generation',
        `Failed to generate token. ${api_error_message(json, response)}`,
      );
      return;
    }

    playbackToken.value = json.token;
    playbackDirect.value = effectiveDirectPlay.value;
  } catch (error: unknown) {
    console.error(error);
    notification('error', 'Error', `Failed to generate token. ${String(error)}`);
  } finally {
    if (currentId === historyId.value && currentSession === sessionVersion.value) {
      isGenerating.value = false;
    }
  }
};

const closeStream = (): void => {
  clearPlaybackState();
};

const toggleWatched = async (): Promise<void> => {
  const { status } = await dialog.confirmDialog({
    title: 'Confirm',
    message: `Mark '${displayName.value}' as ${item.value.watched ? 'unplayed' : 'played'}?`,
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/history/${item.value.id}/watch`, {
      method: item.value.watched ? 'DELETE' : 'POST',
    });

    const json = await parse_api_response<PlayItem>(response);
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    item.value.watched = json.watched;
    emit('watched-change', item.value.watched);
    notification(
      'success',
      '',
      `Marked '${displayName.value}' as ${item.value.watched ? 'played' : 'unplayed'}`,
    );
  } catch (error: unknown) {
    notification('error', 'Error', `Request error. ${String(error)}`);
  }
};

watch(selectedSubtitleRequiresTranscode, (requiresTranscode) => {
  if (true === requiresTranscode) {
    subtitle_delivery_mode.value = 'burned';
  }
});

watch(
  () => props.id,
  async (nextId, previousId) => {
    if (nextId === previousId) {
      return;
    }

    resetSession();
    await loadContent();
  },
);

onMounted(async () => {
  resetSession();
  await loadContent();
});

onBeforeUnmount(() => {
  sessionVersion.value += 1;
});
</script>
