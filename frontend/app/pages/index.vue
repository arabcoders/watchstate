<template>
  <div class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" />

    <section class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <div
            class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
          >
            <UIcon name="i-lucide-history" class="size-4" />
            <span>Recent History</span>
          </div>
        </div>
      </div>

      <UAlert
        v-if="historyLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading history"
        description="Loading history. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="0 === lastHistory.length"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="No history records"
        description="The database has no recent history records yet."
      />

      <RecentHistoryCards
        v-else
        :key="apiUser"
        :items="recentCards"
        :user="apiUser"
        :poster-enable="posterEnable"
        aria-label="Recent history titles"
      />
    </section>

    <section v-if="logs.length > 0" class="space-y-4">
      <div class="space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon name="i-lucide-scroll-text" class="size-4" />
          <span>Recent Logs</span>
        </div>
      </div>

      <div class="space-y-4">
        <section v-for="log in logs" :key="log.filename" class="space-y-3">
          <button
            type="button"
            class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
            @click="toggleLog(log.filename)"
          >
            <div class="flex items-center gap-3">
              <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
              >
                <UIcon
                  :name="logTypeIcon(log.type)"
                  :class="reloadingLogs ? 'animate-spin' : ''"
                  class="size-4"
                />
              </span>
              <div>
                <p class="text-base font-semibold text-highlighted">{{ ucFirst(log.type) }} Logs</p>
                <NuxtLink
                  :to="`/logs/${log.filename}`"
                  class="text-sm text-toned hover:text-primary"
                  @click.stop
                >
                  {{ log.filename }}
                </NuxtLink>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <UTooltip :text="autoReloadLogs ? 'Disable auto reload' : 'Enable auto reload'">
                <UButton
                  color="neutral"
                  :variant="autoReloadLogs ? 'soft' : 'outline'"
                  size="xs"
                  :icon="autoReloadLogs ? 'i-lucide-pause' : 'i-lucide-play'"
                  @click.stop="toggleLogsAutoReload()"
                >
                  Auto
                </UButton>
              </UTooltip>

              <UButton
                icon="i-lucide-wrap-text"
                :variant="isLogWrapped(log.filename) ? 'soft' : 'outline'"
                color="neutral"
                size="xs"
                @click.stop="toggleLogWrap(log.filename)"
              >
                Wrap
              </UButton>

              <span @click.stop>
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
                  >
                    Copy
                  </UButton>
                </UDropdownMenu>
              </span>

              <UButton
                icon="i-lucide-refresh-cw"
                color="neutral"
                variant="outline"
                size="xs"
                :loading="reloadingLogs"
                @click.stop="void reloadLogs()"
              >
                Refresh
              </UButton>

              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  isLogOpen(log.filename) ? 'rotate-90' : '',
                ]"
              />
            </div>
          </button>

          <div
            v-if="isLogOpen(log.filename)"
            :ref="(el: unknown) => setLogContainerRef(log.filename, el as HTMLElement | null)"
            class="min-w-0 max-h-[35vh] overflow-y-auto overflow-x-hidden rounded-lg border border-default/70 bg-elevated/40 shadow-sm sm:max-h-[20vh]"
          >
            <article
              v-for="(entry, idx) in normalizeDashboardEntries(log.lines)"
              :key="`${log.filename}-${idx}`"
              :class="[
                'flex min-w-0 border-b border-default/40 bg-transparent last:border-b-0 hover:bg-elevated/70',
                1 === idx % 2 ? 'bg-elevated/40' : '',
              ]"
            >
              <div
                class="flex w-full min-w-0 flex-col gap-1 px-3 py-[0.65rem] leading-[1.6] md:flex-row md:items-start md:gap-2"
              >
                <StructuredLogLine
                  :log="entry"
                  :compact="true"
                  :show-details="true"
                  :wrapped="isLogWrapped(log.filename)"
                  :expanded="isExpandedLogRow(dashboardLogRowKey(log.filename, entry, idx))"
                  toggleable
                  @details="openLogDetails"
                  @open-event="openEventFromLog"
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
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import { NuxtLink, UAlert, UButton, UDropdownMenu, UIcon, UModal, UTooltip } from '#components';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import PageHeader from '~/components/PageHeader.vue';
import RecentHistoryCards from '~/components/RecentHistoryCards.vue';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import {
  copyText,
  makeEventName,
  notification,
  parse_api_response,
  request,
  ucFirst,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type { HistoryItem, LogEntry, RecentHistoryCard, ServerJsonLogEntry } from '~/types';
import { normalizeStructuredEntry } from '~/utils/logs';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

type IndexLogFile = {
  type: string;
  filename: string;
  date: number;
  size: number;
  modified: string;
  lines: Array<LogEntry | ServerJsonLogEntry>;
};

useHead({ title: 'Index' });

const route = useRoute();
const pageShell = requireTopLevelPageShell('home');
const apiUser = useStorage<string>('api_user', 'main');
const posterEnable = useStorage<boolean>('poster_enable', true);
const autoReloadLogs = useStorage<boolean>('auto_reload_logs', true);
const logWrap = useStorage<Record<string, boolean>>('index_logs_wrap', {});

const lastHistory = shallowRef<Array<HistoryItem>>([]);
const recentCards = computed<Array<RecentHistoryCard>>(() =>
  lastHistory.value.map((item) => ({
    history_id: item.id,
    type: 'episode' === item.type ? 'episode' : 'movie',
    title: item.title,
    year: item.year ?? null,
    season: item.season ?? null,
    episode: item.episode ?? null,
    timestamp: item.updated_at || null,
    timestampTooltip: `Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT.value)}`,
    progress: Number(item.progress ?? 0),
    watched: item.watched,
  })),
);
const logs = ref<Array<IndexLogFile>>([]);
const reloadingLogs = ref<boolean>(false);
const historyLoading = ref<boolean>(true);
const logReloadInterval = ref<ReturnType<typeof setInterval> | null>(null);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<ServerJsonLogEntry | null>(null);
const detailsOpen = ref(false);
const expandedLogRows = ref<Set<string>>(new Set());
const logOpen = useStorage<Record<string, boolean>>('index_logs_open', {});
const logReloadFrequency = 10000;
let historyLoadToken = 0;
const logContainers = ref<Record<string, HTMLElement>>({});

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

const openEventFromLog = (eventId: string): void => {
  selectedEventId.value = eventId;
};

const setLogContainerRef = (filename: string, el: HTMLElement | null): void => {
  if (null !== el) {
    logContainers.value[filename] = el;
  }
};

const scrollLogContainerToBottom = (filename: string): void => {
  void nextTick(() => {
    const el = logContainers.value[filename];
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
    const response = await request('/history?perpage=10', {
      headers: { 'X-User': selectedUser },
    });

    const historyResponse = await parse_api_response<{
      history: Array<HistoryItem>;
      total: number;
      page: number;
      perpage: number;
    }>(response);

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
  } catch (cause: unknown) {
    if (
      'index' === route.name &&
      loadToken === historyLoadToken &&
      selectedUser === apiUser.value
    ) {
      notification(
        'error',
        'Recent History',
        cause instanceof Error ? cause.message : String(cause),
      );
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

  try {
    reloadingLogs.value = true;

    const response = await request('/logs/recent');
    if (!response.ok) {
      return;
    }

    const logsResponse = await parse_api_response<Array<IndexLogFile>>(response);
    if ('error' in logsResponse || 'index' !== route.name) {
      return;
    }

    logs.value = logsResponse;

    void nextTick(() => {
      for (const filename of Object.keys(logContainers.value)) {
        const el = logContainers.value[filename];
        if (el) {
          el.scrollTop = el.scrollHeight;
        }
      }
    });
  } catch {
  } finally {
    reloadingLogs.value = false;
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

onMounted(async () => {
  await Promise.all([loadContent(), reloadLogs()]);
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
  if (!open) {
    selectedLog.value = null;
  }
});

watch(
  apiUser,
  () => {
    lastHistory.value = [];
    void loadContent();
  },
  { flush: 'sync' },
);

onBeforeUnmount(() => stopLogsAutoReload());
</script>
