<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <p class="min-w-0 flex-1 text-sm text-toned"></p>

      <Popover placement="bottom-end" trigger="click" :z-index="13000">
        <template #trigger>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            trailing-icon="i-lucide-chevron-down"
          >
            <span class="hidden sm:inline">Copy</span>
          </UButton>
        </template>

        <template #content="{ hide }">
          <div class="w-52 space-y-1 p-1">
            <button
              type="button"
              class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
              @click="
                copyText(props.item.id);
                hide();
              "
            >
              <UIcon name="i-lucide-hash" class="size-4 text-toned" />
              <span>Copy ID</span>
            </button>
            <button
              type="button"
              class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
              @click="
                copyText(fullJson);
                hide();
              "
            >
              <UIcon name="i-lucide-copy" class="size-4 text-toned" />
              <span>Copy item</span>
            </button>
            <button
              type="button"
              class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
              @click="
                copyText(displayedData);
                hide();
              "
            >
              <UIcon name="i-lucide-database" class="size-4 text-toned" />
              <span>Copy data</span>
            </button>
            <button
              type="button"
              class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
              @click="
                copyText(displayedOptions);
                hide();
              "
            >
              <UIcon name="i-lucide-settings-2" class="size-4 text-toned" />
              <span>Copy options</span>
            </button>
          </div>
        </template>
      </Popover>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label="Event" :value="item.event" icon="i-lucide-tag" value-wrap />
      <StatCard label="State" :value="item.state" :icon="stateIcon" :color="stateColor" />
      <StatCard
        label="Created"
        :value="moment(item.created_at).fromNow()"
        icon="i-lucide-clock"
        :tooltip="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)"
      />
      <StatCard
        label="Envelope ID"
        :value="shortId(item.id)"
        icon="i-lucide-fingerprint"
        :tooltip="item.id"
      />
    </div>

    <section v-if="Object.keys(item.data).length > 0" class="space-y-3">
      <button
        type="button"
        class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
        @click="showData = !showData"
      >
        <div class="flex items-center gap-3">
          <span
            class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
          >
            <UIcon name="i-lucide-database" class="size-4" />
          </span>
          <p class="text-base font-semibold text-highlighted">Attached Data</p>
        </div>
        <div class="flex items-center gap-2" @click.stop>
          <UButton
            color="neutral"
            :variant="wrapData ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-wrap-text"
            @click="wrapData = !wrapData"
          >
            <span class="hidden sm:inline">Wrap</span>
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            @click="copyText(displayedData)"
          >
            Copy
          </UButton>
          <UIcon
            name="i-lucide-chevron-right"
            :class="['size-4 text-toned transition-transform', showData ? 'rotate-90' : '']"
          />
        </div>
      </button>

      <template v-if="showData">
        <UInput
          v-model="dataQuery"
          type="search"
          icon="i-lucide-filter"
          size="sm"
          placeholder="Filter attached data"
          class="w-full"
        />
        <UAlert
          v-if="dataQuery && filteredDataLines.length < 1"
          color="warning"
          variant="soft"
          icon="i-lucide-filter"
          title="No matching lines"
        />
        <code
          v-if="!dataQuery || filteredDataLines.length > 0"
          class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto wrap-break-word"
          :class="wrapData ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >{{ displayedData }}</code
        >
      </template>
    </section>

    <section v-if="Object.keys(item.options).length > 0" class="space-y-3">
      <button
        type="button"
        class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
        @click="showOptions = !showOptions"
      >
        <div class="flex items-center gap-3">
          <span
            class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
          >
            <UIcon name="i-lucide-settings-2" class="size-4" />
          </span>
          <p class="text-base font-semibold text-highlighted">Attached Options</p>
        </div>
        <div class="flex items-center gap-2" @click.stop>
          <UButton
            color="neutral"
            :variant="wrapOptions ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-wrap-text"
            @click="wrapOptions = !wrapOptions"
          >
            <span class="hidden sm:inline">Wrap</span>
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            @click="copyText(displayedOptions)"
          >
            Copy
          </UButton>
          <UIcon
            name="i-lucide-chevron-right"
            :class="['size-4 text-toned transition-transform', showOptions ? 'rotate-90' : '']"
          />
        </div>
      </button>

      <template v-if="showOptions">
        <UInput
          v-model="optionsQuery"
          type="search"
          icon="i-lucide-filter"
          size="sm"
          placeholder="Filter attached options"
          class="w-full"
        />
        <UAlert
          v-if="optionsQuery && filteredOptionsLines.length < 1"
          color="warning"
          variant="soft"
          icon="i-lucide-filter"
          title="No matching lines"
        />
        <code
          v-if="!optionsQuery || filteredOptionsLines.length > 0"
          class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto wrap-break-word"
          :class="wrapOptions ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >{{ displayedOptions }}</code
        >
      </template>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import Popover from '~/components/Popover.vue';
import StatCard from '~/components/StatCard.vue';
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
