<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-wrap items-center gap-2">
        <Popover placement="bottom-end" trigger="click" :z-index="13000" :disabled="!item">
          <template #trigger>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-copy"
              trailing-icon="i-lucide-chevron-down"
              :disabled="!item"
            >
              Copy
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
                <span>Copy event</span>
              </button>

              <button
                type="button"
                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
                @click="copyLogs(hide)"
              >
                <UIcon name="i-lucide-scroll-text" class="size-4 text-toned" />
                <span>Copy logs</span>
              </button>
            </div>
          </template>
        </Popover>

        <UTooltip
          v-if="item && (0 === item.status || 1 === item.status)"
          :text="autoRefresh ? 'Pause automatic refresh' : 'Refresh automatically every 5 seconds'"
        >
          <UButton
            color="neutral"
            :variant="autoRefresh ? 'soft' : 'outline'"
            size="sm"
            :icon="autoRefresh ? 'i-lucide-pause' : 'i-lucide-play'"
            :aria-pressed="autoRefresh"
            @click="autoRefresh = !autoRefresh"
            >{{ autoRefresh ? 'Auto on' : 'Auto off' }}</UButton
          >
        </UTooltip>

        <UTooltip text="Refresh event">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading || isSaving || wasDeleted"
            @click="loadContent"
          >
            Refresh
          </UButton>
        </UTooltip>
      </div>
    </div>

    <Teleport defer :to="props.actionsTarget">
      <div class="flex w-full flex-wrap items-center justify-end gap-2">
        <UTooltip
          :text="0 === item?.status ? 'Cancel this queued event' : 'Queue this event again'"
        >
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            :icon="0 === item?.status ? 'i-lucide-ban' : 'i-lucide-rotate-ccw'"
            :disabled="!item || isLoading || isSaving || 1 === item.status"
            @click="resetEvent(0 === item?.status ? 4 : 0)"
          >
            {{ 0 === item?.status ? 'Cancel' : 'Run again' }}
          </UButton>
        </UTooltip>
        <UTooltip text="Delete this event & its saved logs">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            :disabled="!item || isLoading || isSaving || 1 === item.status"
            @click="deleteItem"
          >
            Delete
          </UButton>
        </UTooltip>
      </div>
    </Teleport>

    <UAlert
      v-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-circle-alert"
      :title="item ? 'Event could not be refreshed' : 'Event could not be loaded'"
      :description="loadError"
    />
    <UAlert
      v-if="wasDeleted"
      color="neutral"
      variant="subtle"
      icon="i-lucide-trash-2"
      title="Event deleted"
    />

    <UAlert
      v-if="isLoading && !item?.id"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading event…"
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-if="item">
      <dl class="grid grid-cols-2 gap-x-6 gap-y-4 border-y border-default py-4 sm:grid-cols-4">
        <div class="min-w-0">
          <dt class="text-xs text-muted">Event</dt>
          <dd class="mt-1 wrap-anywhere text-sm font-medium text-highlighted">{{ item.event }}</dd>
        </div>
        <div>
          <dt class="text-xs text-muted">Status</dt>
          <dd class="mt-1">
            <UBadge :color="getEventStatusColor(item.status)" variant="soft">
              <UIcon
                :name="getEventStatusIcon(item.status)"
                :class="['size-3.5', 1 === item.status ? 'animate-spin' : '']"
              />
              {{ item.status_name }}
            </UBadge>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted">Created</dt>
          <dd class="mt-1 text-sm font-medium text-highlighted">
            <UTooltip :text="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)">
              <span class="cursor-help">{{ moment(item.created_at).fromNow() }}</span>
            </UTooltip>
          </dd>
        </div>
        <div>
          <dt class="text-xs text-muted">Updated</dt>
          <dd class="mt-1 text-sm font-medium text-highlighted">
            <UTooltip
              v-if="item.updated_at"
              :text="moment(item.updated_at).format(TOOLTIP_DATE_FORMAT)"
            >
              <span class="cursor-help">{{ moment(item.updated_at).fromNow() }}</span>
            </UTooltip>
            <span v-else class="text-muted">Not started</span>
          </dd>
        </div>
      </dl>
      <p v-if="item.reference" class="wrap-anywhere text-sm text-toned">
        <span class="font-medium text-muted">Reference</span> {{ item.reference }}
      </p>

      <section v-if="item.event_data && 0 < Object.keys(item.event_data).length" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :aria-expanded="toggleData"
            :aria-controls="`event-${id}-data`"
            @click="toggleData = !toggleData"
          >
            <span class="ws-detail-icon">
              <UIcon name="i-lucide-database" class="size-4" />
            </span>
            <span class="text-base font-semibold text-highlighted">Attached data</span>
            <UIcon
              name="i-lucide-chevron-right"
              :class="[
                'size-4 shrink-0 text-toned transition-transform',
                toggleData ? 'rotate-90' : '',
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
              @click="wrapData = !wrapData"
            >
              Wrap
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
          </div>
        </div>

        <div v-if="toggleData" :id="`event-${id}-data`" class="space-y-3">
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
            v-if="dataQuery && 0 === filteredEventDataLineCount"
            size="sm"
            variant="naked"
            icon="i-lucide-filter"
            title="No matching lines"
          />

          <code
            v-if="!dataQuery || 0 < filteredEventDataLineCount"
            class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto"
            :class="wrapData ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >
            {{ displayedEventData }}
          </code>
        </div>
      </section>

      <section v-if="item.logs && 0 < item.logs.length" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :aria-expanded="toggleLogs"
            :aria-controls="`event-${id}-logs`"
            @click="toggleLogs = !toggleLogs"
          >
            <span class="ws-detail-icon">
              <UIcon name="i-lucide-scroll-text" class="size-4" />
            </span>
            <span class="text-base font-semibold text-highlighted">Event logs</span>
            <UIcon
              name="i-lucide-chevron-right"
              :class="[
                'size-4 shrink-0 text-toned transition-transform',
                toggleLogs ? 'rotate-90' : '',
              ]"
            />
          </button>
          <div class="flex items-center gap-2">
            <UButton
              color="neutral"
              :variant="wrapLogs ? 'soft' : 'outline'"
              size="sm"
              icon="i-lucide-wrap-text"
              :aria-pressed="wrapLogs"
              @click="wrapLogs = !wrapLogs"
            >
              Wrap
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
          </div>
        </div>

        <div v-if="toggleLogs" :id="`event-${id}-logs`" class="space-y-3">
          <UInput
            v-model="query"
            type="search"
            icon="i-lucide-filter"
            size="sm"
            placeholder="Filter event logs"
            aria-label="Filter event logs"
            class="w-full"
          />

          <UEmpty
            v-if="query && 0 === filteredRows.length"
            size="sm"
            variant="naked"
            icon="i-lucide-filter"
            title="No matching logs"
          />

          <div
            class="min-w-0 overflow-y-auto overflow-x-hidden rounded-lg border border-default/70 bg-elevated/40 shadow-sm"
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
              <div class="ws-log-line">
                <template v-if="'structured' === row.kind">
                  <StructuredLogLine
                    :log="row.entry"
                    :show-details="true"
                    :wrapped="wrapLogs"
                    :expanded="isExpandedLogRow(row.key)"
                    toggleable
                    @details="openLogDetails"
                    @open-event="(id) => emit('openEvent', id)"
                    @toggle-expand="toggleExpandedLogRow(row.key)"
                  />
                </template>
                <template v-else>
                  <span
                    class="inline-flex max-w-full flex-wrap items-center gap-2 text-[11px] text-toned md:shrink-0 md:flex-nowrap"
                  >
                    <span class="inline-flex size-2 shrink-0 rounded-full bg-muted" />
                  </span>
                  <button
                    type="button"
                    class="block min-w-0 flex-1 cursor-pointer text-left"
                    :aria-expanded="isExpandedLogRow(row.key) || wrapLogs"
                    @click="toggleExpandedLogRow(row.key)"
                  >
                    <span :class="legacyLogMessageClass(row.key)">{{
                      String(row.entry.text).trim()
                    }}</span>
                  </button>
                </template>
              </div>
            </article>
          </div>
        </div>
      </section>

      <section v-if="item.options && 0 < Object.keys(item.options).length" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :aria-expanded="toggleOptions"
            :aria-controls="`event-${id}-options`"
            @click="toggleOptions = !toggleOptions"
          >
            <span class="ws-detail-icon">
              <UIcon name="i-lucide-settings-2" class="size-4" />
            </span>
            <span class="text-base font-semibold text-highlighted">Attached options</span>
            <UIcon
              name="i-lucide-chevron-right"
              :class="[
                'size-4 shrink-0 text-toned transition-transform',
                toggleOptions ? 'rotate-90' : '',
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
              @click="wrapOptions = !wrapOptions"
            >
              Wrap
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
          </div>
        </div>

        <div v-if="toggleOptions" :id="`event-${id}-options`" class="space-y-3">
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
            v-if="optionsQuery && 0 === filteredOptionsLineCount"
            size="sm"
            variant="naked"
            icon="i-lucide-filter"
            title="No matching lines"
          />

          <code
            v-if="!optionsQuery || 0 < filteredOptionsLineCount"
            class="ws-terminal ws-terminal-panel ws-terminal-panel-lg max-h-[35vh] overflow-auto"
            :class="wrapOptions ? 'whitespace-pre-wrap' : 'whitespace-pre'"
          >
            {{ displayedOptions }}
          </code>
        </div>
      </section>
    </template>

    <LogDetailsModal
      v-model="detailsOpen"
      :log="selectedLog"
      @open-event="
        (id) => {
          detailsOpen = false;
          emit('openEvent', id);
        }
      "
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { UAlert, UBadge, UButton, UEmpty, UIcon, UInput, UTooltip } from '#components';
import moment from 'moment';
import { useStorage } from '@vueuse/core';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import Popover from '~/components/Popover.vue';
import { useDialog } from '~/composables/useDialog';
import type { EventsItem, LogEntry, ServerJsonLogEntry } from '~/types';
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
  deleted: [EventsItem];
  openEvent: [eventId: string];
}>();

const props = defineProps<{ id: string; actionsTarget: string }>();

const query = ref<string>('');
const dataQuery = ref<string>('');
const optionsQuery = ref<string>('');
const item = ref<EventsItem | null>(null);
const isLoading = ref<boolean>(true);
const isSaving = ref(false);
const loadError = ref('');
const autoRefresh = ref(true);
const wasDeleted = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let loadVersion = 0;
let selectionVersion = 0;
const toggleLogs = useStorage<boolean>('events_toggle_logs', true);
const toggleData = useStorage<boolean>('events_toggle_data', true);
const toggleOptions = useStorage<boolean>('events_toggle_options', true);
const wrapData = useStorage<boolean>('events_wrap_data', false);
const wrapLogs = useStorage<boolean>('events_wrap_logs', false);
const wrapOptions = useStorage<boolean>('events_wrap_options', false);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);
const expandedLogRows = ref<Set<string>>(new Set());

type EventLogRow =
  | { kind: 'structured'; key: string; entry: ServerJsonLogEntry }
  | { kind: 'legacy'; key: string; entry: LogEntry };

const openLogDetails = (entry: ServerJsonLogEntry): void => {
  selectedLog.value = entry;
  detailsOpen.value = true;
};

const isExpandedLogRow = (key: string): boolean => expandedLogRows.value.has(key);

const toggleExpandedLogRow = (key: string): void => {
  const next = new Set(expandedLogRows.value);

  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }

  expandedLogRows.value = next;
};

const legacyLogMessageClass = (key: string): Array<string> => [
  'text-sm text-default',
  isExpandedLogRow(key) || wrapLogs.value
    ? 'block whitespace-pre-wrap wrap-break-word'
    : 'ws-log-message-collapsed md:block md:truncate',
];

watch(detailsOpen, (open) => {
  if (!open) {
    selectedLog.value = null;
  }
});

const eventDataJson = computed<string>(() => JSON.stringify(item.value?.event_data ?? {}, null, 2));
const filteredEventDataLines = computed<Array<string>>(() =>
  filterLogTextLines(eventDataJson.value, dataQuery.value),
);
const filteredEventDataLineCount = computed<number>(() => filteredEventDataLines.value.length);
const displayedEventData = computed<string>(() =>
  dataQuery.value ? filteredEventDataLines.value.join('\n') : eventDataJson.value,
);

const optionsJson = computed<string>(() => JSON.stringify(item.value?.options ?? {}, null, 2));
const filteredOptionsLines = computed<Array<string>>(() =>
  filterLogTextLines(optionsJson.value, optionsQuery.value),
);
const filteredOptionsLineCount = computed<number>(() => filteredOptionsLines.value.length);
const displayedOptions = computed<string>(() =>
  optionsQuery.value ? filteredOptionsLines.value.join('\n') : optionsJson.value,
);

const filteredRows = computed<Array<EventLogRow>>(() => {
  const logs = item.value?.logs ?? [];

  if (!query.value) {
    return logs.map((entry, index) => toEventLogRow(entry, index));
  }

  const queryValue = query.value.toLowerCase();

  return logs
    .map((entry, index) => toEventLogRow(entry, index))
    .filter((row) => {
      if ('structured' === row.kind) {
        if (query.value.includes('.')) {
          return 0 < filterLogTextLines(JSON.stringify(row.entry, null, 2), query.value).length;
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
  if (!item.value) {
    return;
  }

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
        if ('structured' === row.kind) {
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

const clearTimer = (): void => {
  if (null !== timer) {
    clearTimeout(timer);
    timer = null;
  }
};

const scheduleRefresh = (): void => {
  clearTimer();
  if (
    autoRefresh.value &&
    !loadError.value &&
    item.value &&
    (0 === item.value.status || 1 === item.value.status)
  ) {
    timer = setTimeout(() => void loadContent(), 5000);
  }
};

const loadContent = async (): Promise<void> => {
  if (isSaving.value || wasDeleted.value) {
    return;
  }

  clearTimer();
  const version = ++loadVersion;
  const id = props.id;
  isLoading.value = true;
  loadError.value = '';

  try {
    if (!id) {
      throw new Error('No event was selected.');
    }

    const response = await request(`/system/events/${id}`);
    const json = await parse_api_response<EventsItem>(response);
    if (version !== loadVersion || id !== props.id) {
      return;
    }

    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }
    if (!response.ok) {
      throw new Error(`${response.status}: ${response.statusText}`);
    }

    item.value = json;
  } catch (error) {
    if (version === loadVersion && id === props.id) {
      loadError.value = error instanceof Error ? error.message : 'Try refreshing this event again.';
      autoRefresh.value = false;
    }
  } finally {
    if (version === loadVersion) {
      isLoading.value = false;
      scheduleRefresh();
    }
  }
};

const deleteItem = async (): Promise<void> => {
  if (!item.value || isSaving.value || 1 === item.value.status) {
    return;
  }

  const id = item.value.id;
  const selection = selectionVersion;
  const { status } = await useDialog().confirmDialog({
    title: 'Delete event?',
    message: `Delete #${makeEventName(id)} & its saved logs? This can't be undone.`,
    confirmText: 'Delete',
    confirmColor: 'error',
  });

  if (
    selection !== selectionVersion ||
    true !== status ||
    id !== props.id ||
    id !== item.value?.id ||
    1 === item.value.status
  ) {
    return;
  }

  clearTimer();
  const version = ++loadVersion;
  isSaving.value = true;
  isLoading.value = false;

  try {
    const response = await request(`/system/events/${id}`, { method: 'DELETE' });
    const json = await parse_api_response<EventsItem>(response);
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (!response.ok) {
      throw new Error(`${response.status}: ${response.statusText}`);
    }
    if (version !== loadVersion || id !== props.id) {
      return;
    }

    item.value = null;
    wasDeleted.value = true;
    detailsOpen.value = false;
    notification('success', 'Event deleted', `Deleted #${makeEventName(id)} & its saved logs.`);
    emit('deleted', json);
  } catch (error) {
    notification(
      'error',
      'Could not delete event',
      error instanceof Error ? error.message : 'Try again.',
    );
  } finally {
    if (version === loadVersion) {
      isSaving.value = false;
      scheduleRefresh();
    }
  }
};

const resetEvent = async (status: number = 0): Promise<void> => {
  if (!item.value || isSaving.value || 1 === item.value.status) {
    return;
  }

  const id = item.value.id;
  const selection = selectionVersion;
  const { status: confirmed } = await useDialog().confirmDialog({
    title: 4 === status ? 'Cancel queued event?' : 'Run event again?',
    message:
      4 === status
        ? `Cancel #${makeEventName(id)}? Its saved logs will be cleared.`
        : `Queue #${makeEventName(id)} again? This repeats its action & clears its saved logs.`,
    confirmText: 4 === status ? 'Cancel event' : 'Run again',
    confirmColor: 'warning',
  });

  if (
    selection !== selectionVersion ||
    true !== confirmed ||
    id !== props.id ||
    id !== item.value?.id ||
    1 === item.value.status
  ) {
    return;
  }

  clearTimer();
  const version = ++loadVersion;
  isSaving.value = true;
  isLoading.value = false;

  try {
    const response = await request(`/system/events/${id}`, {
      method: 'PATCH',
      body: JSON.stringify({ status, reset_logs: true }),
    });
    const json = await parse_api_response<EventsItem>(response);
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (!response.ok) {
      throw new Error(`${response.status}: ${response.statusText}`);
    }
    if (version === loadVersion && id === props.id) {
      item.value = json;
      loadError.value = '';
      expandedLogRows.value = new Set();
    }
  } catch (error) {
    notification(
      'error',
      'Could not change event',
      error instanceof Error ? error.message : 'Try again.',
    );
  } finally {
    if (version === loadVersion) {
      isSaving.value = false;
      scheduleRefresh();
    }
  }
};

watch(autoRefresh, (enabled) => {
  if (enabled && item.value && (0 === item.value.status || 1 === item.value.status)) {
    void loadContent();
  } else {
    clearTimer();
  }
});

watch(
  () => props.id,
  () => {
    clearTimer();
    ++selectionVersion;
    ++loadVersion;
    item.value = null;
    wasDeleted.value = false;
    isSaving.value = false;
    query.value = '';
    dataQuery.value = '';
    optionsQuery.value = '';
    detailsOpen.value = false;
    expandedLogRows.value = new Set();
    void loadContent();
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  ++selectionVersion;
  ++loadVersion;
  clearTimer();
});
</script>
