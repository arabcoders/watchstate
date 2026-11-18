<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      :page-label="filename"
      description=""
      content-class="min-w-0 space-y-2 wrap-anywhere"
    >
      <template #icon>
        <UIcon
          :name="pageShell.icon"
          :class="[
            'size-5 transition-colors',
            isLoading
              ? 'text-info'
              : stream
                ? 'text-success motion-safe:animate-pulse'
                : 'text-toned',
          ]"
          role="img"
          :aria-hidden="false"
          :title="isLoading ? 'Loading log entries' : stream ? 'Live log stream' : 'Log file'"
          :aria-label="isLoading ? 'Loading log entries' : stream ? 'Live log stream' : 'Log file'"
        />
      </template>
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <NuxtLink to="/logs" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
      </template>
      <template #actions>
        <template v-if="!error">
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

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            @click="reloadLog"
          >
            Refresh
          </UButton>

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            @click="deleteFile"
          >
            Delete
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

    <section
      v-else
      aria-labelledby="log-entries-heading"
      :aria-busy="isLoading"
      class="ws-card overflow-hidden"
    >
      <div class="space-y-3 bg-elevated/30 px-3 py-3 sm:px-4">
        <div class="flex flex-wrap items-baseline gap-2">
          <h2 id="log-entries-heading" class="text-sm font-semibold text-highlighted">Entries</h2>
          <span
            v-if="hasResults && rows.length < data.length"
            class="text-xs tabular-nums text-toned"
          >
            {{ rows.length }} of {{ data.length }}
          </span>
          <span
            v-if="isLoading && hasResults"
            role="status"
            class="inline-flex items-center gap-1.5 text-xs text-toned"
          >
            <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />
            Loading entries
          </span>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex min-w-0 flex-wrap items-center gap-2">
            <UButton
              icon="i-lucide-filter"
              :variant="toggleFilter ? 'soft' : 'outline'"
              color="neutral"
              size="sm"
              :aria-pressed="toggleFilter"
              aria-controls="log-text-filter"
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
              aria-label="Filter log levels"
              class="w-44 shrink-0 sm:w-48"
              :ui="{ content: 'min-w-48' }"
            >
              <template #default>{{ levelFilterLabel }}</template>
            </USelect>
            <UButton
              color="neutral"
              :variant="searchForm ? 'soft' : 'outline'"
              size="sm"
              icon="i-lucide-search"
              :aria-expanded="searchForm"
              aria-controls="log-file-search"
              @click="searchForm = !searchForm"
            >
              Search file
            </UButton>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <UButton
              icon="i-lucide-wrap-text"
              :variant="wrapLines ? 'soft' : 'outline'"
              color="neutral"
              size="sm"
              :aria-pressed="wrapLines"
              @click="wrapLines = !wrapLines"
            >
              Wrap
            </UButton>
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
            <UButton
              v-if="!autoScroll"
              color="neutral"
              variant="ghost"
              size="sm"
              icon="i-lucide-arrow-down-to-line"
              @click="scrollToBottom"
            >
              Bottom
            </UButton>
          </div>
        </div>

        <UFormField v-if="toggleFilter" id="log-text-filter" label="Filter entries" name="filter">
          <UInput
            v-model.lazy="query"
            type="search"
            placeholder="Filter log entries"
            icon="i-lucide-filter"
            autofocus
            size="sm"
            class="w-full"
          />
        </UFormField>

        <form
          v-if="searchForm"
          id="log-file-search"
          class="grid items-end gap-3 border-t border-default pt-3 sm:grid-cols-[minmax(0,1fr)_6rem_auto]"
          @submit.prevent="void searchLog()"
        >
          <UFormField label="Search file" name="search">
            <UInput
              v-model="searchQuery"
              type="search"
              placeholder="Search entire file"
              icon="i-lucide-search"
              size="sm"
              class="w-full"
              :disabled="isLoading"
            />
          </UFormField>
          <UFormField label="Limit" name="limit">
            <UInput
              v-model.number="searchLimit"
              type="number"
              :min="1"
              :step="1"
              required
              size="sm"
              class="w-full"
              :disabled="isLoading"
            />
          </UFormField>
          <div class="flex flex-wrap items-center gap-2">
            <UButton
              color="primary"
              variant="solid"
              size="sm"
              icon="i-lucide-search"
              type="submit"
              :disabled="isLoading || '' === searchQuery.trim()"
              :loading="isLoading"
            >
              Search
            </UButton>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-x"
              type="button"
              :disabled="isLoading"
              @click="clearSearch"
            >
              Reset
            </UButton>
          </div>
        </form>

        <p v-if="activeSearch" class="wrap-anywhere text-xs text-toned">
          {{ data.length }} matches for “{{ activeSearch }}”
        </p>
      </div>

      <div
        ref="logContainer"
        class="min-w-0 overflow-y-auto overflow-x-hidden border-t border-default text-default [overflow-anchor:none]"
        :style="{ minHeight: '70vh', maxHeight: '70vh' }"
        role="region"
        tabindex="0"
        :aria-label="`Log entries in ${filename}`"
        @scroll.passive="handleScroll"
      >
        <div
          v-if="reachedEnd && 0 < data.length && !activeSearch"
          class="border-b border-default/40 px-3 py-2 text-center text-xs text-muted"
        >
          Beginning of file
        </div>

        <template v-if="0 < rows.length">
          <article
            v-for="entry in rows"
            :key="entry.key"
            :data-log-id="entry.key"
            class="ws-log-row"
            :class="{ 'bg-warning/10': entry.isMatch, 'bg-muted/30': entry.isContext }"
          >
            <div class="ws-log-line">
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

        <UAlert
          v-else-if="!hasResults"
          color="neutral"
          variant="subtle"
          icon="i-lucide-loader-circle"
          title="Loading logs"
          :ui="{ icon: 'animate-spin' }"
        />
        <UEmpty
          v-else
          size="sm"
          variant="naked"
          :icon="hasActiveFilter || activeSearch ? 'i-lucide-search' : 'i-lucide-scroll-text'"
          :title="emptyTitle"
          :description="emptyDescription"
        />
      </div>
    </section>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :id="selectedEventId"
          :actions-target="`#${eventActionsId}`"
          @open-event="(id) => (selectedEventId = id)"
        />
      </template>
      <template #footer>
        <div :id="eventActionsId" class="w-full" />
      </template>
    </UModal>

    <LogDetailsModal
      v-model="detailsOpen"
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import {
  NuxtLink,
  UAlert,
  UButton,
  UDropdownMenu,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  UModal,
  USelect,
} from '#components';
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
const searchQuery = ref('');
const searchLimit = ref(100);
const searchForm = ref(false);
const activeSearch = ref('');
const data = ref<Array<ServerJsonLogEntry>>([]);
const error = ref<string>('');
const wrapLines = useStorage('logs_wrap_lines', false);
const isDownloading = ref<boolean>(false);
const isLoading = ref<boolean>(false);
const hasResults = ref(false);
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
let disposed = false;

const isTodayLog = computed<boolean>(() => filename.includes(moment().format('YYYYMMDD')));

const eventActionsId = useId();
const eventViewModalUi = { content: 'max-w-5xl' };
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
      key: log.id,
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
        key: log.id,
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
  const knownIds = new Set(data.value.map((entry) => entry.id));
  const entries = items
    .map((item) => normalizeStructuredEntry(item))
    .filter((item): item is ServerJsonLogEntry => {
      if (null === item || knownIds.has(item.id)) {
        return false;
      }
      knownIds.add(item.id);
      return true;
    });
  if (entries.length < 1) {
    return;
  }
  data.value = prepend ? [...entries, ...data.value] : [...data.value, ...entries];
};

const loadContent = async (): Promise<void> => {
  if (disposed || isLoading.value || activeSearch.value || reachedEnd.value) {
    return;
  }

  try {
    isLoading.value = true;
    const response = await request(`/log/${filename}?offset=${offset.value}`);
    const json = await parse_api_response<LogResponse>(response);

    if (disposed || 'logs-filename' !== route.name) {
      return;
    }
    if (200 !== response.status) {
      error.value =
        'error' in json
          ? `${json.error.code}: ${json.error.message}`
          : `${response.status}: ${response.statusText}`;
      return;
    }
    if ('error' in json) {
      error.value = `${json.error.code}: ${json.error.message}`;
      return;
    }

    const container = logContainer.value;
    const containerTop = container?.getBoundingClientRect().top ?? 0;
    const anchor = Array.from(container?.querySelectorAll<HTMLElement>('[data-log-id]') ?? []).find(
      (element) => element.getBoundingClientRect().bottom > containerTop,
    );
    const anchorTop = anchor?.getBoundingClientRect().top ?? 0;

    if (0 < json.lines.length) {
      applyLogBatch(json.lines as Array<unknown>, true);
    }
    hasResults.value = true;

    offset.value = json.next ?? offset.value;
    if (null === json.next) {
      reachedEnd.value = true;
    }

    await nextTick();
    if (disposed || container !== logContainer.value) {
      return;
    }
    if (autoScroll.value) {
      scrollLogContainerToBottom('auto');
    } else if (container && anchor?.isConnected) {
      container.scrollTop += anchor.getBoundingClientRect().top - anchorTop;
    }
    watchLog();
  } catch (err) {
    if (!disposed) {
      error.value = err instanceof Error ? err.message : 'Unexpected error';
    }
  } finally {
    isLoading.value = false;
  }
};

const searchLog = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }
  const term = searchQuery.value.trim();
  if ('' === term) {
    await clearSearch();
    return;
  }
  closeStream();
  isLoading.value = true;
  try {
    const params = new URLSearchParams({ search: term, limit: String(searchLimit.value) });
    const response = await request(`/log/${encodeURIComponent(filename)}?${params}`);
    const json = await parse_api_response<LogResponse>(response);
    if ('error' in json) {
      notification('error', 'Search failed', json.error.message);
      return;
    }
    if (!response.ok) {
      notification('error', 'Search failed', 'Unable to search this log file.');
      return;
    }
    activeSearch.value = term;
    data.value = [];
    applyLogBatch(json.lines);
    hasResults.value = true;
    expandedLogRows.value = new Set();
    reachedEnd.value = true;
    autoScroll.value = true;
    await nextTick();
    scrollLogContainerToBottom('auto');
  } catch (err) {
    notification('error', 'Search failed', err instanceof Error ? err.message : 'Unexpected error');
  } finally {
    isLoading.value = false;
    watchLog();
  }
};

const clearSearch = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }
  searchQuery.value = '';
  searchLimit.value = 100;
  activeSearch.value = '';
  query.value = '';
  searchForm.value = false;
  toggleFilter.value = false;
  await reloadLog();
};

const reloadLog = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }
  if (activeSearch.value) {
    searchQuery.value = activeSearch.value;
    await searchLog();
    return;
  }
  closeStream();
  offset.value = 0;
  reachedEnd.value = false;
  data.value = [];
  hasResults.value = false;
  autoScroll.value = true;
  expandedLogRows.value = new Set();
  await loadContent();
};

const scrollLogContainerToBottom = (behavior: ScrollBehavior = 'auto'): void => {
  if (!logContainer.value) {
    return;
  }
  logContainer.value.scrollTo({ top: logContainer.value.scrollHeight, behavior });
};

const emptyTitle = computed(() => {
  if (activeSearch.value && 0 === data.value.length) {
    return 'No matching log entries';
  }
  if (hasActiveFilter.value) {
    return 'No logs match these filters';
  }
  return 'No log lines available';
});

const emptyDescription = computed(() => {
  if (activeSearch.value && 0 === data.value.length) {
    return 'Adjust your search or clear it to return to the log view.';
  }
  if (hasActiveFilter.value) {
    return 'Adjust the text or level filters to show log entries.';
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
  if (disposed || !logContainer.value || activeSearch.value || error.value) {
    return;
  }
  const container = logContainer.value;
  const nearBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50;
  const nearTop = container.scrollTop < 50;
  autoScroll.value = nearBottom;

  if (
    nearTop &&
    !isLoading.value &&
    !scrollTimeout &&
    !reachedEnd.value &&
    0 < selectedLevelSet.value.size
  ) {
    scrollTimeout = setTimeout(() => {
      scrollTimeout = null;
      if (
        container === logContainer.value &&
        50 > container.scrollTop &&
        0 < selectedLevelSet.value.size &&
        !isLoading.value &&
        !activeSearch.value &&
        !reachedEnd.value &&
        !error.value &&
        !disposed
      ) {
        autoScroll.value = false;
        void loadContent();
      }
    }, 300);
  }
};

watch(
  [isLoading, normalizedQuery, selectedLevelSet],
  () => {
    if (hasResults.value && !isLoading.value) {
      handleScroll();
    }
  },
  { flush: 'post' },
);

const scrollToBottom = (): void => {
  autoScroll.value = true;
  nextTick(() => {
    scrollLogContainerToBottom('smooth');
  });
};

onMounted(async () => {
  await loadContent();
});
onBeforeUnmount(() => {
  disposed = true;
  closeStream();
  if (scrollTimeout) {
    clearTimeout(scrollTimeout);
    scrollTimeout = null;
  }
});

const watchLog = (): void => {
  if (
    disposed ||
    !isTodayLog.value ||
    stream.value ||
    activeSearch.value ||
    'logs-filename' !== route.name
  ) {
    return;
  }

  const controller = new AbortController();
  streamController.value = controller;
  stream.value = true;

  void fetchEventSource(`/v1/api/log/${filename}?stream=1`, {
    onmessage: async (evt) => {
      if (controller.signal.aborted || 'data' !== evt.event) {
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
      if (streamController.value === controller) {
        stream.value = false;
        streamController.value = null;
      }
    },
    headers: { Authorization: `Token ${token.value}` },
    signal: controller.signal,
  }).catch(() => {
    if (streamController.value === controller) {
      stream.value = false;
      streamController.value = null;
    }
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
    message: `Delete log file '${filename}'? This can't be undone.`,
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
      notification('success', 'Log file deleted', filename);
      await router.push('/logs');
      return;
    }
    if ('error' in json) {
      notification(
        'error',
        'Log file could not be deleted',
        `${json.error.code}: ${json.error.message}`,
      );
      return;
    }
    notification('error', 'Log file could not be deleted', 'Refresh the file and try again.');
  } catch (err) {
    notification(
      'error',
      'Log file could not be deleted',
      err instanceof Error ? err.message : String(err),
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
