<template>
  <div class="space-y-4">
    <div class="flex justify-end">
      <UDropdownMenu
        :items="copyMenuItems"
        :content="{ side: 'bottom', align: 'end' }"
        :modal="false"
        :ui="{ content: 'z-[13000]' }"
      >
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-copy"
          trailing-icon="i-lucide-chevron-down"
          aria-label="Copy transport item"
          title="Copy transport item"
        >
          <span class="hidden sm:inline">Copy</span>
        </UButton>
      </UDropdownMenu>
    </div>

    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-y border-default py-4 sm:grid-cols-4">
      <div class="min-w-0">
        <dt class="text-xs text-muted">Event</dt>
        <dd class="mt-1 wrap-anywhere text-sm font-medium text-highlighted">{{ item.event }}</dd>
      </div>
      <div class="min-w-0">
        <dt class="text-xs text-muted">State</dt>
        <dd class="mt-1 text-sm font-medium text-highlighted">
          <UBadge :color="stateColor" variant="soft">
            <UIcon :name="stateIcon" class="size-3.5" />
            <span class="capitalize">{{ item.state }}</span>
          </UBadge>
        </dd>
      </div>
      <div class="min-w-0">
        <dt class="text-xs text-muted">Created</dt>
        <dd class="mt-1 text-sm font-medium text-highlighted">
          <UTooltip :text="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)">
            <time :datetime="item.created_at">{{ moment(item.created_at).fromNow() }}</time>
          </UTooltip>
        </dd>
      </div>
      <div class="min-w-0">
        <dt class="text-xs text-muted">Envelope ID</dt>
        <dd class="mt-1 text-sm font-medium text-highlighted">
          <UTooltip :text="item.id">
            <span class="font-mono text-xs">{{ shortId(item.id) }}</span>
          </UTooltip>
        </dd>
      </div>
    </dl>

    <section v-if="0 < Object.keys(item.data).length" class="space-y-3">
      <div class="flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-3 text-left focus-visible:outline-primary"
          :aria-expanded="showData"
          aria-controls="transport-attached-data"
          @click="showData = !showData"
        >
          <span class="ws-detail-icon">
            <UIcon name="i-lucide-database" class="size-4" />
          </span>
          <span class="text-base font-semibold text-highlighted">Attached data</span>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 shrink-0 text-toned transition-transform',
              showData ? 'rotate-90' : '',
            ]"
          />
        </button>
        <div class="flex items-center gap-2">
          <UButton
            color="neutral"
            :variant="wrapData ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-wrap-text"
            :aria-pressed="wrapData"
            aria-label="Wrap attached data"
            @click="wrapData = !wrapData"
          >
            Wrap
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            aria-label="Copy attached data"
            @click="copyText(displayedData)"
          >
            Copy
          </UButton>
        </div>
      </div>

      <div v-show="showData" id="transport-attached-data" class="space-y-3">
        <UInput
          v-model="dataQuery"
          type="search"
          icon="i-lucide-filter"
          size="sm"
          placeholder="Filter attached data"
          aria-label="Filter attached data"
          class="w-full"
        />
        <UEmpty
          v-if="dataQuery && 1 > filteredDataLines.length"
          size="sm"
          variant="naked"
          icon="i-lucide-filter"
          title="No matching lines"
        />
        <code
          v-if="!dataQuery || 0 < filteredDataLines.length"
          class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto wrap-break-word"
          :class="wrapData ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >{{ displayedData }}</code
        >
      </div>
    </section>

    <section v-if="0 < Object.keys(item.options).length" class="space-y-3">
      <div class="flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-3 text-left focus-visible:outline-primary"
          :aria-expanded="showOptions"
          aria-controls="transport-attached-options"
          @click="showOptions = !showOptions"
        >
          <span class="ws-detail-icon">
            <UIcon name="i-lucide-settings-2" class="size-4" />
          </span>
          <span class="text-base font-semibold text-highlighted">Attached options</span>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 shrink-0 text-toned transition-transform',
              showOptions ? 'rotate-90' : '',
            ]"
          />
        </button>
        <div class="flex items-center gap-2">
          <UButton
            color="neutral"
            :variant="wrapOptions ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-wrap-text"
            :aria-pressed="wrapOptions"
            aria-label="Wrap attached options"
            @click="wrapOptions = !wrapOptions"
          >
            Wrap
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            aria-label="Copy attached options"
            @click="copyText(displayedOptions)"
          >
            Copy
          </UButton>
        </div>
      </div>

      <div v-show="showOptions" id="transport-attached-options" class="space-y-3">
        <UInput
          v-model="optionsQuery"
          type="search"
          icon="i-lucide-filter"
          size="sm"
          placeholder="Filter attached options"
          aria-label="Filter attached options"
          class="w-full"
        />
        <UEmpty
          v-if="optionsQuery && 1 > filteredOptionsLines.length"
          size="sm"
          variant="naked"
          icon="i-lucide-filter"
          title="No matching lines"
        />
        <code
          v-if="!optionsQuery || 0 < filteredOptionsLines.length"
          class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto wrap-break-word"
          :class="wrapOptions ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >{{ displayedOptions }}</code
        >
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { UBadge, UButton, UDropdownMenu, UEmpty, UIcon, UInput, UTooltip } from '#components';
import type { DropdownMenuItem } from '@nuxt/ui';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import type { TransportQueueDetail } from '~/types';
import { copyText, TOOLTIP_DATE_FORMAT } from '~/utils';
import { filterLogTextLines } from '~/utils/logs';

const props = defineProps<{ item: TransportQueueDetail }>();

const showData = useStorage<boolean>('transport_queue_show_data', true);
const showOptions = useStorage<boolean>('transport_queue_show_options', true);
const wrapData = useStorage<boolean>('transport_queue_wrap_data', false);
const wrapOptions = useStorage<boolean>('transport_queue_wrap_options', false);
const dataQuery = ref<string>('');
const optionsQuery = ref<string>('');

const fullJson = computed<string>(() => JSON.stringify(props.item, null, 2));
const dataJson = computed<string>(() => JSON.stringify(props.item.data, null, 2));
const optionsJson = computed<string>(() => JSON.stringify(props.item.options, null, 2));
const filteredDataLines = computed<Array<string>>(() =>
  filterLogTextLines(dataJson.value, dataQuery.value),
);
const filteredOptionsLines = computed<Array<string>>(() =>
  filterLogTextLines(optionsJson.value, optionsQuery.value),
);
const displayedData = computed<string>(() =>
  dataQuery.value ? filteredDataLines.value.join('\n') : dataJson.value,
);
const displayedOptions = computed<string>(() =>
  optionsQuery.value ? filteredOptionsLines.value.join('\n') : optionsJson.value,
);

const copyMenuItems: Array<DropdownMenuItem> = [
  {
    label: 'Copy ID',
    icon: 'i-lucide-hash',
    onSelect: () => {
      copyText(props.item.id);
    },
  },
  {
    label: 'Copy item',
    icon: 'i-lucide-copy',
    onSelect: () => {
      copyText(fullJson.value);
    },
  },
  {
    label: 'Copy data',
    icon: 'i-lucide-database',
    onSelect: () => {
      copyText(displayedData.value);
    },
  },
  {
    label: 'Copy options',
    icon: 'i-lucide-settings-2',
    onSelect: () => {
      copyText(displayedOptions.value);
    },
  },
];

const stateIcon = computed<string>(() => {
  if ('processing' === props.item.state) {
    return 'i-lucide-loader-circle';
  }
  if ('failed' === props.item.state) {
    return 'i-lucide-circle-x';
  }
  return 'i-lucide-clock-3';
});

const stateColor = computed<'neutral' | 'warning' | 'error'>(() => {
  if ('processing' === props.item.state) {
    return 'warning';
  }
  if ('failed' === props.item.state) {
    return 'error';
  }
  return 'neutral';
});

const shortId = (id: string): string => `${id.slice(0, 8)}...${id.slice(-4)}`;
</script>
