<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-sm text-toned"></p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <UInput
          v-if="toggleFilter"
          id="filter"
          v-model="query"
          type="search"
          icon="i-lucide-filter"
          size="sm"
          placeholder="Filter"
        />

        <UTooltip text="Filter event logs.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-filter"
            :disabled="!item?.logs || item.logs.length < 1"
            @click="toggleFilter = !toggleFilter"
          >
            <span class="hidden sm:inline">Filter</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Reset event.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            :icon="0 !== item.status ? 'i-lucide-rotate-ccw' : 'i-lucide-power'"
            :disabled="1 === item.status"
            @click="resetEvent(0 === item.status ? 4 : 0)"
          >
            <span class="hidden sm:inline">Reset</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Delete event.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            :disabled="1 === item.status"
            @click="deleteItem"
          >
            <span class="hidden sm:inline">Delete</span>
          </UButton>
        </UTooltip>

        <UTooltip :text="wrapLines ? 'Disable wrap lines' : 'Enable wrap lines'">
          <UButton
            color="neutral"
            :variant="wrapLines ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-wrap-text"
            @click="wrapLines = !wrapLines"
          >
            <span class="hidden sm:inline">Wrap</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Copy event.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            :disabled="isLoading"
            @click="copyItem"
          >
            <span class="hidden sm:inline">Copy</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Reload event data.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            @click="loadContent"
          >
            <span class="hidden sm:inline">Reload</span>
          </UButton>
        </UTooltip>
      </div>
    </div>

    <UAlert
      v-if="isLoading && !item?.id"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-if="!isLoading || item?.id">
      <UCard class="border border-default/70 shadow-sm">
        <div class="flex flex-wrap items-center gap-2 text-sm text-default">
          <span>Event</span>
          <UBadge color="info" variant="soft">{{ item.event }}</UBadge>

          <template v-if="item.reference">
            <span>with reference</span>
            <UBadge color="neutral" variant="outline">{{ item.reference }}</UBadge>
          </template>

          <span>was created</span>
          <UTooltip :text="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)">
            <UBadge color="warning" variant="soft">
              {{ moment(item.created_at).fromNow() }}
            </UBadge>
          </UTooltip>

          <span>and last updated</span>
          <template v-if="!item.updated_at">
            <UBadge color="neutral" variant="outline">not started</UBadge>
          </template>
          <template v-else>
            <UTooltip :text="moment(item.updated_at).format(TOOLTIP_DATE_FORMAT)">
              <UBadge color="error" variant="soft">
                {{ moment(item.updated_at).fromNow() }}
              </UBadge>
            </UTooltip>
          </template>

          <span>with status of</span>
          <UBadge :color="getEventStatusColor(item.status)">
            <span class="inline-flex items-center gap-1">
              <UIcon
                :name="getEventStatusIcon(item.status)"
                :class="getEventStatusIconClass(item.status)"
              />
              <span>{{ item.status }}: {{ item.status_name }}</span>
            </span>
          </UBadge>
        </div>
      </UCard>

      <UCard
        v-if="item?.event_data && Object.keys(item.event_data).length > 0"
        class="border border-default/70 shadow-sm"
        :ui="cardUi"
      >
        <template #header>
          <button
            type="button"
            class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
            @click="toggleData = !toggleData"
          >
            <UIcon
              :name="toggleData ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4 text-toned"
            />
            <span>{{ !toggleData ? 'Show' : 'Hide' }} attached data</span>
          </button>
        </template>

        <div v-if="toggleData" class="relative">
          <code
            class="ws-terminal ws-terminal-panel ws-terminal-panel-lg"
            :class="wrapLines ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >
            {{ JSON.stringify(item.event_data, null, 2) }}
          </code>
          <UTooltip text="Copy event data">
            <UButton
              color="neutral"
              variant="soft"
              size="sm"
              icon="i-lucide-copy"
              class="absolute right-3 top-3"
              @click="copyEventData"
            />
          </UTooltip>
        </div>
      </UCard>

      <UCard
        v-if="item?.logs && item.logs.length > 0"
        class="border border-default/70 shadow-sm"
        :ui="cardUi"
      >
        <template #header>
          <button
            type="button"
            class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
            @click="toggleLogs = !toggleLogs"
          >
            <UIcon
              :name="toggleLogs ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4 text-toned"
            />
            <span>{{ !toggleLogs ? 'Show' : 'Hide' }} event logs</span>
          </button>
        </template>

        <div v-if="toggleLogs" class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-toned">
            <span>
              {{ filteredRows.length }} of {{ logRows.length }} line{{
                1 === logRows.length ? '' : 's'
              }}
              {{ query ? 'visible' : 'loaded' }}
            </span>

            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              icon="i-lucide-copy"
              @click="copyLogs"
            >
              Copy logs
            </UButton>
          </div>

          <div class="max-h-[60vh] overflow-auto border border-default bg-elevated/30 shadow-sm">
            <template v-if="structuredRows.length > 0">
              <article
                v-for="(entry, index) in structuredRows"
                :key="entry.key"
                :class="structuredRowClass(index)"
              >
                <div
                  :class="[
                    'flex min-w-0 flex-1 items-start gap-[0.65rem] px-3 py-[0.65rem] leading-[1.6]',
                    wrapLines ? 'w-full' : 'w-max min-w-full',
                  ]"
                >
                  <p :class="structuredLineClass">
                    <span
                      class="inline-flex max-w-full flex-wrap items-center gap-x-2 gap-y-1 align-middle"
                    >
                      <UTooltip :text="lineTitle(entry.log)">
                        <span class="inline cursor-pointer text-[11px] font-semibold text-toned">
                          {{ timestampLabel(entry.log) }}
                        </span>
                      </UTooltip>

                      <LogDetailsChip
                        :item="entry.log"
                        :open-details="openLogDetails"
                        :open-event="openEvent"
                      />

                      <span
                        :class="logLevelBadgeClass(entry.level)"
                        @click="openLogDetails(entry.log)"
                      >
                        <UIcon :name="LOG_LEVEL_ICON[entry.level]" class="size-3" />
                        {{ entry.level }}
                      </span>

                      <span
                        v-if="entry.log.logger"
                        :title="entry.log.logger"
                        class="inline-block max-w-[46vw] truncate align-middle text-[11px] font-semibold text-toned sm:max-w-104"
                      >
                        [{{ entry.log.logger }}]
                      </span>
                    </span>

                    <span class="ml-2">{{ logMessage(entry.log) }}</span>

                    <span v-if="entry.log.exception_message" class="ml-1 text-error/90">
                      : {{ entry.log.exception_message }}
                    </span>
                  </p>
                </div>
              </article>
            </template>

            <div
              v-else
              class="flex min-h-40 flex-col items-center justify-center gap-3 px-6 py-8 text-center"
            >
              <UIcon
                :name="query ? 'i-lucide-filter-x' : 'i-lucide-circle-off'"
                class="size-6 text-toned"
              />

              <div class="space-y-1">
                <p class="text-sm font-medium text-default">
                  {{ query ? 'No logs match this query' : 'No log lines available' }}
                </p>

                <p class="text-sm text-toned">
                  {{
                    query
                      ? 'Adjust the filter to inspect more event output.'
                      : 'This event has no visible log lines.'
                  }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </UCard>

      <UCard
        v-if="item?.options && Object.keys(item.options).length > 0"
        class="border border-default/70 shadow-sm"
        :ui="cardUi"
      >
        <template #header>
          <button
            type="button"
            class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
            @click="toggleOptions = !toggleOptions"
          >
            <UIcon
              :name="toggleOptions ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4 text-toned"
            />
            <span>{{ !toggleOptions ? 'Show' : 'Hide' }} attached options</span>
          </button>
        </template>

        <div v-if="toggleOptions" class="relative">
          <code
            class="ws-terminal ws-terminal-panel ws-terminal-panel-lg"
            :class="wrapLines ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >
            {{ JSON.stringify(item.options, null, 2) }}
          </code>
          <UTooltip text="Copy options">
            <UButton
              color="neutral"
              variant="soft"
              size="sm"
              icon="i-lucide-copy"
              class="absolute right-3 top-3"
              @click="copyOptions"
            />
          </UTooltip>
        </div>
      </UCard>
    </template>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :id="selectedEventId"
          @delete="(eventItem) => emit('delete', eventItem)"
        />
      </template>
    </UModal>

    <LogDetailsModal :log="selectedLog" v-model:open="detailsOpen" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { createError, useHead } from '#app';
import moment from 'moment';
import { useStorage } from '@vueuse/core';
import EventView from '~/components/EventView.vue';
import LogDetailsChip from '~/components/LogDetailsChip.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import { useDialog } from '~/composables/useDialog';
import type { EventsItem, GenericError, LogEntry } from '~/types';
import {
  copyText,
  getEventStatusClass,
  makeEventName,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import {
  getLogLevel,
  logMessageText,
  logSearchText,
  logTimestampLabel,
  logTimestampTitle,
  parseLogLines,
} from '~/utils/logs';

const emit = defineEmits<{
  closeOverlay: [];
  delete: [EventsItem];
  deleted: [EventsItem];
}>();

const props = defineProps<{ id: string }>();

const query = ref<string>('');
const item = ref<EventsItem>({} as EventsItem);
const isLoading = ref<boolean>(true);
const toggleFilter = ref<boolean>(false);
const timer = ref<ReturnType<typeof setInterval> | null>(null);
const toggleLogs = useStorage<boolean>('events_toggle_logs', true);
const toggleData = useStorage<boolean>('events_toggle_data', true);
const toggleOptions = useStorage<boolean>('events_toggle_options', true);
const wrapLines = useStorage<boolean>('logs_wrap_lines', false);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<LogEntry | null>(null);

type LogLevel = 'debug' | 'info' | 'warning' | 'error';
type StructuredLogRow = {
  key: string;
  log: LogEntry;
  level: LogLevel;
};

const LOG_LEVEL_ICON: Record<LogLevel, string> = {
  debug: 'i-lucide-terminal',
  info: 'i-lucide-info',
  warning: 'i-lucide-triangle-alert',
  error: 'i-lucide-circle-x',
};

const cardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const eventViewModalUi = {
  content: 'max-w-5xl',
  body: 'p-4 sm:p-5',
};

const eventViewOpen = computed({
  get: () => null !== selectedEventId.value,
  set: (value: boolean) => {
    if (false === value) {
      selectedEventId.value = null;
    }
  },
});

const eventViewTitle = computed(() =>
  null === selectedEventId.value ? 'Event' : `#${makeEventName(selectedEventId.value)}`,
);

const detailsOpen = computed({
  get: () => null !== selectedLog.value,
  set: (value: boolean) => {
    if (false === value) {
      selectedLog.value = null;
    }
  },
});

const dialog = useDialog();

const logRows = computed<Array<LogEntry>>(() => parseLogLines(item.value.logs ?? []));

const filteredRows = computed<Array<LogEntry>>(() => {
  if (!query.value) {
    return logRows.value;
  }

  const queryValue = query.value.toLowerCase();

  return logRows.value.filter((logLine) => logSearchText(logLine).includes(queryValue));
});

const structuredRows = computed<Array<StructuredLogRow>>(() => {
  return filteredRows.value.map((log, index) => ({
    key: `${log.id}:${index}`,
    log,
    level: getLogLevel(log.level),
  }));
});

const structuredLineClass = computed<Array<string>>(() => [
  'flex-1',
  wrapLines.value ? 'min-w-0 whitespace-pre-wrap wrap-break-word' : 'min-w-max whitespace-pre',
  'text-default',
]);

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

watch(
  () => props.id,
  (value, oldValue) => {
    if (!value || value === oldValue) {
      return;
    }

    if (timer.value) {
      clearInterval(timer.value);
      timer.value = null;
    }

    selectedLog.value = null;
    selectedEventId.value = null;
    query.value = '';
    void loadContent();
  },
);

onMounted(async () => {
  if (!props.id) {
    throw createError({
      statusCode: 404,
      message: 'Error ID not provided.',
    });
  }

  await loadContent();
});

onUnmounted(() => {
  if (timer.value) {
    clearInterval(timer.value);
    timer.value = null;
  }
});

const getEventStatusColor = (status: number) => {
  const value = getEventStatusClass(status);

  if (value.includes('danger')) {
    return 'error';
  }

  if (value.includes('warning')) {
    return 'warning';
  }

  if (value.includes('success')) {
    return 'success';
  }

  if (value.includes('info')) {
    return 'info';
  }

  return 'neutral';
};

const getEventStatusIcon = (status: number): string => {
  switch (status) {
    case 0:
      return 'i-lucide-clock-3';
    case 1:
      return 'i-lucide-loader-circle';
    case 2:
      return 'i-lucide-circle-check';
    case 3:
      return 'i-lucide-circle-x';
    case 4:
      return 'i-lucide-ban';
    default:
      return 'i-lucide-circle-help';
  }
};

const getEventStatusIconClass = (status: number): string => {
  return 1 === status ? 'size-3.5 animate-spin' : 'size-3.5';
};

const loadContent = async (): Promise<void> => {
  try {
    isLoading.value = true;
    const response = await request(`/system/events/${props.id}`);
    const json = await parse_api_response<EventsItem>(response);

    if ('error' in json) {
      const errorJson = json as GenericError;
      notification(
        'error',
        'Error',
        `Errors viewItem request error. ${errorJson.error.code}: ${errorJson.error.message}`,
      );
      return;
    }

    if (200 !== response.status) {
      notification('error', 'Error', 'Errors viewItem request error.');
      return;
    }

    if (1 === json.status) {
      if (!timer.value) {
        timer.value = setInterval(() => {
          void loadContent();
        }, 5000);
      }
    } else if (timer.value) {
      clearInterval(timer.value);
      timer.value = null;
    }

    item.value = json;

    useHead({ title: `Event: ${json.id}` });
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    console.error(error);
    notification('crit', 'Error', `Errors viewItem Request failure. ${message}`);
  } finally {
    isLoading.value = false;
  }
};

const deleteItem = async (): Promise<void> => emit('delete', item.value);

const resetEvent = async (status: number = 0): Promise<void> => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Reset '${makeEventName(item.value.id)}'?`,
    confirmColor: 'warning',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/system/events/${item.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        status,
        reset_logs: true,
      }),
    });

    const json = await parse_api_response<EventsItem>(response);

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Events view patch Request error. ${json.error.code}: ${json.error.message}`,
      );
      return;
    }

    item.value = json;
  } catch (error: unknown) {
    console.error(error);
    notification(
      'crit',
      'Error',
      `Events view patch Request failure. ${error instanceof Error ? error.message : String(error)}`,
    );
  }
};

const openEvent = (id: string): void => {
  if (id === item.value.id) {
    return;
  }

  selectedEventId.value = id;
};

const openLogDetails = (log: LogEntry): void => {
  selectedLog.value = log;
};

const lineTitle = (logLine: LogEntry): string =>
  logTimestampTitle(logLine.datetime ?? logLine.date);

const timestampLabel = (logLine: LogEntry): string =>
  logTimestampLabel(logLine.datetime ?? logLine.date);

const logMessage = (logLine: LogEntry): string => logMessageText(logLine);

const structuredRowClass = (index: number): Array<string> => {
  const classes = [
    'flex min-w-0 border-b border-default/40 bg-transparent transition-colors duration-150 last:border-b-0 hover:bg-elevated/70',
  ];

  if (index % 2 === 1) {
    classes.push('bg-elevated/40');
  }

  return classes;
};

const logLevelBadgeClass = (level: LogLevel): Array<string> => [
  'inline-flex cursor-pointer items-center gap-1.5 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide',
  'debug' === level ? 'bg-muted/40 text-muted' : '',
  'info' === level ? 'bg-info/10 text-info' : '',
  'warning' === level ? 'bg-warning/10 text-warning' : '',
  'error' === level ? 'bg-error/10 text-error' : '',
];

const copyItem = (): void => {
  copyText(JSON.stringify(item.value, null, 2));
};

const copyEventData = (): void => {
  if (!item.value.event_data) {
    return;
  }

  copyText(JSON.stringify(item.value.event_data, null, 2));
};

const copyOptions = (): void => {
  if (!item.value.options) {
    return;
  }

  copyText(JSON.stringify(item.value.options, null, 2));
};

const copyLogs = (): void => {
  copyText(filteredRows.value.map((logLine) => logLine.raw).join('\n'));
};
</script>
