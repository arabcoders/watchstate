<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0 space-y-2">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" :class="['size-4', stream ? 'animate-spin' : '']" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <NuxtLink to="/logs" class="text-toned hover:text-primary">{{
            pageShell.pageLabel
          }}</NuxtLink>
          <span>/</span>
          <span class="truncate text-highlighted normal-case tracking-normal">{{ filename }}</span>
        </div>
      </div>

      <div v-if="!error" class="flex flex-wrap items-center justify-end gap-2">
        <UTooltip v-if="'log' === contentType && !autoScroll" text="Go to bottom">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-chevron-down"
            @click="scrollToBottom"
          >
            <span class="hidden sm:inline">Bottom</span>
          </UButton>
        </UTooltip>

        <UInput
          v-if="'json' !== contentType && (toggleFilter || query)"
          id="filter"
          v-model="query"
          type="search"
          :placeholder="'log' === contentType ? 'Filter log entries' : 'Filter'"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <UTooltip v-if="'json' !== contentType" text="Filter log lines.">
          <UButton
            color="neutral"
            :variant="toggleFilter ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-filter"
            @click="toggleFilter = !toggleFilter"
          >
            <span class="hidden sm:inline">Filter</span>
          </UButton>
        </UTooltip>

        <USelect
          v-if="'log' === contentType"
          v-model="selectedLevels"
          :items="levelFilterItems"
          value-key="value"
          label-key="label"
          multiple
          size="sm"
          icon="i-lucide-list-filter"
          class="w-44 shrink-0 sm:w-48"
          :ui="{ content: 'min-w-48' }"
        >
          <template #default>
            {{ levelFilterLabel }}
          </template>
        </USelect>

        <UTooltip text="Delete logfile.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            @click="deleteFile"
          >
            <span class="hidden sm:inline">Delete</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Download file.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-download"
            :loading="isDownloading"
            @click="downloadFile"
          >
            <span class="hidden sm:inline">Download</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Toggle wrap line">
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

        <UTooltip text="Copy text">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            @click="copyData"
          >
            <span class="hidden sm:inline">Copy</span>
          </UButton>
        </UTooltip>
      </div>
    </div>

    <UAlert
      v-if="error"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="API Error"
      close
      @update:open="(open) => (!open ? router.push('/logs') : undefined)"
    >
      <template #description>
        <p class="text-sm text-default">{{ error }}</p>
      </template>
    </UAlert>

    <UAlert
      v-else-if="isLoading && 0 === rawLines.length"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-else-if="!error">
      <UCard class="overflow-hidden border border-default/70 shadow-sm" :ui="viewerCardUi">
        <div
          v-if="'json' === contentType"
          ref="logContainer"
          class="logbox overflow-auto"
          :style="logContainerStyle"
        >
          <code
            id="logView"
            class="logline block"
            :class="
              wrapLines
                ? 'min-w-0 whitespace-pre-wrap ws-wrap-anywhere'
                : 'w-max min-w-full whitespace-pre'
            "
          >
            {{ renderJson(rawLines) }}
          </code>
        </div>

        <div
          v-else
          ref="logContainer"
          class="overflow-auto"
          @scroll.passive="handleScroll"
          :style="logContainerStyle"
        >
          <div
            v-if="reachedEnd && !hasActiveStructuredFilter"
            class="flex justify-center border-b border-default/40 px-4 py-3"
          >
            <div
              class="inline-flex items-center gap-1.5 rounded-full border border-warning/30 bg-warning/10 px-3 py-1 text-[11px] font-medium text-warning"
            >
              <UIcon name="i-lucide-triangle-alert" class="size-3.5 shrink-0" />
              No older lines remain in this file.
            </div>
          </div>

          <div
            v-if="canLoadFilteredHistory"
            class="flex justify-center border-b border-default/40 px-4 py-3"
          >
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              icon="i-lucide-history"
              :loading="isLoading"
              @click="loadContent(true)"
            >
              Load older lines into filter
            </UButton>
          </div>

          <template v-if="structuredRows.length > 0">
            <article
              v-for="(entry, index) in structuredRows"
              :key="entry.key"
              :class="structuredRowClass(entry, index)"
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
            class="flex min-h-[55vh] flex-col items-center justify-center gap-3 px-6 py-8 text-center"
          >
            <UIcon
              :name="hasActiveStructuredFilter ? 'i-lucide-filter-x' : 'i-lucide-circle-off'"
              class="size-6 text-toned"
            />

            <div class="space-y-1">
              <p class="text-sm font-medium text-default">
                {{ emptyTitle }}
              </p>

              <p class="text-sm text-toned">
                {{ emptyDescription }}
              </p>
            </div>
          </div>
        </div>
      </UCard>

      <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
        <template #body>
          <EventView v-if="selectedEventId" :id="selectedEventId" />
        </template>
      </UModal>

      <LogDetailsModal :log="selectedLog" v-model:open="detailsOpen" />
    </template>
  </main>
</template>

<style scoped>
code {
  background-color: unset;
}

.logbox {
  background-color: #1f2229;
  min-width: 100%;
}

div.logbox pre {
  background-color: rgb(31, 34, 41);
}

.logline {
  line-height: 1.8em;
}
</style>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, onUnmounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import { fetchEventSource } from '@microsoft/fetch-event-source';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import LogDetailsChip from '~/components/LogDetailsChip.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type { GenericResponse, LogEntry, LogResponse } from '~/types';
import { copyText, makeEventName, notification, parse_api_response, request } from '~/utils';
import {
  getLogLevel,
  LOG_LEVEL_ICON,
  logLevelBadgeClass,
  logMessageText,
  logSearchText,
  logTimestampLabel,
  logTimestampTitle,
  parseLogLine,
  parseLogLines,
} from '~/utils/logs';

const router = useRouter();
const route = useRoute();
const filenameParam = Array.isArray(route.params.filename)
  ? route.params.filename[0]
  : route.params.filename;
const filename = filenameParam ?? '';

const pageShell = requireTopLevelPageShell('logs');

useHead({ title: `Logs : ${filename}` });

const query = ref<string>('');
const data = ref<Array<LogEntry>>([]);
const rawLines = ref<Array<string>>([]);
const error = ref<string>('');
const wrapLines = useStorage('logs_wrap_lines', false);
const selectedLevels = useStorage<Array<LogLevel>>('logs_level_filter', [
  'debug',
  'info',
  'notice',
  'warning',
  'error',
]);
const isDownloading = ref<boolean>(false);
const isLoading = ref<boolean>(false);
const toggleFilter = ref<boolean>(false);
const autoScroll = ref<boolean>(true);
const reachedEnd = ref<boolean>(false);
const offset = ref<number>(0);
const contentType = ref<'log' | 'json'>('log');
const stream = ref<boolean>(false);
const logContainer = ref<HTMLElement | null>(null);
const streamController = ref<AbortController | null>(null);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<LogEntry | null>(null);
const isTodayLog = computed<boolean>(() => filename.includes(moment().format('YYYYMMDD')));

type LogLevel = 'debug' | 'info' | 'notice' | 'warning' | 'error';

type StructuredLogRow = {
  key: string;
  log: LogEntry;
  level: LogLevel;
  isMatch: boolean;
  isContext: boolean;
};

type LevelFilterItem = {
  label: string;
  value: LogLevel;
};

const LOG_LEVELS: Array<LogLevel> = ['debug', 'info', 'notice', 'warning', 'error'];
const FILTER_CONTEXT_REGEX = /context:(\d+)/;

const eventViewModalUi = {
  content: 'max-w-5xl',
  body: 'p-4 sm:p-5',
};

const logContainerStyle = {
  minHeight: '70vh',
  maxHeight: '70vh',
} as const;

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

let scrollTimeout: ReturnType<typeof setTimeout> | null = null;

const token = useStorage('token', '');
const dialog = useDialog();

type FilePickerOptions = {
  suggestedName?: string;
};

type FilePickerHandle = {
  createWritable: () => Promise<WritableStream>;
};

const viewerCardUi = {
  body: 'p-0',
};

const scrollLogContainerToBottom = (behavior: ScrollBehavior = 'auto'): void => {
  if (!logContainer.value) {
    return;
  }

  logContainer.value.scrollTo({ top: logContainer.value.scrollHeight, behavior });
};

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
    scrollToBottom();
  }
});

const normalizedQuery = computed(() => query.value.trim().toLowerCase());
const selectedLevelSet = computed(
  () => new Set(LOG_LEVELS.filter((level) => selectedLevels.value.includes(level))),
);
const hasLevelFilter = computed(() => selectedLevelSet.value.size !== LOG_LEVELS.length);
const filterContext = computed(() => {
  const match = normalizedQuery.value.match(FILTER_CONTEXT_REGEX);
  return match ? parseInt(match[1] ?? '0', 10) : 0;
});
const searchTerm = computed(() => normalizedQuery.value.replace(FILTER_CONTEXT_REGEX, '').trim());
const hasTextFilter = computed(() => Boolean(searchTerm.value));
const hasActiveStructuredFilter = computed(() => hasTextFilter.value || hasLevelFilter.value);
const canLoadFilteredHistory = computed(
  () =>
    'log' === contentType.value &&
    hasActiveStructuredFilter.value &&
    !reachedEnd.value &&
    data.value.length > 0,
);
const levelCounts = computed<Record<LogLevel, number>>(() => {
  const counts: Record<LogLevel, number> = {
    debug: 0,
    info: 0,
    notice: 0,
    warning: 0,
    error: 0,
  };

  data.value.forEach((item) => {
    const level = getLogLevel(item.level);
    counts[level] += 1;
  });

  return counts;
});
const levelFilterItems = computed<Array<LevelFilterItem>>(() =>
  LOG_LEVELS.map((level) => ({
    label: `${level.charAt(0).toUpperCase()}${level.slice(1)} (${levelCounts.value[level]})`,
    value: level,
  })),
);
const levelFilterLabel = computed(() => {
  if (selectedLevelSet.value.size === LOG_LEVELS.length) {
    return `All levels (${data.value.length})`;
  }

  if (selectedLevelSet.value.size === 0) {
    return 'No levels selected';
  }

  return LOG_LEVELS.filter((level) => selectedLevelSet.value.has(level)).join(', ');
});

const structuredRows = computed<Array<StructuredLogRow>>(() => {
  if ('log' !== contentType.value) {
    return [];
  }

  if (!hasActiveStructuredFilter.value) {
    return data.value.map((log, index) => ({
      key: `${log.id}:${index}`,
      log,
      level: getLogLevel(log.level),
      isMatch: false,
      isContext: false,
    }));
  }

  const result: Array<StructuredLogRow> = [];
  const visibleIndexes = new Set<number>();
  const matchedIndexes = new Set<number>();

  data.value.forEach((log, index) => {
    if (!selectedLevelSet.value.has(getLogLevel(log.level))) {
      return;
    }

    if (!hasTextFilter.value) {
      visibleIndexes.add(index);
      return;
    }

    if (logSearchText(log).includes(searchTerm.value)) {
      matchedIndexes.add(index);

      for (
        let cursor = Math.max(0, index - filterContext.value);
        cursor <= Math.min(data.value.length - 1, index + filterContext.value);
        cursor++
      ) {
        visibleIndexes.add(cursor);
      }
    }
  });

  Array.from(visibleIndexes)
    .sort((a, b) => a - b)
    .forEach((index) => {
      const log = data.value[index];
      if (!log || !selectedLevelSet.value.has(getLogLevel(log.level))) {
        return;
      }

      result.push({
        key: `${log.id}:${index}`,
        log,
        level: getLogLevel(log.level),
        isMatch: matchedIndexes.has(index),
        isContext: !matchedIndexes.has(index),
      });
    });

  return result;
});

const emptyTitle = computed(() => {
  if ('json' === contentType.value) {
    return 'No log lines available';
  }

  if (hasActiveStructuredFilter.value) {
    return 'No logs match these filters';
  }

  if (!isLoading.value) {
    return 'No log lines available';
  }

  return 'Loading logs...';
});

const emptyDescription = computed(() => {
  if ('json' === contentType.value) {
    return 'This file has no visible log lines yet.';
  }

  if (hasActiveStructuredFilter.value) {
    return 'Adjust filters or load older lines into the current filter.';
  }

  return stream.value
    ? 'Waiting for new stream output.'
    : 'This file has no visible log lines yet.';
});

const openEvent = (id: string): void => {
  selectedEventId.value = id;
};

const openLogDetails = (log: LogEntry): void => {
  selectedLog.value = log;
};

const loadContent = async (force = false): Promise<void> => {
  if (isLoading.value) {
    return;
  }

  if (
    'log' === contentType.value &&
    hasActiveStructuredFilter.value &&
    !force &&
    data.value.length > 0
  ) {
    return;
  }

  try {
    isLoading.value = true;
    const response = await request(`/log/${filename}?offset=${offset.value}`);
    const json = await parse_api_response<LogResponse>(response);

    if (200 !== response.status) {
      if ('error' in json) {
        error.value = `${json.error.code}: ${json.error.message}`;
      }
      return;
    }

    if ('logs-filename' !== route.name) {
      return;
    }

    if ('error' in json) {
      error.value = `${json.error.code}: ${json.error.message}`;
      return;
    }

    contentType.value = json.type ?? 'log';

    if (0 < json.lines.length) {
      rawLines.value.unshift(...json.lines);

      if ('log' === contentType.value) {
        data.value.unshift(...parseLogLines(json.lines));
      }
    }

    offset.value = json.next ?? offset.value;
    if (null === json.next) {
      reachedEnd.value = true;
    }

    await nextTick(() => {
      if (autoScroll.value) {
        scrollLogContainerToBottom('auto');
      }
    });

    watchLog();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unexpected error';
  } finally {
    isLoading.value = false;
  }
};

const handleScroll = (): void => {
  if (!logContainer.value || 'json' === contentType.value) {
    return;
  }

  if (hasActiveStructuredFilter.value) {
    return;
  }

  const container = logContainer.value;
  const nearBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50;
  const nearTop = container.scrollTop < 50;
  autoScroll.value = nearBottom;

  if (nearTop && !isLoading.value && !scrollTimeout && !reachedEnd.value) {
    scrollTimeout = setTimeout(async () => {
      const previousHeight = container.scrollHeight;
      await loadContent();
      await nextTick(() => {
        const newHeight = container.scrollHeight;
        container.scrollTop += newHeight - previousHeight;
      });
      scrollTimeout = null;
    }, 300);
  }
};

const scrollToBottom = (): void => {
  autoScroll.value = true;
  nextTick(() => {
    scrollLogContainerToBottom('smooth');
  });
};

onMounted(async () => {
  await loadContent();
});

onBeforeUnmount(() => closeStream());

onUnmounted(async () => {
  closeStream();
  if (scrollTimeout) {
    clearTimeout(scrollTimeout);
    scrollTimeout = null;
  }
});

const watchLog = (): void => {
  if (!isTodayLog.value || 'log' !== contentType.value || stream.value) {
    return;
  }

  const controller = new AbortController();
  streamController.value = controller;
  stream.value = true;

  void fetchEventSource(`/v1/api/log/${filename}?stream=1`, {
    onmessage: async (evt) => {
      if ('data' !== evt.event) {
        return;
      }

      try {
        const payload = JSON.parse(evt.data) as { data?: string };
        const line = typeof payload.data === 'string' ? payload.data.trim() : '';

        if (!line) {
          return;
        }

        rawLines.value.push(line);

        if ('log' === contentType.value) {
          data.value.push(parseLogLine(line));
        }

        await nextTick(() => {
          if (autoScroll.value) {
            scrollLogContainerToBottom('smooth');
          }
        });
      } catch (streamError) {
        console.error(streamError);
      }
    },
    onclose: () => {
      stream.value = false;
      streamController.value = null;
    },
    headers: {
      Authorization: `Token ${token.value}`,
    },
    signal: controller.signal,
  }).catch((streamError) => {
    if (controller.signal.aborted) {
      return;
    }

    console.error(streamError);
    stream.value = false;
    streamController.value = null;
  });
};

const closeStream = (): void => {
  streamController.value?.abort();
  streamController.value = null;
  stream.value = false;
};

const downloadFile = async (): Promise<void> => {
  isDownloading.value = true;

  try {
    const response = await request(`/log/${filename}?download=1`);
    const pickerWindow = window as Window & {
      showSaveFilePicker?: (options: FilePickerOptions) => Promise<FilePickerHandle>;
    };
    const showSaveFilePicker = pickerWindow.showSaveFilePicker;

    if (showSaveFilePicker) {
      if (!response.body) {
        notification('error', 'Error', 'No data returned from download request.');
        return;
      }

      const handle = await showSaveFilePicker({
        suggestedName: `${filename}`,
      });
      await response.body.pipeTo(await handle.createWritable());
      return;
    }

    const blob = await response.blob();
    const fileURL = URL.createObjectURL(blob);
    const fileLink = document.createElement('a');
    fileLink.href = fileURL;
    fileLink.download = `${filename}`;
    fileLink.click();
    URL.revokeObjectURL(fileURL);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    if (message.includes('aborted')) {
      return;
    }
    notification('error', 'Error', `Failed to download the file. ${message}`);
  } finally {
    isDownloading.value = false;
  }
};

const deleteFile = async (): Promise<void> => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Are you sure you want to delete the log file '${filename}'? This action cannot be undone.`,
    confirmText: 'Delete',
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    closeStream();

    const response = await request(`/log/${filename}`, { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);

    if (response.ok) {
      notification('success', 'Information', `Logfile '${filename}' has been deleted.`);
      await router.push('/logs');
      return;
    }

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Request to delete logfile failed. (${json.error.code}: ${json.error.message}).`,
      );
      return;
    }

    notification('error', 'Error', 'Request to delete logfile failed.');
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    notification('error', 'Error', `Failed to request to delete a logfile. ${message}.`);
  }
};

const lineTitle = (item: LogEntry): string => logTimestampTitle(item.datetime ?? item.date);

const timestampLabel = (item: LogEntry): string => logTimestampLabel(item.datetime ?? item.date);

const logMessage = (item: LogEntry): string => logMessageText(item);

const renderStructuredRowText = (entry: StructuredLogRow): string => {
  const parts = [timestampLabel(entry.log), entry.level.toUpperCase()];

  if (entry.log.logger) {
    parts.push(`[${entry.log.logger}]`);
  }

  parts.push(logMessage(entry.log));

  if (entry.log.exception_message) {
    parts.push(`: ${entry.log.exception_message}`);
  }

  return parts.join(' ');
};

const structuredLineClass = computed<Array<string>>(() => [
  'flex-1',
  wrapLines.value ? 'min-w-0 whitespace-pre-wrap wrap-break-word' : 'min-w-max whitespace-pre',
  'text-default',
]);

const structuredRowClass = (_entry: StructuredLogRow, index: number): Array<string> => {
  const classes = [
    'flex min-w-0 border-b border-default/40 bg-transparent transition-colors duration-150 last:border-b-0 hover:bg-elevated/70',
  ];

  if (_entry.isMatch) {
    classes.push('bg-warning/10');
    return classes;
  }

  if (_entry.isContext) {
    classes.push('bg-muted/30');
    return classes;
  }

  if (index % 2 === 1) {
    classes.push('bg-elevated/40');
  }

  return classes;
};

const renderJson = (lines: Array<string>): string => {
  try {
    return JSON.stringify(JSON.parse(lines.join('')), null, 4);
  } catch {
    return lines.join('\n');
  }
};

const copyData = (): void => {
  if ('json' === contentType.value) {
    copyText(renderJson(rawLines.value));
    return;
  }

  copyText(structuredRows.value.map((item) => renderStructuredRowText(item)).join('\n'));
};
</script>
