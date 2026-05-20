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
        <UCard
          v-for="log in logs"
          :key="log.filename"
          class="border border-default/70 shadow-sm"
          :ui="logCardUi"
        >
          <template #header>
            <div class="space-y-2">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <div class="flex min-w-0 items-center gap-3">
                    <span
                      class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/60 text-toned"
                    >
                      <UIcon
                        :name="logTypeIcon(log.type)"
                        :class="reloadingLogs ? 'animate-spin' : ''"
                      />
                    </span>

                    <div class="min-w-0">
                      <NuxtLink
                        :to="`/logs/${log.filename}`"
                        class="block truncate text-base font-semibold text-highlighted hover:text-primary"
                      >
                        {{ ucFirst(log.type) }} Logs
                      </NuxtLink>
                    </div>
                  </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                  <UTooltip :text="autoReloadLogs ? 'Disable auto reload' : 'Enable auto reload'">
                    <UButton
                      color="neutral"
                      :variant="autoReloadLogs ? 'soft' : 'outline'"
                      size="sm"
                      :icon="autoReloadLogs ? 'i-lucide-pause' : 'i-lucide-play'"
                      :aria-label="autoReloadLogs ? 'Disable auto reload' : 'Enable auto reload'"
                      @click="toggleLogsAutoReload()"
                    >
                      <span class="hidden sm:inline">Auto Reload</span>
                    </UButton>
                  </UTooltip>

                  <UTooltip text="Toggle wrap line">
                    <UButton
                      color="neutral"
                      :variant="wrapLines ? 'soft' : 'outline'"
                      size="sm"
                      icon="i-lucide-wrap-text"
                      aria-label="Toggle wrap lines"
                      @click="wrapLines = !wrapLines"
                    >
                      <span class="hidden sm:inline">Wrap</span>
                    </UButton>
                  </UTooltip>

                  <UTooltip text="Fetch latest log entries.">
                    <UButton
                      color="neutral"
                      variant="outline"
                      size="sm"
                      icon="i-lucide-refresh-cw"
                      :loading="reloadingLogs"
                      aria-label="Fetch latest log entries"
                      @click="void reloadLogs()"
                    >
                      <span class="hidden sm:inline">Reload</span>
                    </UButton>
                  </UTooltip>
                </div>
              </div>

              <p class="text-sm text-toned">Recent log stream from {{ log.filename }}</p>
            </div>
          </template>

          <div class="space-y-3">
            <div
              class="overflow-auto border border-default bg-elevated/30 shadow-sm"
              :style="miniLogStyle"
              data-dashboard-log-scroll="1"
            >
              <template v-if="log.entries.length > 0">
                <article
                  v-for="(item, index) in log.entries"
                  :key="`${log.filename}-${index}-${item.id}`"
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
                        <UTooltip :text="lineTitle(item)">
                          <span class="inline cursor-pointer text-[11px] font-semibold text-toned">
                            {{ timestampLabel(item) }}
                          </span>
                        </UTooltip>

                        <LogDetailsChip
                          :item="item"
                          :open-details="openLogDetails"
                          :open-event="openEvent"
                        />

                        <span
                          :class="logLevelBadgeClass(getLogLevel(item.level))"
                          @click="openLogDetails(item)"
                        >
                          <UIcon :name="LOG_LEVEL_ICON[getLogLevel(item.level)]" class="size-3" />
                          {{ getLogLevel(item.level) }}
                        </span>

                        <span
                          v-if="item.logger"
                          :title="item.logger"
                          class="inline-block max-w-[46vw] truncate align-middle text-[11px] font-semibold text-toned sm:max-w-104"
                        >
                          [{{ item.logger }}]
                        </span>
                      </span>

                      <span class="ml-2">{{ logMessage(item) }}</span>

                      <span v-if="item.exception_message" class="ml-1 text-error/90">
                        : {{ item.exception_message }}
                      </span>
                    </p>
                  </div>
                </article>
              </template>

              <div
                v-else
                class="flex min-h-28 items-center justify-center px-6 py-8 text-sm text-toned"
              >
                No log lines available.
              </div>
            </div>
          </div>
        </UCard>
      </div>
    </section>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView v-if="selectedEventId" :id="selectedEventId" />
      </template>
    </UModal>

    <LogDetailsModal :log="selectedLog" v-model:open="detailsOpen" />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, onUpdated, ref, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useBreakpoints, useStorage } from '@vueuse/core';
import { NuxtLink } from '#components';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import FloatingImage from '~/components/FloatingImage.vue';
import LogDetailsChip from '~/components/LogDetailsChip.vue';
import LogDetailsModal from '~/components/LogDetailsModal.vue';
import Popover from '~/components/Popover.vue';
import DuplicateRecordList from '~/components/DuplicateRecordList.vue';
import {
  formatDuration,
  makeEventName,
  makeName,
  parse_api_response,
  request,
  ucFirst,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import {
  getLogLevel,
  LOG_LEVEL_ICON,
  logLevelBadgeClass,
  logMessageText,
  logTimestampLabel,
  logTimestampTitle,
  parseLogLines,
} from '~/utils/logs';
import type { HistoryItem, JsonObject, LogEntry, RecentLogFile } from '~/types';

type IndexLogFile = RecentLogFile & {
  entries: Array<LogEntry>;
};

useHead({ title: 'Index' });

const route = useRoute();
const poster_enable = useStorage('poster_enable', true);
const autoReloadLogs = useStorage<boolean>('auto_reload_logs', true);
const wrapLines = useStorage('logs_wrap_lines', false);
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });

const lastHistory = ref<Array<HistoryItem>>([]);
const logs = ref<Array<IndexLogFile>>([]);
const reloadingLogs = ref<boolean>(false);
const historyLoading = ref<boolean>(true);
const logReloadInterval = ref<ReturnType<typeof setInterval> | null>(null);
const selectedEventId = ref<string | null>(null);
const selectedLog = ref<LogEntry | null>(null);
const logReloadFrequency = 10000;
let historyLoadToken = 0;

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

const openEvent = (id: string): void => {
  selectedEventId.value = id;
};

const openLogDetails = (item: LogEntry): void => {
  selectedLog.value = item;
};

const lineTitle = (item: LogEntry): string => logTimestampTitle(item.datetime ?? item.date);

const timestampLabel = (item: LogEntry): string => logTimestampLabel(item.datetime ?? item.date);

const logMessage = (item: LogEntry): string => logMessageText(item);

const structuredLineClass = computed<Array<string>>(() => [
  'flex-1',
  wrapLines.value ? 'min-w-0 whitespace-pre-wrap wrap-break-word' : 'min-w-max whitespace-pre',
  'text-default',
]);

const miniLogStyle = {
  minHeight: '12rem',
  maxHeight: '26rem',
} as const;

const structuredRowClass = (index: number): Array<string> => {
  const classes = [
    'flex min-w-0 border-b border-default/40 bg-transparent transition-colors duration-150 last:border-b-0 hover:bg-elevated/70',
  ];

  if (index % 2 === 1) {
    classes.push('bg-elevated/40');
  }

  return classes;
};

const duplicatePopoverTrigger = computed<'click' | 'hover'>(() =>
  'mobile' === breakpoints.active().value ? 'click' : 'hover',
);

const historyCardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
  footer: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
};

const logCardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
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

    const logsResponse = await parse_api_response<Array<RecentLogFile>>(response);
    if ('error' in logsResponse || 'index' !== route.name) {
      return;
    }

    logs.value = logsResponse.map((item) => ({
      ...item,
      entries: parseLogLines(item.lines),
    }));
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

onUpdated(() => {
  document.querySelectorAll('[data-dashboard-log-scroll="1"]').forEach((element) => {
    element.scrollTop = element.scrollHeight;
  });
});

watch(autoReloadLogs, (value: boolean) => {
  if (true === value) {
    startLogsAutoReload();
    return;
  }

  stopLogsAutoReload();
});

onBeforeUnmount(() => stopLogsAutoReload());
</script>
