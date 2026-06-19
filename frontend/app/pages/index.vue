<template>
  <div class="space-y-6">
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

      <div v-else class="grid gap-4 xl:grid-cols-2">
        <UCard
          v-for="item in lastHistory"
          :key="item.id"
          class="h-full border border-default/70 shadow-sm"
          :class="item.watched ? 'bg-default/90 ring-1 ring-success/20' : 'bg-default/90'"
          :ui="historyCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div
                  class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
                >
                  <UIcon
                    :name="'episode' === item.type ? 'i-lucide-tv' : 'i-lucide-film'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />

                  <div class="min-w-0 flex-1">
                    <FloatingImage
                      v-if="poster_enable"
                      :image="`/history/${item.id}/images/poster`"
                    >
                      <UTooltip
                        :text="String(item.full_title || makeName(item as unknown as JsonObject))"
                      >
                        <ULink
                          :to="`/history/${item.id}`"
                          class="block truncate text-highlighted hover:text-primary"
                        >
                          {{ item.full_title || makeName(item as unknown as JsonObject) }}
                        </ULink>
                      </UTooltip>
                    </FloatingImage>

                    <UTooltip
                      v-else
                      :text="String(item.full_title || makeName(item as unknown as JsonObject))"
                    >
                      <ULink
                        :to="`/history/${item.id}`"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item.full_title || makeName(item as unknown as JsonObject) }}
                      </ULink>
                    </UTooltip>
                  </div>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-2">
                <Popover
                  v-if="(item.duplicate_reference_ids?.length ?? 0) > 0"
                  placement="top"
                  :trigger="duplicatePopoverTrigger"
                  :show-delay="200"
                  :hide-delay="200"
                  :offset="8"
                  content-class="p-0"
                >
                  <template #trigger>
                    <UBadge color="warning" variant="soft" class="cursor-pointer font-medium">
                      <span class="inline-flex items-center gap-1">
                        <UIcon name="i-lucide-layers-3" class="size-3.5" />
                        <span>{{ item.duplicate_reference_ids?.length }}</span>
                      </span>
                    </UBadge>
                  </template>

                  <template #content>
                    <DuplicateRecordList :ids="item.duplicate_reference_ids ?? []" />
                  </template>
                </Popover>
              </div>
            </div>
          </template>

          <div
            :class="['grid grid-cols-2 gap-3', item.progress ? 'xl:grid-cols-4' : 'xl:grid-cols-3']"
          >
            <div
              v-if="item.updated_at"
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
              <UTooltip
                :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
              >
                <span class="cursor-help">{{ moment.unix(item.updated_at).fromNow() }}</span>
              </UTooltip>
            </div>

            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-server" class="size-4 shrink-0 text-toned" />
              <div>
                <NuxtLink :to="`/backend/${item.via}`" class="hover:text-primary">{{
                  item.via
                }}</NuxtLink>
                <UTooltip
                  v-if="item.metadata && Object.keys(item.metadata).length > 1"
                  :text="`Also reported by: ${Object.keys(item.metadata)
                    .filter((backend) => backend !== item.via)
                    .join(', ')}.`"
                >
                  <span class="ml-1 cursor-help text-toned">
                    (+{{ Object.keys(item.metadata).length - 1 }})
                  </span>
                </UTooltip>
              </div>
            </div>

            <div
              :class="[
                'flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default',
                item.updated_at && !item.progress ? 'col-span-2 xl:col-span-1' : '',
              ]"
            >
              <UIcon name="i-lucide-mail" class="size-4 shrink-0 text-toned" />
              <span>{{ item.event }}</span>
            </div>

            <div
              v-if="item.progress"
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-gauge" class="size-4 shrink-0 text-toned" />
              <span>{{ formatDuration(item.progress as number) }}</span>
            </div>
          </div>
        </UCard>
      </div>
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useBreakpoints, useStorage } from '@vueuse/core';
import { NuxtLink } from '#components';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import FloatingImage from '~/components/FloatingImage.vue';
import StructuredLogLine from '~/components/StructuredLogLine.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import Popover from '~/components/Popover.vue';
import DuplicateRecordList from '~/components/DuplicateRecordList.vue';
import {
  copyText,
  formatDuration,
  makeEventName,
  makeName,
  parse_api_response,
  request,
  ucFirst,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type { HistoryItem, JsonObject, LogEntry, ServerJsonLogEntry } from '~/types';
import { normalizeStructuredEntry } from '~/utils/logs';

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
const poster_enable = useStorage('poster_enable', true);
const autoReloadLogs = useStorage<boolean>('auto_reload_logs', true);
const logWrap = useStorage<Record<string, boolean>>('index_logs_wrap', {});
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });

const lastHistory = ref<Array<HistoryItem>>([]);
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

const duplicatePopoverTrigger = computed<'click' | 'hover'>(() =>
  'mobile' === breakpoints.active().value ? 'click' : 'hover',
);

const historyCardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
  footer: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
};

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

const checkDuplicates = async (
  historyItems: Array<HistoryItem>,
  loadToken: number,
): Promise<void> => {
  await Promise.allSettled(
    historyItems.map(async (item) => {
      if ((item.duplicate_reference_ids?.length ?? 0) > 0) {
        return;
      }

      const duplicatesResponse = await request(`/history/${item.id}/duplicates`);
      if (!duplicatesResponse.ok) {
        return;
      }

      const duplicatePayload = await parse_api_response<{
        duplicate_reference_ids: Array<number>;
      }>(duplicatesResponse);

      if ('error' in duplicatePayload || 'index' !== route.name || loadToken !== historyLoadToken) {
        return;
      }

      for (const entry of lastHistory.value) {
        if (entry.id !== item.id) {
          continue;
        }

        entry.duplicate_reference_ids = duplicatePayload.duplicate_reference_ids;
        break;
      }
    }),
  );
};

const loadContent = async (): Promise<void> => {
  const loadToken = ++historyLoadToken;

  try {
    const response = await request('/history?perpage=4');
    if (!response.ok) {
      return;
    }

    const historyResponse = await parse_api_response<{
      history: Array<HistoryItem>;
      total: number;
      page: number;
      perpage: number;
    }>(response);

    if ('error' in historyResponse || 'index' !== route.name) {
      return;
    }

    const historyItems = historyResponse.history;

    for (const item of historyItems) {
      item.duplicate_reference_ids = item.duplicate_reference_ids ?? [];
    }

    lastHistory.value = historyItems;

    historyLoading.value = false;
    await nextTick();

    window.setTimeout(() => {
      if ('index' !== route.name || loadToken !== historyLoadToken) {
        return;
      }

      void checkDuplicates(historyItems, loadToken);
    }, 0);
  } catch {
  } finally {
    historyLoading.value = false;
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

onBeforeUnmount(() => stopLogsAutoReload());
</script>
