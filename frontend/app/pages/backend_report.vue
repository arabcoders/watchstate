<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <div class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading || isQueueing"
            @click="void loadReport()"
          >
            Reload
          </UButton>

          <UDropdownMenu v-if="summary" :items="exportMenuItems">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-download"
              trailing-icon="i-lucide-chevron-down"
              :loading="null !== exporting"
              :disabled="null !== exporting"
            >
              Export
            </UButton>
          </UDropdownMenu>

          <UButton
            color="primary"
            variant="solid"
            icon="i-lucide-play"
            :loading="isQueueing"
            :disabled="isLoading || isQueueing || true === reportState?.queued"
            @click="void queueReport()"
          >
            {{ reportState?.queued ? 'Queued' : report ? 'Queue refresh' : 'Generate report' }}
          </UButton>
        </template>
      </PageHeader>

      <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-toned">
        <div class="flex min-w-0 items-center gap-2">
          <dt>Identity:</dt>
          <dd class="wrap-break-word font-medium text-default">{{ apiUser }}</dd>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <dt>Last completed:</dt>
          <dd>
            <UTooltip v-if="report?.completed_at" :text="relativeDate(report.completed_at)">
              <time :datetime="moment.unix(report.completed_at).toISOString()" class="cursor-help">
                {{ formatDate(report.completed_at) }}
              </time>
            </UTooltip>
            <span v-else>{{ report ? 'Unknown' : 'No report loaded' }}</span>
          </dd>
        </div>
      </dl>
    </div>

    <UAlert
      v-if="reportState?.queued"
      color="info"
      variant="soft"
      icon="i-lucide-clock-3"
      title="Report queued"
      description="Reload to check for a completed report."
    />

    <UEmpty
      v-if="
        false === isLoading &&
        !loadError &&
        reportState &&
        !reportState.queued &&
        null === reportState.report
      "
      icon="i-lucide-chart-no-axes-column"
      title="No report found"
      description="Generate a report for this identity to collect local media statistics."
    />

    <UAlert
      v-else-if="false === isLoading && reportState?.report && null === summary"
      color="warning"
      variant="soft"
      icon="i-lucide-user-round-x"
      title="Identity not included"
      description="Generate a report for the selected identity."
    />

    <UAlert
      v-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Couldn't load backend report"
      :description="loadError"
    />

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading backend report"
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-if="report && summary">
      <section class="space-y-3">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-highlighted">
          <UIcon name="i-lucide-chart-no-axes-column" class="size-4 text-toned" />
          Summary
        </h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
          <StatCard
            v-for="card in summaryCards"
            :key="card.label"
            :label="card.label"
            :value="card.value"
            :icon="card.icon"
            :color="card.color"
          />
        </div>
      </section>

      <section v-for="[backend, backendSummary] in backendEntries" :key="backend" class="space-y-3">
        <button
          type="button"
          class="ws-panel-padded flex w-full flex-wrap items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen(`backend-${backend}`)"
          @click="toggleSection(`backend-${backend}`)"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-database" class="size-4 text-toned" />
            <span>{{ backend }}</span>
            <span v-if="false === backendSummary.configured" class="text-xs font-normal text-toned">
              Not configured
            </span>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
              <span class="text-toned">
                Watched
                <strong class="font-semibold text-success">
                  {{ formatNumber(backendSummary.watched) }}
                </strong>
              </span>
              <span class="text-toned">
                Unwatched
                <strong class="font-semibold text-highlighted">
                  {{ formatNumber(backendSummary.unwatched) }}
                </strong>
              </span>
              <span class="text-toned">
                In progress
                <strong class="font-semibold text-info">
                  {{ formatNumber(backendSummary.in_progress) }}
                </strong>
              </span>
              <span class="text-toned">
                Total
                <strong class="font-semibold text-highlighted">
                  {{ formatNumber(backendSummary.total) }}
                </strong>
              </span>
            </div>
            <UIcon
              name="i-lucide-chevron-right"
              :class="[
                'size-4 shrink-0 text-toned transition-transform',
                isSectionOpen(`backend-${backend}`) ? 'rotate-90' : '',
              ]"
            />
          </div>
        </button>

        <UEmpty
          v-if="isSectionOpen(`backend-${backend}`) && 0 === backendSummary.libraries.length"
          size="sm"
          variant="naked"
          icon="i-lucide-library"
          title="No linked local records"
        />

        <div
          v-else-if="isSectionOpen(`backend-${backend}`)"
          class="ws-card overflow-x-auto shadow-sm"
        >
          <table class="w-full min-w-170 table-fixed text-left text-sm rounded-none">
            <colgroup>
              <col class="w-[28%]" />
              <col class="w-[12%]" />
              <col class="w-[15%]" />
              <col class="w-[15%]" />
              <col class="w-[15%]" />
              <col class="w-[15%]" />
            </colgroup>
            <thead class="border-b border-default text-xs uppercase tracking-wide text-toned">
              <tr>
                <th class="px-3 py-2 font-semibold">Library</th>
                <th class="px-3 py-2 font-semibold">Type</th>
                <th class="px-3 py-2 text-right font-semibold">Watched</th>
                <th class="px-3 py-2 text-right font-semibold">Unwatched</th>
                <th class="px-3 py-2 text-right font-semibold">In progress</th>
                <th class="px-3 py-2 text-right font-semibold">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-default">
              <tr
                v-for="librarySummary in backendSummary.libraries"
                :key="librarySummary.id"
                class="transition-colors duration-150 hover:bg-elevated/70"
              >
                <td class="px-3 py-2 font-medium text-highlighted">
                  <UTooltip
                    v-if="'unknown' !== librarySummary.id"
                    :text="`Library ID: ${librarySummary.id}`"
                  >
                    <span class="cursor-help">{{ librarySummary.title ?? librarySummary.id }}</span>
                  </UTooltip>
                  <span v-else>Unknown (No library id)</span>
                </td>
                <td class="px-3 py-2 text-toned">{{ libraryTypeLabel(librarySummary.type) }}</td>
                <td class="px-3 py-2 text-right text-success">
                  {{ formatNumber(librarySummary.watched) }}
                </td>
                <td class="px-3 py-2 text-right">{{ formatNumber(librarySummary.unwatched) }}</td>
                <td class="px-3 py-2 text-right text-info">
                  {{ formatNumber(librarySummary.in_progress) }}
                </td>
                <td class="px-3 py-2 text-right">{{ formatNumber(librarySummary.total) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t border-default bg-elevated/40 font-semibold text-highlighted">
              <tr>
                <td class="px-3 py-2">Total</td>
                <td class="px-3 py-2"></td>
                <td class="px-3 py-2 text-right text-success">
                  {{ formatNumber(backendSummary.watched) }}
                </td>
                <td class="px-3 py-2 text-right">{{ formatNumber(backendSummary.unwatched) }}</td>
                <td class="px-3 py-2 text-right text-info">
                  {{ formatNumber(backendSummary.in_progress) }}
                </td>
                <td class="px-3 py-2 text-right">{{ formatNumber(backendSummary.total) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <section v-if="0 < originEntries.length" class="space-y-3">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          :aria-expanded="isSectionOpen('origins')"
          @click="toggleSection('origins')"
        >
          <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-git-branch" class="size-4 text-toned" />
            <span>Record origins ({{ originEntries.length }})</span>
          </div>
          <UIcon
            name="i-lucide-chevron-right"
            :class="[
              'size-4 text-toned transition-transform',
              isSectionOpen('origins') ? 'rotate-90' : '',
            ]"
          />
        </button>

        <div v-if="isSectionOpen('origins')" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <StatCard
            v-for="[origin, counts] in originEntries"
            :key="origin"
            :label="origin"
            :value="`${formatNumber(counts.total)} records`"
            :hint="`${formatNumber(counts.watched)} watched`"
            icon="i-lucide-git-branch"
            color="neutral"
          />
        </div>
      </section>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHead } from '#app';
import { UAlert, UButton, UDropdownMenu, UEmpty, UIcon, UTooltip } from '#components';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import StatCard from '~/components/StatCard.vue';
import type {
  BackendReportBackendSummary,
  BackendReportResponse,
  BackendReportRunResponse,
} from '~/types';
import { notification, parse_api_response, request, ucFirst } from '~/utils';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

const pageShell = requireTopLevelPageShell('backend-report');
const apiUser = useStorage<string>('api_user', 'main');
useHead(() => ({ title: `${ucFirst(apiUser.value)} @ Backend Report` }));
const reportState = ref<BackendReportResponse | null>(null);
const isLoading = ref(false);
const loadError = ref('');
const isQueueing = ref(false);
const exporting = ref<'markdown' | 'json' | 'csv' | null>(null);
const collapsedSections = ref<Record<string, boolean>>({ origins: true });
let identityVersion = 0;
let loadRequest = 0;
const report = computed(() => {
  const completedReport = reportState.value?.report;
  return apiUser.value === completedReport?.identity ? completedReport : null;
});
const summary = computed(() => report.value?.summary ?? null);
const backendEntries = computed<Array<[string, BackendReportBackendSummary]>>(() =>
  Object.entries(summary.value?.backends ?? {}),
);
const originEntries = computed(() => Object.entries(summary.value?.origins ?? {}));
const formatNumber = (value: number): string => new Intl.NumberFormat().format(value);
const libraryTypeLabel = (type: 'movie' | 'show' | 'mixed'): string => {
  if ('movie' === type) {
    return 'Movies';
  }
  if ('show' === type) {
    return 'Shows';
  }
  return 'Movies & Shows';
};
const isSectionOpen = (id: string): boolean => !collapsedSections.value[id];
const toggleSection = (id: string): void => {
  collapsedSections.value[id] = !collapsedSections.value[id];
};

type SummaryCard = {
  label: string;
  value: string;
  icon: string;
  color: 'success' | 'info' | 'neutral';
};

const summaryCards = computed<Array<SummaryCard>>(() => [
  {
    label: 'Total',
    value: formatNumber(summary.value?.total ?? 0),
    icon: 'i-lucide-database',
    color: 'neutral',
  },
  {
    label: 'Movies',
    value: formatNumber(summary.value?.types.movie.total ?? 0),
    icon: 'i-lucide-film',
    color: 'neutral',
  },
  {
    label: 'Episodes',
    value: formatNumber(summary.value?.types.episode.total ?? 0),
    icon: 'i-lucide-clapperboard',
    color: 'neutral',
  },
  {
    label: 'Watched',
    value: formatNumber(summary.value?.watched ?? 0),
    icon: 'i-lucide-circle-check',
    color: 'success',
  },
  {
    label: 'Unwatched',
    value: formatNumber(summary.value?.unwatched ?? 0),
    icon: 'i-lucide-circle',
    color: 'neutral',
  },
  {
    label: 'In progress',
    value: formatNumber(summary.value?.in_progress ?? 0),
    icon: 'i-lucide-clock-3',
    color: 'info',
  },
]);

const formatDate = (value: number | null): string => {
  if (!value) {
    return 'Unknown';
  }

  return moment.unix(value).format('YYYY-MM-DD HH:mm:ss Z');
};

const relativeDate = (value: number | null): string => {
  if (!value) {
    return 'at an unknown time';
  }

  return moment.unix(value).fromNow();
};

const loadReport = async (): Promise<void> => {
  const requestId = ++loadRequest;
  const version = identityVersion;
  const identity = apiUser.value;
  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/state/backend-report', { headers: { 'X-User': identity } });
    const json = await parse_api_response<BackendReportResponse>(response);
    if (version !== identityVersion || requestId !== loadRequest) {
      return;
    }

    if ('error' in json) {
      loadError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Report not loaded', `${json.error.code}: ${json.error.message}`);
      return;
    }

    reportState.value = json;
  } catch (e: unknown) {
    if (version !== identityVersion || requestId !== loadRequest) {
      return;
    }

    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Report not loaded', loadError.value);
  } finally {
    if (version === identityVersion && requestId === loadRequest) {
      isLoading.value = false;
    }
  }
};

const queueReport = async (): Promise<void> => {
  if (isLoading.value || isQueueing.value || reportState.value?.queued) {
    return;
  }

  const version = identityVersion;
  const identity = apiUser.value;
  isQueueing.value = true;

  try {
    const response = await request('/state/backend-report/run', {
      method: 'POST',
      headers: { 'X-User': identity },
    });
    const json = await parse_api_response<BackendReportRunResponse>(response);
    if (version !== identityVersion) {
      return;
    }

    if (false === response.ok) {
      if ('error' in json) {
        notification('error', 'Report not queued', `${json.error.code}: ${json.error.message}`);
      } else {
        notification(
          'error',
          'Report not queued',
          `HTTP ${response.status}: ${response.statusText}`,
        );
      }
      return;
    }

    if ('error' in json) {
      notification('error', 'Report not queued', `${json.error.code}: ${json.error.message}`);
      return;
    }

    reportState.value = {
      report: reportState.value?.report ?? null,
      queued: true,
      queued_event: json.event_id,
    };
    notification(json.queued ? 'success' : 'info', 'Backend Report', json.message);
    await loadReport();
  } catch (e: unknown) {
    if (version === identityVersion) {
      const error = e instanceof Error ? e.message : 'Unknown error occurred';
      notification('error', 'Report not queued', error);
    }
  } finally {
    if (version === identityVersion) {
      isQueueing.value = false;
    }
  }
};

const exportReport = async (format: 'markdown' | 'json' | 'csv'): Promise<void> => {
  if (null !== exporting.value || !report.value) {
    return;
  }

  const version = identityVersion;
  const identity = apiUser.value;
  exporting.value = format;

  try {
    const response = await request(`/state/backend-report/export/${format}`, {
      headers: { 'X-User': identity },
    });
    if (version !== identityVersion) {
      return;
    }

    if (false === response.ok) {
      const json = await parse_api_response<BackendReportResponse>(response);
      if (version === identityVersion && 'error' in json) {
        notification('error', 'Report not exported', `${json.error.code}: ${json.error.message}`);
      }
      return;
    }

    const blob = await response.blob();
    if (version !== identityVersion) {
      return;
    }

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `backend-report-${format}.${'markdown' === format ? 'md' : format}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (e: unknown) {
    if (version === identityVersion) {
      const error = e instanceof Error ? e.message : 'Unknown error occurred';
      notification('error', 'Report not exported', error);
    }
  } finally {
    if (version === identityVersion) {
      exporting.value = null;
    }
  }
};

const exportMenuItems = computed(() => [
  [
    {
      label: 'Markdown',
      icon: 'i-lucide-file-text',
      disabled: null !== exporting.value,
      onSelect: () => void exportReport('markdown'),
    },
    {
      label: 'JSON',
      icon: 'i-lucide-braces',
      disabled: null !== exporting.value,
      onSelect: () => void exportReport('json'),
    },
    {
      label: 'CSV',
      icon: 'i-lucide-table',
      disabled: null !== exporting.value,
      onSelect: () => void exportReport('csv'),
    },
  ],
]);

watch(
  apiUser,
  () => {
    identityVersion++;
    reportState.value = null;
    loadError.value = '';
    isQueueing.value = false;
    exporting.value = null;
    void loadReport();
  },
  { flush: 'sync' },
);

onMounted((): void => {
  void loadReport();
});
</script>
