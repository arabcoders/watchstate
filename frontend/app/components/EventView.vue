<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-sm text-toned"></p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
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

        <Popover placement="bottom-end" trigger="click" :z-index="13000" :disabled="isLoading">
          <template #trigger>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-copy"
              trailing-icon="i-lucide-chevron-down"
              :disabled="isLoading"
            >
              <span class="hidden sm:inline">Copy</span>
            </UButton>
          </template>

          <template #content="{ hide }">
            <div class="w-52 space-y-1 p-1">
              <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
                @click="copyEventId(hide)"
              >
                <UIcon name="i-lucide-hash" class="size-4 text-toned" />
                <span>Copy ID</span>
              </button>

              <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
                @click="copyItem(hide)"
              >
                <UIcon name="i-lucide-copy" class="size-4 text-toned" />
                <span>Copy Event</span>
              </button>

              <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
                @click="copyLogs(hide)"
              >
                <UIcon name="i-lucide-scroll-text" class="size-4 text-toned" />
                <span>Copy Logs</span>
              </button>
            </div>
          </template>
        </Popover>

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
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Event" :value="item.event ?? 'Unknown'" icon="i-lucide-tag" />
        <StatCard
          label="Status"
          :value="item.status_name ?? 'Unknown'"
          :icon="getEventStatusIcon(item.status)"
          :color="getEventStatusColor(item.status)"
          :hint="String(item.status ?? '')"
        />
        <StatCard
          label="Created"
          :value="item.created_at ? moment(item.created_at).fromNow() : '-'"
          icon="i-lucide-clock"
          :tooltip="item.created_at ? moment(item.created_at).format(TOOLTIP_DATE_FORMAT) : ''"
        />
        <StatCard
          v-if="item.updated_at"
          label="Updated"
          :value="moment(item.updated_at).fromNow()"
          icon="i-lucide-clock"
          :tooltip="moment(item.updated_at).format(TOOLTIP_DATE_FORMAT)"
        />
        <StatCard v-else label="Updated" value="-" hint="not started" icon="i-lucide-clock" />
      </div>

      <section v-if="item?.event_data && Object.keys(item.event_data).length > 0" class="space-y-3">
        <button
          type="button"
          class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
          @click="toggleData = !toggleData"
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
              @click="copyText(displayedEventData)"
            >
              Copy
            </UButton>
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 text-toned transition-transform', toggleData ? 'rotate-90' : '']"
            />
          </div>
        </button>

        <template v-if="toggleData">
          <UInput
            v-model="dataQuery"
            type="search"
            icon="i-lucide-filter"
            size="sm"
            placeholder="Filter attached data"
            class="w-full"
          />

          <UAlert
            v-if="dataQuery && 0 === filteredEventDataLineCount"
            color="warning"
            variant="soft"
            icon="i-lucide-filter"
            title="No matching lines"
          />

          <code
            v-if="!dataQuery || filteredEventDataLineCount > 0"
            class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto"
            :class="wrapData ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >
            {{ displayedEventData }}
          </code>
        </template>
      </section>

      <section v-if="item?.logs && item.logs.length > 0" class="space-y-3">
        <button
          type="button"
          class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
          @click="toggleLogs = !toggleLogs"
        >
          <div class="flex items-center gap-3">
            <span
              class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
            >
              <UIcon name="i-lucide-scroll-text" class="size-4" />
            </span>
            <p class="text-base font-semibold text-highlighted">Event Logs</p>
          </div>
          <div class="flex items-center gap-2" @click.stop>
            <UButton
              color="neutral"
              :variant="wrapLogs ? 'soft' : 'outline'"
              size="sm"
              icon="i-lucide-wrap-text"
              @click="wrapLogs = !wrapLogs"
            >
              <span class="hidden sm:inline">Wrap</span>
            </UButton>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-copy"
              @click="copyLogs()"
            >
              Copy
            </UButton>
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 text-toned transition-transform', toggleLogs ? 'rotate-90' : '']"
            />
          </div>
        </button>

        <template v-if="toggleLogs">
          <UInput
            v-model="query"
            type="search"
            icon="i-lucide-filter"
            size="sm"
            placeholder="Filter event logs"
            class="w-full"
          />

          <UAlert
            v-if="query && 0 === filteredRows.length"
            color="warning"
            variant="soft"
            icon="i-lucide-filter"
            title="No matching logs"
          />

          <div
            class="overflow-auto rounded-lg border border-default/70 bg-elevated/40 shadow-sm"
            :style="{ maxHeight: '40vh' }"
          >
            <article
              v-for="(row, idx) in filteredRows"
              :key="row.key"
              :class="[
                'flex min-w-0 border-b border-default/40 bg-transparent last:border-b-0 hover:bg-elevated/70',
                1 === idx % 2 ? 'bg-elevated/40' : '',
              ]"
            >
              <div
                class="flex min-w-0 flex-1 items-start gap-[0.65rem] px-3 py-[0.65rem] leading-[1.6]"
              >
                <template v-if="row.kind === 'structured'">
                  <p
                    :class="[
                      wrapLogs
                        ? 'min-w-0 whitespace-pre-wrap ws-wrap-anywhere'
                        : 'min-w-max whitespace-pre',
                      'flex-1 text-default',
                    ]"
                  >
                    <StructuredLogLine
                      :log="row.entry"
                      :compact="true"
                      :show-details="true"
                      @details="openLogDetails"
                      @open-event="(id) => emit('openEvent', id)"
                    />
                  </p>
                </template>
                <template v-else>
                  <span
                    class="mr-2 inline-flex size-2 shrink-0 rounded-full bg-muted align-middle"
                  />
                  <span class="text-default">{{ String(row.entry.text).trim() }}</span>
                </template>
              </div>
            </article>
          </div>
        </template>
      </section>

      <section v-if="item?.options && Object.keys(item.options).length > 0" class="space-y-3">
        <button
          type="button"
          class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
          @click="toggleOptions = !toggleOptions"
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
              :class="['size-4 text-toned transition-transform', toggleOptions ? 'rotate-90' : '']"
            />
          </div>
        </button>

        <template v-if="toggleOptions">
          <UInput
            v-model="optionsQuery"
            type="search"
            icon="i-lucide-filter"
            size="sm"
            placeholder="Filter attached options"
            class="w-full"
          />

          <UAlert
            v-if="optionsQuery && 0 === filteredOptionsLineCount"
            color="warning"
            variant="soft"
            icon="i-lucide-filter"
            title="No matching lines"
          />

          <code
            v-if="!optionsQuery || filteredOptionsLineCount > 0"
            class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto"
            :class="wrapOptions ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >
            {{ displayedOptions }}
          </code>
        </template>
      </section>
    </template>

    <LogDetailsModal v-model:open="detailsOpen" :log="selectedLog" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { createError, useHead } from '#app';
import moment from 'moment';
import { useStorage } from '@vueuse/core';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import Popover from '~/components/Popover.vue';
import StatCard from '~/components/StatCard.vue';
import { useDialog } from '~/composables/useDialog';
import type { EventsItem, GenericError, LogEntry, ServerJsonLogEntry } from '~/types';
import {
  copyText,
  getEventStatusClass,
  makeEventName,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import { filterLogTextLines, normalizeStructuredEntry } from '~/utils/logs';

const emit = defineEmits<{
  closeOverlay: [];
  delete: [EventsItem];
  deleted: [EventsItem];
  openEvent: [eventId: string];
}>();

const props = defineProps<{ id: string }>();

const query = ref<string>('');
const dataQuery = ref<string>('');
const optionsQuery = ref<string>('');
const item = ref<EventsItem>({} as EventsItem);
const isLoading = ref<boolean>(true);
const timer = ref<ReturnType<typeof setInterval> | null>(null);
const toggleLogs = useStorage<boolean>('events_toggle_logs', true);
const toggleData = useStorage<boolean>('events_toggle_data', true);
const toggleOptions = useStorage<boolean>('events_toggle_options', true);
const wrapData = useStorage<boolean>('events_wrap_data', false);
const wrapLogs = useStorage<boolean>('events_wrap_logs', false);
const wrapOptions = useStorage<boolean>('events_wrap_options', false);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);

type EventLogRow =
  | { kind: 'structured'; key: string; entry: ServerJsonLogEntry }
  | { kind: 'legacy'; key: string; entry: LogEntry };

const openLogDetails = (entry: ServerJsonLogEntry): void => {
  selectedLog.value = entry;
  detailsOpen.value = true;
};

watch(detailsOpen, (open) => {
  if (!open) {
    selectedLog.value = null;
  }
});

const eventDataJson = computed<string>(() => JSON.stringify(item.value.event_data ?? {}, null, 2));
const filteredEventDataLines = computed<Array<string>>(() =>
  filterLogTextLines(eventDataJson.value, dataQuery.value),
);
const filteredEventDataLineCount = computed<number>(() => filteredEventDataLines.value.length);
const displayedEventData = computed<string>(() =>
  dataQuery.value ? filteredEventDataLines.value.join('\n') : eventDataJson.value,
);

const optionsJson = computed<string>(() => JSON.stringify(item.value.options ?? {}, null, 2));
const filteredOptionsLines = computed<Array<string>>(() =>
  filterLogTextLines(optionsJson.value, optionsQuery.value),
);
const filteredOptionsLineCount = computed<number>(() => filteredOptionsLines.value.length);
const displayedOptions = computed<string>(() =>
  optionsQuery.value ? filteredOptionsLines.value.join('\n') : optionsJson.value,
);

const filteredRows = computed<Array<EventLogRow>>(() => {
  const logs = item.value.logs ?? [];

  if (!query.value) {
    return logs.map((entry, index) => toEventLogRow(entry, index));
  }

  const queryValue = query.value.toLowerCase();

  return logs
    .map((entry, index) => toEventLogRow(entry, index))
    .filter((row) => {
      if (row.kind === 'structured') {
        if (query.value.includes('.')) {
          return filterLogTextLines(JSON.stringify(row.entry, null, 2), query.value).length > 0;
        }

        return row.entry.message.toLowerCase().includes(queryValue);
      }
      return row.entry.text.toLowerCase().includes(queryValue);
    });
});

const toEventLogRow = (raw: LogEntry | ServerJsonLogEntry, index: number): EventLogRow => {
  const structured = normalizeStructuredEntry(raw);
  if (null !== structured) {
    return { kind: 'structured', key: `${structured.id}:${index}`, entry: structured };
  }
  const legacy = raw as LogEntry;
  return {
    kind: 'legacy',
    key: `${legacy.id}:${index}`,
    entry: legacy,
  };
};

const copyEventId = (hide?: () => void): void => {
  copyText(item.value.id);
  hide?.();
};

const copyItem = (hide?: () => void): void => {
  copyText(JSON.stringify(item.value, null, 2));
  hide?.();
};

const copyLogs = (hide?: () => void): void => {
  copyText(
    filteredRows.value
      .map((row) => {
        if (row.kind === 'structured') {
          return `[${row.entry.datetime}] ${row.entry.level.toUpperCase()} [${row.entry.logger}] ${row.entry.message}`;
        }
        const prefix = row.entry.date ? `[${row.entry.date}] ` : '';
        return `${prefix}${String(row.entry.text).trim()}`;
      })
      .join('\n'),
  );
  hide?.();
};

const getEventStatusColor = (
  status: number,
): 'primary' | 'success' | 'error' | 'warning' | 'info' | 'neutral' => {
  const value = getEventStatusClass(status);
  if (value.includes('danger')) return 'error';
  if (value.includes('warning')) return 'warning';
  if (value.includes('success')) return 'success';
  if (value.includes('info')) return 'info';
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

onMounted(async () => {
  if (!props.id) {
    throw createError({
      statusCode: 404,
      message: 'Error ID not provided.',
    });
  }
  return await loadContent();
});

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
        timer.value = setInterval(async () => await loadContent(), 5000);
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
  const { status: confirmStatus } = await useDialog().confirmDialog({
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
  } catch (e: any) {
    console.error(e);
    notification('crit', 'Error', `Events view patch Request failure. ${e.message}`);
  }
};
</script>
