<template>
  <div class="w-full min-w-0 max-w-full space-y-8">
    <PageHeader v-bind="pageShell" />

    <section aria-labelledby="recent-updates-heading" :aria-busy="historyLoading" class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 id="recent-updates-heading" class="text-lg font-semibold text-highlighted">
            Recent updates
          </h2>
          <p class="text-sm text-toned">
            Identity: <span class="font-medium text-default">{{ apiUser }}</span>
          </p>
        </div>
        <div class="flex items-center gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="historyLoading"
            @click="void loadContent()"
          >
            Refresh
          </UButton>
          <UButton
            to="/history"
            color="neutral"
            variant="ghost"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
          >
            View history
          </UButton>
        </div>
      </div>

      <UAlert
        v-if="historyError"
        role="alert"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="
          0 < lastHistory.length
            ? 'Recent updates could not be refreshed'
            : 'Recent updates could not be loaded'
        "
      >
        <template #description>
          <p class="wrap-break-word">{{ historyError }}</p>
          <p v-if="0 < lastHistory.length" class="mt-1 text-xs">
            These results may be out of date.
          </p>
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="historyLoading"
            @click="void loadContent()"
          >
            Retry
          </UButton>
        </template>
      </UAlert>

      <div
        v-if="historyLoading && 0 === lastHistory.length"
        role="status"
        aria-label="Loading recent updates"
        class="flex gap-4 overflow-hidden pb-3"
      >
        <div v-for="index in 6" :key="index" class="w-44 shrink-0 space-y-3 sm:w-48">
          <USkeleton v-if="posterEnable" class="aspect-2/3 w-full rounded-lg" />
          <USkeleton class="h-4 w-4/5" />
          <USkeleton class="h-3 w-3/5" />
        </div>
      </div>

      <UEmpty
        v-else-if="0 === lastHistory.length && !historyError"
        size="sm"
        variant="naked"
        icon="i-lucide-history"
        title="No recent updates"
        :description="`Import watch history from a backend to get started with ${apiUser}.`"
      />

      <RecentHistoryCards
        v-if="0 < lastHistory.length"
        :key="apiUser"
        :items="recentCards"
        :user="apiUser"
        :poster-enable="posterEnable"
        timestamp-label="Updated"
        show-state
        :attribution="recentAttribution"
        aria-label="Recently updated records"
      />
    </section>

    <section aria-labelledby="pending-work-heading" :aria-busy="statsLoading" class="ws-card">
      <div
        class="flex flex-wrap items-center justify-between gap-3 border-b border-default px-4 py-3 sm:px-5"
      >
        <h2 id="pending-work-heading" class="text-sm font-medium text-highlighted">Pending work</h2>
      </div>
      <div class="grid divide-y divide-default sm:grid-cols-2 sm:divide-x sm:divide-y-0">
        <NuxtLink
          to="/events"
          class="flex items-center justify-between gap-4 px-4 py-4 transition-colors hover:bg-elevated/60 sm:px-5"
        >
          <span class="flex items-center gap-3 text-sm text-default"
            ><UIcon name="i-lucide-list-todo" class="size-4 text-toned" />Queued events</span
          >
          <span
            v-if="null !== statsUpdatedAt"
            class="text-xl font-semibold tabular-nums text-highlighted"
            >{{ stats.events.pending }}</span
          >
          <span v-else class="text-xs text-toned">{{
            statsError ? 'Unavailable' : 'Loading…'
          }}</span>
        </NuxtLink>
        <NuxtLink
          to="/transport/queue"
          class="flex items-center justify-between gap-4 px-4 py-4 transition-colors hover:bg-elevated/60 sm:px-5"
        >
          <span class="flex items-center gap-3 text-sm text-default"
            ><UIcon name="i-lucide-send" class="size-4 text-toned" />Pending transport</span
          >
          <span
            v-if="null !== statsUpdatedAt"
            class="text-xl font-semibold tabular-nums text-highlighted"
            >{{ stats.transport.pending }}</span
          >
          <span v-else class="text-xs text-toned">{{
            statsError ? 'Unavailable' : 'Loading…'
          }}</span>
        </NuxtLink>
      </div>
      <UAlert
        v-if="statsError"
        role="alert"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
      >
        <template #description>
          {{
            null === statsUpdatedAt
              ? 'Pending counts are unavailable.'
              : 'Could not refresh. These counts may be out of date.'
          }}
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="xs"
            icon="i-lucide-refresh-cw"
            :loading="statsLoading"
            @click="void loadStats()"
            >Retry</UButton
          >
        </template>
      </UAlert>
    </section>

    <section aria-labelledby="recent-logs-heading" :aria-busy="reloadingLogs" class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 id="recent-logs-heading" class="text-lg font-semibold text-highlighted">
            Recent logs
          </h2>
          <p class="text-sm text-toned">Application, task &amp; access logs.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <UTooltip
            :text="
              autoReloadLogs
                ? 'Pause automatic refresh every 10 seconds'
                : 'Refresh automatically every 10 seconds'
            "
          >
            <UButton
              color="neutral"
              :variant="autoReloadLogs ? 'soft' : 'outline'"
              size="sm"
              :icon="autoReloadLogs ? 'i-lucide-pause' : 'i-lucide-play'"
              :aria-pressed="autoReloadLogs"
              @click="toggleLogsAutoReload()"
            >
              {{ autoReloadLogs ? 'Auto on' : 'Auto off' }}
            </UButton>
          </UTooltip>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="reloadingLogs"
            @click="void reloadLogs()"
            >Refresh</UButton
          >
          <UButton
            to="/logs"
            color="neutral"
            variant="ghost"
            size="sm"
            trailing-icon="i-lucide-arrow-right"
            >View logs</UButton
          >
        </div>
      </div>

      <UAlert
        v-if="logsError"
        role="alert"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="0 < logs.length ? 'Logs could not be refreshed' : 'Recent logs could not be loaded'"
      >
        <template #description>
          <p class="wrap-break-word">{{ logsError }}</p>
          <p v-if="0 < logs.length" class="mt-1 text-xs">Newer entries may be available.</p>
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="reloadingLogs"
            @click="void reloadLogs()"
            >Retry</UButton
          >
        </template>
      </UAlert>
      <UAlert
        v-if="0 === logs.length && (reloadingLogs || (!logsError && null === logsUpdatedAt))"
        color="neutral"
        variant="subtle"
        icon="i-lucide-loader-circle"
        title="Loading recent logs"
        :ui="{ icon: 'animate-spin' }"
      />
      <UEmpty
        v-else-if="0 === logs.length && !logsError"
        size="sm"
        variant="naked"
        icon="i-lucide-scroll-text"
        title="No recent logs"
      />

      <div class="space-y-4">
        <section
          v-for="(log, logIndex) in dashboardLogs"
          :key="log.filename"
          :aria-labelledby="`log-heading-${logIndex}`"
          class="overflow-hidden rounded-xl border border-default"
        >
          <div class="flex flex-wrap items-center justify-between gap-3 bg-elevated/30 px-4 py-3">
            <div class="min-w-0 space-y-1">
              <h3 :id="`log-heading-${logIndex}`">
                <button
                  type="button"
                  class="flex items-center gap-2 rounded text-left text-sm font-semibold text-highlighted focus-visible:outline-2 focus-visible:outline-primary"
                  :aria-expanded="isLogOpen(log.filename)"
                  :aria-controls="`log-entries-${logIndex}`"
                  @click="toggleLog(log.filename)"
                >
                  <UIcon :name="logTypeIcon(log.type)" class="size-4 text-toned" />
                  {{ ucFirst(log.type) }} log
                  <UIcon
                    name="i-lucide-chevron-down"
                    :class="[
                      'size-3.5 text-toned transition-transform',
                      isLogOpen(log.filename) ? '' : '-rotate-90',
                    ]"
                  />
                </button>
              </h3>
              <NuxtLink
                :to="`/logs/${log.filename}`"
                class="block truncate text-xs text-toned hover:text-primary"
              >
                {{ log.filename }}
              </NuxtLink>
            </div>
            <div class="flex items-center gap-2">
              <span class="hidden text-xs tabular-nums text-dimmed sm:inline"
                >{{ log.entries.length }} entries</span
              >
              <UButton
                icon="i-lucide-wrap-text"
                :variant="isLogWrapped(log.filename) ? 'soft' : 'outline'"
                color="neutral"
                size="xs"
                :aria-pressed="isLogWrapped(log.filename)"
                @click="toggleLogWrap(log.filename)"
              >
                Wrap
              </UButton>

              <UDropdownMenu
                :items="getCopyMenuItems(log)"
                :content="{ align: 'end' }"
                :modal="false"
              >
                <UButton
                  icon="i-lucide-copy"
                  color="neutral"
                  variant="outline"
                  size="xs"
                  trailing-icon="i-lucide-chevron-down"
                  >Copy</UButton
                >
              </UDropdownMenu>
            </div>
          </div>

          <div
            v-show="isLogOpen(log.filename)"
            :id="`log-entries-${logIndex}`"
            :ref="(el: unknown) => setLogContainerRef(log.filename, el as HTMLElement | null)"
            class="min-w-0 max-h-96 overflow-y-auto overflow-x-hidden border-t border-default"
            tabindex="0"
            :aria-label="`${ucFirst(log.type)} log entries`"
          >
            <UEmpty
              v-if="0 === log.entries.length"
              size="sm"
              variant="naked"
              icon="i-lucide-scroll-text"
              title="No entries in this log yet"
            />
            <article
              v-for="(entry, idx) in log.entries"
              :key="`${log.filename}-${idx}`"
              class="flex min-w-0 border-b border-default/50 last:border-b-0 hover:bg-elevated/30"
            >
              <div class="ws-log-line">
                <StructuredLogLine
                  :log="entry"
                  :show-details="true"
                  :wrapped="isLogWrapped(log.filename)"
                  :expanded="isExpandedLogRow(dashboardLogRowKey(log.filename, entry, idx))"
                  toggleable
                  @details="openLogDetails"
                  @open-event="(id) => (selectedEventId = id)"
                  @toggle-expand="
                    toggleExpandedLogRow(dashboardLogRowKey(log.filename, entry, idx))
                  "
                />
              </div>
            </article>
          </div>
        </section>
      </div>
    </section>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :key="selectedEventId"
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
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useId, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import {
  NuxtLink,
  UAlert,
  UButton,
  UDropdownMenu,
  UEmpty,
  UIcon,
  UModal,
  USkeleton,
  UTooltip,
} from '#components';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import PageHeader from '~/components/PageHeader.vue';
import RecentHistoryCards from '~/components/RecentHistoryCards.vue';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import useSystemStats from '~/composables/useSystemStats';
import {
  copyText,
  makeEventName,
  notification,
  parse_api_response,
  request,
  ucFirst,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type {
  HistoryItem,
  IndexLogFile,
  LogEntry,
  RecentHistoryCard,
  ServerJsonLogEntry,
} from '~/types';
import { normalizeStructuredEntry } from '~/utils/logs';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

useHead({ title: 'Overview' });

const route = useRoute();
const pageShell = requireTopLevelPageShell('home');
const apiUser = useStorage<string>('api_user', 'main');
const posterEnable = useStorage<boolean>('poster_enable', true);
const autoReloadLogs = useStorage<boolean>('auto_reload_logs', true);
const logWrap = useStorage<Record<string, boolean>>('index_logs_wrap', {});
const {
  stats,
  loading: statsLoading,
  error: statsError,
  lastUpdated: statsUpdatedAt,
  load: loadStats,
  start: startStats,
} = useSystemStats();

const lastHistory = shallowRef<Array<HistoryItem>>([]);
const recentCards = computed<Array<RecentHistoryCard>>(() =>
  lastHistory.value.map((item) => ({
    history_id: item.id,
    type: 'episode' === item.type ? 'episode' : 'movie',
    title: item.title || 'Untitled record',
    year: item.year ?? null,
    season: item.season ?? null,
    episode: item.episode ?? null,
    timestamp: item.updated_at || null,
    timestampTooltip: `Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT.value)}`,
    progress: Number(item.progress ?? 0),
    watched: item.watched,
  })),
);
const recentAttribution = computed<Record<number, string>>(() =>
  Object.fromEntries(
    lastHistory.value.filter((item) => item.via).map((item) => [item.id, item.via]),
  ),
);
const logs = ref<Array<IndexLogFile>>([]);
const dashboardLogs = computed(() =>
  logs.value.map((log) => ({ ...log, entries: normalizeDashboardEntries(log.lines) })),
);
const reloadingLogs = ref(false);
const logsError = ref('');
const logsUpdatedAt = ref<number | null>(null);
const historyLoading = ref(true);
const historyError = ref('');
const logReloadInterval = ref<ReturnType<typeof setInterval> | null>(null);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);
const expandedLogRows = ref<Set<string>>(new Set());
const logOpen = useStorage<Record<string, boolean>>('index_logs_open', {});
const logReloadFrequency = 10000;
let historyLoadToken = 0;
let logsLoadToken = 0;
const logContainers = new Map<string, HTMLElement>();

const eventActionsId = useId();
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

const normalizeDashboardEntries = (
  lines: Array<LogEntry | ServerJsonLogEntry>,
): Array<ServerJsonLogEntry> =>
  lines
    .map((item) => normalizeStructuredEntry(item))
    .filter((item): item is ServerJsonLogEntry => null !== item);

const dashboardLogRowKey = (filename: string, entry: ServerJsonLogEntry, index: number): string =>
  `${filename}:${entry.id}:${index}`;

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

const openLogDetails = (entry: ServerJsonLogEntry): void => {
  selectedLog.value = entry;
  detailsOpen.value = true;
};

const setLogContainerRef = (filename: string, el: HTMLElement | null): void => {
  if (null !== el) {
    logContainers.set(filename, el);
  } else {
    logContainers.delete(filename);
  }
};

const scrollLogContainerToBottom = (filename: string): void => {
  void nextTick(() => {
    const el = logContainers.get(filename);
    if (el) {
      el.scrollTop = el.scrollHeight;
    }
  });
};

const isLogOpen = (filename: string): boolean => logOpen.value[filename] ?? true;
const isLogWrapped = (filename: string): boolean => logWrap.value[filename] ?? false;
const toggleLogWrap = (filename: string): void => {
  logWrap.value = { ...logWrap.value, [filename]: !isLogWrapped(filename) };
};

const toggleLog = (filename: string): void => {
  const wasOpen = isLogOpen(filename);
  logOpen.value[filename] = !wasOpen;

  if (false === wasOpen) {
    scrollLogContainerToBottom(filename);
  }
};

const getCopyMenuItems = (log: IndexLogFile) => [
  [
    {
      label: 'Copy text',
      icon: 'i-lucide-message-square-text',
      onSelect: () => {
        copyText(
          normalizeDashboardEntries(log.lines)
            .map((e) => `[${e.datetime}] ${e.level.toUpperCase()} [${e.logger}] ${e.message}`)
            .join('\n'),
        );
      },
    },
    {
      label: 'Copy raw',
      icon: 'i-lucide-braces',
      onSelect: () => {
        copyText(log.lines.map((l) => ('string' === typeof l ? l : JSON.stringify(l))).join('\n'));
      },
    },
  ],
];

const logTypeIcon = (type: string): string => {
  switch (type) {
    case 'access':
      return 'i-lucide-key-round';
    case 'task':
      return 'i-lucide-list-checks';
    case 'app':
      return 'i-lucide-bug';
    default:
      return 'i-lucide-book-open';
  }
};

const loadContent = async (): Promise<void> => {
  const loadToken = ++historyLoadToken;
  const selectedUser = apiUser.value;
  historyLoading.value = true;

  try {
    const response = await request('/history?perpage=8', {
      headers: { 'X-User': selectedUser },
    });

    const historyResponse = await parse_api_response<{ history: Array<HistoryItem> }>(response);

    if (
      'index' !== route.name ||
      loadToken !== historyLoadToken ||
      selectedUser !== apiUser.value
    ) {
      return;
    }

    if ('error' in historyResponse) {
      throw new Error(`${historyResponse.error.code}: ${historyResponse.error.message}`);
    }

    if (false === response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    lastHistory.value = historyResponse.history;
    historyError.value = '';
  } catch (cause: unknown) {
    if (
      'index' === route.name &&
      loadToken === historyLoadToken &&
      selectedUser === apiUser.value
    ) {
      historyError.value = cause instanceof Error ? cause.message : String(cause);
      notification('error', 'Recent updates', historyError.value);
    }
  } finally {
    if (loadToken === historyLoadToken && selectedUser === apiUser.value) {
      historyLoading.value = false;
    }
  }
};

const reloadLogs = async (): Promise<void> => {
  if (reloadingLogs.value) {
    return;
  }

  const loadToken = ++logsLoadToken;
  const selectedUser = apiUser.value;
  reloadingLogs.value = true;

  try {
    const response = await request('/logs/recent', { headers: { 'X-User': selectedUser } });
    const logsResponse = await parse_api_response<Array<IndexLogFile>>(response);

    if ('index' !== route.name || loadToken !== logsLoadToken || selectedUser !== apiUser.value) {
      return;
    }

    if ('error' in logsResponse) {
      throw new Error(`${logsResponse.error.code}: ${logsResponse.error.message}`);
    }

    if (false === response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    logs.value = logsResponse;
    logsError.value = '';
    logsUpdatedAt.value = Date.now();

    void nextTick(() => {
      if (loadToken !== logsLoadToken || selectedUser !== apiUser.value) {
        return;
      }

      for (const el of logContainers.values()) {
        el.scrollTop = el.scrollHeight;
      }
    });
  } catch (cause: unknown) {
    if ('index' === route.name && loadToken === logsLoadToken && selectedUser === apiUser.value) {
      const message = cause instanceof Error ? cause.message : String(cause);
      if (message !== logsError.value) {
        notification('error', 'Recent logs', message);
      }
      logsError.value = message;
    }
  } finally {
    if (loadToken === logsLoadToken && selectedUser === apiUser.value) {
      reloadingLogs.value = false;
    }
  }
};

const stopLogsAutoReload = (): void => {
  if (null === logReloadInterval.value) {
    return;
  }

  clearInterval(logReloadInterval.value);
  logReloadInterval.value = null;
};

const startLogsAutoReload = (): void => {
  if (false === autoReloadLogs.value || null !== logReloadInterval.value) {
    return;
  }

  logReloadInterval.value = setInterval(() => {
    void reloadLogs();
  }, logReloadFrequency);
};

const toggleLogsAutoReload = (): void => {
  autoReloadLogs.value = !autoReloadLogs.value;

  if (true === autoReloadLogs.value) {
    void reloadLogs();
    startLogsAutoReload();
    return;
  }

  stopLogsAutoReload();
};

onMounted(() => {
  startStats();
  void loadContent();
  void reloadLogs();
  startLogsAutoReload();
});

watch(autoReloadLogs, (value: boolean) => {
  if (true === value) {
    startLogsAutoReload();
    return;
  }

  stopLogsAutoReload();
});

watch(detailsOpen, (open) => {
  if (false === open) {
    selectedLog.value = null;
  }
});

watch(
  apiUser,
  () => {
    ++historyLoadToken;
    ++logsLoadToken;
    lastHistory.value = [];
    historyError.value = '';
    logs.value = [];
    logsError.value = '';
    logsUpdatedAt.value = null;
    reloadingLogs.value = false;
    expandedLogRows.value = new Set();
    logContainers.clear();
    selectedEventId.value = null;
    detailsOpen.value = false;
    selectedLog.value = null;
    void loadContent();
    void reloadLogs();
  },
  { flush: 'sync' },
);

onBeforeUnmount(() => {
  ++historyLoadToken;
  ++logsLoadToken;
  stopLogsAutoReload();
});
</script>
