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
            @click="onToggleFilter"
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
              @click="() => copyText(JSON.stringify(item.event_data, null, 2))"
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

        <div
          v-if="toggleLogs"
          class="overflow-auto rounded-lg border border-default/70 bg-elevated/40 shadow-sm"
          :style="{ maxHeight: '50vh' }"
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
                    wrapLines
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
                  />
                </p>
              </template>
              <template v-else>
                <span
                  class="mr-2 inline-flex size-2 shrink-0 rounded-full align-middle"
                  :class="toneDotClass(row.tone)"
                />
                <span :class="toneTextClass(row.tone)">{{ String(row.entry.text).trim() }}</span>
              </template>
            </div>
          </article>
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
              @click="() => copyText(JSON.stringify(item.options, null, 2))"
            />
          </UTooltip>
        </div>
      </UCard>
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
import {
  normalizeStructuredEntry,
  lineTone,
  toneDotClass,
  toneTextClass,
  type LogTone,
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
const wrapLines = useStorage<boolean>('events_wrap_lines', false);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);

const cardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

type EventLogRow =
  | { kind: 'structured'; key: string; entry: ServerJsonLogEntry }
  | { kind: 'legacy'; key: string; entry: LogEntry; tone: LogTone };

const onToggleFilter = (): void => {
  toggleFilter.value = !toggleFilter.value;
  if (!toggleFilter.value) {
    query.value = '';
  }
};

const openLogDetails = (entry: ServerJsonLogEntry): void => {
  selectedLog.value = entry;
  detailsOpen.value = true;
};

watch(detailsOpen, (open) => {
  if (!open) {
    selectedLog.value = null;
  }
});

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
    tone: lineTone(legacy.text),
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
