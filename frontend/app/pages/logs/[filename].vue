<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" description="">
      <template #icon>
        <UIcon :name="pageShell.icon" :class="['size-4', stream ? 'animate-spin' : '']" />
      </template>
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <NuxtLink to="/logs" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
        <span>/</span>
        <span class="truncate text-highlighted normal-case tracking-normal">{{ filename }}</span>
      </template>
      <template #actions>
        <template v-if="!error">
          <UTooltip v-if="!autoScroll" text="Go to bottom">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-chevron-down"
              @click="scrollToBottom"
            >
              Bottom
            </UButton>
          </UTooltip>

          <UInput
            v-if="toggleFilter"
            v-model.lazy="query"
            type="search"
            placeholder="Filter log entries"
            icon="i-lucide-filter"
            autofocus
            size="sm"
            class="w-full sm:w-72"
          />

          <UButton
            icon="i-lucide-filter"
            :variant="toggleFilter ? 'soft' : 'outline'"
            color="neutral"
            size="sm"
            @click="toggleFilter = !toggleFilter"
          >
            Filter
          </UButton>

          <USelect
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
            <template #default>{{ levelFilterLabel }}</template>
          </USelect>

          <UButton
            icon="i-lucide-wrap-text"
            :variant="wrapLines ? 'soft' : 'outline'"
            color="neutral"
            size="sm"
            @click="wrapLines = !wrapLines"
          >
            Wrap
          </UButton>

          <UTooltip text="Delete logfile.">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              @click="deleteFile"
            >
              Delete
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
              Download
            </UButton>
          </UTooltip>

          <UTooltip text="Copy text">
            <UDropdownMenu :items="copyMenuItems" :content="{ align: 'end' }" :modal="false">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-copy"
                trailing-icon="i-lucide-chevron-down"
              >
                Copy
              </UButton>
            </UDropdownMenu>
          </UTooltip>

          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            size="sm"
            @click="reloadLog"
          >
            Refresh
          </UButton>
        </template>
      </template>
    </PageHeader>

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

    <template v-else-if="!error">
      <div
        ref="logContainer"
        class="min-w-0 overflow-y-auto overflow-x-hidden border border-default bg-elevated/30 shadow-sm text-default"
        :style="{ minHeight: '70vh', maxHeight: '70vh' }"
        @scroll.passive="handleScroll"
      >
        <div
          v-if="reachedEnd && !hasActiveFilter"
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
            @click="() => loadContent(true)"
          >
            Load older lines into filter
          </UButton>
        </div>

        <template v-if="0 < rows.length">
          <article v-for="(entry, index) in rows" :key="entry.key" :class="rowClass(entry, index)">
            <div
              class="flex w-full min-w-0 flex-col gap-1 px-3 py-[0.65rem] leading-[1.6] md:flex-row md:items-start md:gap-2"
            >
              <StructuredLogLine
                :log="entry.log"
                :show-details="true"
                :wrapped="wrapLines"
                :expanded="isExpandedLogRow(entry.key)"
                toggleable
                @details="openLogDetails"
                @open-event="(id) => (selectedEventId = id)"
                @toggle-expand="toggleExpandedLogRow(entry.key)"
              />
            </div>
          </article>
        </template>

        <div
          v-else
          class="flex min-h-[55vh] flex-col items-center justify-center gap-3 px-6 py-8 text-center"
        >
          <UIcon
            :name="hasActiveFilter ? 'i-lucide-filter-x' : 'i-lucide-circle-off'"
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
    </template>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :id="selectedEventId"
          @open-event="(id) => (selectedEventId = id)"
        />
      </template>
    </UModal>

    <LogDetailsModal
      v-model:open="detailsOpen"
      :log="selectedLog"
      @open-event="
        (id) => {
          detailsOpen = false;
          selectedEventId = id;
        }
      "
    />
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, onUnmounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import { fetchEventSource } from '@microsoft/fetch-event-source';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import PageHeader from '~/components/PageHeader.vue';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type { GenericResponse, LogResponse, ServerJsonLogEntry } from '~/types';
import { copyText, makeEventName, notification, parse_api_response, request } from '~/utils';
import { getLogLevel, normalizeStructuredEntry } from '~/utils/logs';
import type { LogLevel } from '~/utils/logs';

const FILTER_CONTEXT_REGEX = /context:(\d+)/;
const LOG_LEVELS: Array<LogLevel> = ['debug', 'info', 'notice', 'warning', 'error'];
const LOG_ROW_CLASS =
  'flex min-w-0 border-b border-default/40 bg-transparent transition-colors duration-150 last:border-b-0 hover:bg-elevated/70';

type LevelFilterItem = { label: string; value: LogLevel };
type LogRow = {
  key: string;
  number: number;
  index: number;
  log: ServerJsonLogEntry;
  level: LogLevel;
  isMatch: boolean;
  isContext: boolean;
};

const router = useRouter();
const route = useRoute();
const filenameParam = Array.isArray(route.params.filename)
  ? route.params.filename[0]
  : route.params.filename;
const filename = filenameParam ?? '';

const pageShell = requireTopLevelPageShell('logs');
useHead({ title: `Logs : ${filename}` });

const token = useStorage('token', '');
const dialog = useDialog();

const query = ref<string>('');
const data = ref<Array<ServerJsonLogEntry>>([]);
const error = ref<string>('');
const wrapLines = useStorage('logs_wrap_lines', false);
const isDownloading = ref<boolean>(false);
const isLoading = ref<boolean>(false);
const toggleFilter = ref<boolean>(false);
const autoScroll = ref<boolean>(true);
const reachedEnd = ref<boolean>(false);
const offset = ref<number>(0);
const stream = ref<boolean>(false);
const logContainer = ref<HTMLElement | null>(null);
const streamController = ref<AbortController | null>(null);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);
const expandedLogRows = ref<Set<string>>(new Set());
const selectedLevels = useStorage<Array<LogLevel>>('logs_level_filter', [...LOG_LEVELS]);

let scrollTimeout: ReturnType<typeof setTimeout> | null = null;

const isTodayLog = computed<boolean>(() => filename.includes(moment().format('YYYYMMDD')));

const eventViewModalUi = { content: 'max-w-5xl', body: 'p-4 sm:p-5' };
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

const normalizedQuery = computed(() => query.value.trim().toLowerCase());
const selectedLevelSet = computed(
  () => new Set(LOG_LEVELS.filter((level) => selectedLevels.value.includes(level))),
);
const hasLevelFilter = computed(() => selectedLevelSet.value.size !== LOG_LEVELS.length);
const filterContext = computed(() => {
  const m = normalizedQuery.value.match(FILTER_CONTEXT_REGEX);
  return m ? parseInt(m[1] ?? '0', 10) : 0;
});
const searchTerm = computed(() => normalizedQuery.value.replace(FILTER_CONTEXT_REGEX, '').trim());
const hasTextFilter = computed(() => Boolean(searchTerm.value));
const hasActiveFilter = computed(() => hasTextFilter.value || hasLevelFilter.value);
const canLoadFilteredHistory = computed(
  () => hasActiveFilter.value && !reachedEnd.value && data.value.length > 0,
);

const levelCounts = computed<Record<LogLevel, number>>(() => {
  const counts: Record<LogLevel, number> = { debug: 0, info: 0, notice: 0, warning: 0, error: 0 };
  data.value.forEach((log) => {
    const l = getLogLevel(log.level);
    counts[l] += 1;
  });
  return counts;
});

const levelFilterItems = computed<Array<LevelFilterItem>>(() =>
  LOG_LEVELS.map((level) => ({
    label: `${ucFirst(level)} (${levelCounts.value[level]})`,
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

const searchableLog = (log: ServerJsonLogEntry): string =>
  [
    log.message,
    log.level,
    log.logger,
    log.exception ? JSON.stringify(log.exception) : '',
    log.source ? JSON.stringify(log.source) : '',
    log.process ? JSON.stringify(log.process) : '',
    log.fields ? JSON.stringify(log.fields) : '',
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();

const rows = computed<Array<LogRow>>(() => {
  if (!hasActiveFilter.value) {
    return data.value.map((log, index) => ({
      key: `${log.id}:${index}`,
      number: index + 1,
      index,
      log,
      level: getLogLevel(log.level),
      isMatch: false,
      isContext: false,
    }));
  }

  const result: Array<LogRow> = [];
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
    if (searchableLog(log).includes(searchTerm.value)) {
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
        number: index + 1,
        index,
        log,
        level: getLogLevel(log.level),
        isMatch: matchedIndexes.has(index),
        isContext: !matchedIndexes.has(index),
      });
    });

  return result;
});

const rowClass = (entry: LogRow, index: number): Array<string> => {
  const classes = [LOG_ROW_CLASS];
  if (entry.isMatch) {
    classes.push('bg-warning/10');
    return classes;
  }
  if (entry.isContext) {
    classes.push('bg-muted/30');
    return classes;
  }
  if (index % 2 === 1) {
    classes.push('bg-elevated/40');
  }
  return classes;
};

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

const applyLogBatch = (items: Array<unknown>, prepend = false): void => {
  const entries = items
    .map((item) => normalizeStructuredEntry(item))
    .filter((item): item is ServerJsonLogEntry => null !== item);
  if (entries.length < 1) {
    return;
  }
  data.value = prepend ? [...entries, ...data.value] : [...data.value, ...entries];
};

const loadContent = async (force = false): Promise<void> => {
  if (isLoading.value) {
    return;
  }
  if (hasActiveFilter.value && !force && data.value.length > 0) {
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

    if (0 < json.lines.length) {
      applyLogBatch(json.lines as Array<unknown>, true);
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

const reloadLog = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }
  offset.value = 0;
  reachedEnd.value = false;
  data.value = [];
  await loadContent();
};

const scrollLogContainerToBottom = (behavior: ScrollBehavior = 'auto'): void => {
  if (!logContainer.value) {
    return;
  }
  logContainer.value.scrollTo({ top: logContainer.value.scrollHeight, behavior });
};

const emptyTitle = computed(() => {
  if (hasActiveFilter.value) {
    return 'No logs match these filters';
  }
  if (!isLoading.value) {
    return 'No log lines available';
  }
  return 'Loading logs...';
});

const emptyDescription = computed(() => {
  if (hasActiveFilter.value) {
    return 'Adjust filters or load older lines into the current filter.';
  }
  return stream.value
    ? 'Waiting for new stream output.'
    : 'This source has no available log lines yet.';
});

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});
watch(detailsOpen, (open) => {
  if (!open) {
    selectedLog.value = null;
  }
});

const handleScroll = (): void => {
  if (!logContainer.value || hasActiveFilter.value) {
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
  if (!isTodayLog.value || stream.value) {
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
      const lines = evt.data.split(/\n/g);

      for (let i = 0; i < lines.length; i++) {
        try {
          const line = String(lines[i]);
          if (!line.trim()) {
            continue;
          }
          applyLogBatch([line]);
          await nextTick(() => {
            if (autoScroll.value) {
              scrollLogContainerToBottom('smooth');
            }
          });
        } catch {
          /* ignore */
        }
      }
    },
    onclose: () => {
      stream.value = false;
      streamController.value = null;
    },
    headers: { Authorization: `Token ${token.value}` },
    signal: controller.signal,
  }).catch(() => {
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
      showSaveFilePicker?: (options: {
        suggestedName?: string;
      }) => Promise<{ createWritable: () => Promise<WritableStream> }>;
    };
    const showSaveFilePicker = pickerWindow.showSaveFilePicker;

    if (showSaveFilePicker) {
      if (!response.body) {
        notification('error', 'Error', 'No data returned from download request.');
        return;
      }
      const handle = await showSaveFilePicker({ suggestedName: `${filename}` });
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
    notification(
      'error',
      'Error',
      `Failed to download the file. ${err instanceof Error ? err.message : String(err)}`,
    );
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
    notification(
      'error',
      'Error',
      `Failed to request to delete a logfile. ${err instanceof Error ? err.message : String(err)}.`,
    );
  }
};

const ucFirst = (str: string): string => (str ? str.charAt(0).toUpperCase() + str.slice(1) : str);

const copyMenuItems = computed(() => [
  [
    {
      label: 'Copy text',
      icon: 'i-lucide-message-square-text',
      onSelect: () => {
        copyText(
          rows.value
            .map(
              (row) =>
                `[${row.log.datetime}] ${row.log.level.toUpperCase()} [${row.log.logger}] ${row.log.message}`,
            )
            .join('\n'),
        );
      },
    },
    {
      label: 'Copy raw',
      icon: 'i-lucide-braces',
      onSelect: () => {
        copyText(rows.value.map((row) => JSON.stringify(row.log)).join('\n'));
      },
    },
  ],
]);
</script>
