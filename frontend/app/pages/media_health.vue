<template>
  <main
    class="w-full min-w-0 max-w-full space-y-6"
    @click.capture="openMainLink"
    @auxclick.capture="openMainLink"
    @contextmenu.capture="openMainLink"
  >
    <div class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading || isQueueing || massActionInProgress"
            @click="loadContent(page)"
          >
            Reload report
          </UButton>

          <UDropdownMenu
            v-if="report"
            :items="exportMenuItems"
            :content="{ align: 'end' }"
            :modal="false"
          >
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-download"
              trailing-icon="i-lucide-chevron-down"
              aria-label="Export full completed report"
              :loading="null !== exporting"
              :disabled="null !== exporting"
            >
              Export report
            </UButton>
          </UDropdownMenu>

          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-play"
            :loading="isQueueing"
            :disabled="isLoading || isQueueing || massActionInProgress || reportState?.queued"
            @click="queueReport"
          >
            Run audit
          </UButton>
        </template>
      </PageHeader>

      <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-toned">
        <div class="flex min-w-0 items-center gap-2">
          <dt>Database:</dt>
          <dd class="font-medium text-default">Main</dd>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <dt>Last completed:</dt>
          <dd>
            <UTooltip
              v-if="report && 'completed' === report.status && report.completed_at"
              :text="relativeDate(report.completed_at)"
            >
              <time :datetime="moment.unix(report.completed_at).toISOString()" class="cursor-help">
                {{ formatDate(report.completed_at) }}
              </time>
            </UTooltip>
            <span v-else>{{ report ? 'Unknown' : 'No report loaded' }}</span>
          </dd>
        </div>
        <div v-if="report" class="flex items-center gap-2">
          <dt>Backends:</dt>
          <dd>{{ formatNumber(report.backend_count) }}</dd>
        </div>
        <div v-if="report && reportState?.stale" class="flex items-center gap-2 text-warning">
          <UIcon name="i-lucide-clock-alert" class="size-4" />
          <dt class="sr-only">Report status:</dt>
          <dd>
            <UTooltip
              text="Records changed after this audit. Run a new audit to update the report."
            >
              <span class="cursor-help">Report is out of date</span>
            </UTooltip>
          </dd>
        </div>
      </dl>
    </div>

    <UAlert
      v-if="reportState?.queued"
      color="info"
      variant="soft"
      icon="i-lucide-clock-3"
      title="Audit queued or running"
      :description="
        report
          ? 'Results remain from the last completed audit. Reload report to check for new results.'
          : 'Reload report to check for a completed audit.'
      "
    />

    <UEmpty
      v-if="!isLoading && !loadError && reportState && !reportState.queued && !report"
      icon="i-lucide-file-search"
      title="No audit found"
      description="Run an audit to check records in the main database."
    />

    <UAlert
      v-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Couldn't load media health"
      :description="loadError"
    />

    <div v-if="report" class="grid gap-3 sm:grid-cols-3">
      <StatCard
        label="Needs review"
        :value="formatNumber(report.summary.actionable_count)"
        icon="i-lucide-triangle-alert"
        color="warning"
      />
      <StatCard
        label="No issues detected"
        :value="formatNumber(statusCount('healthy'))"
        icon="i-lucide-check-circle"
        color="success"
      />
      <StatCard
        label="Records checked"
        :value="formatNumber(report.state_count)"
        icon="i-lucide-library"
        color="neutral"
      />
    </div>

    <section v-if="report" aria-label="Audit filters" class="space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <UFormField label="Main issue" name="status">
          <USelect
            v-model="status"
            :items="statusOptions"
            value-key="value"
            label-key="label"
            class="w-48 max-w-full"
            :disabled="isLoading || isQueueing || massActionInProgress"
          />
        </UFormField>
        <UFormField label="Media type" name="type">
          <USelect
            v-model="type"
            :items="typeOptions"
            value-key="value"
            label-key="label"
            class="w-36"
            :disabled="isLoading || isQueueing || massActionInProgress"
          />
        </UFormField>
        <UFormField label="Filter this page" name="filter" class="min-w-48 flex-1">
          <UInput
            id="filter"
            v-model.lazy="filter"
            type="search"
            icon="i-lucide-search"
            placeholder="Title, backend, or file path"
            class="w-full"
            :disabled="isLoading || isQueueing || massActionInProgress"
          />
        </UFormField>
        <UButton
          v-if="UNHEALTHY_FILTER_VALUE !== status || ALL_FILTER_VALUE !== type || filter"
          color="neutral"
          variant="outline"
          icon="i-lucide-filter-x"
          :disabled="isLoading || isQueueing || massActionInProgress"
          @click="clearFilters"
        >
          Reset filters
        </UButton>
      </div>

      <p class="text-xs text-toned">
        {{ formatNumber(total) }} {{ 1 === total ? 'record matches' : 'records match' }} the
        filters.
        <span v-if="filter">
          {{ formatNumber(filteredItems.length) }} of {{ formatNumber(items.length) }} on this page.
        </span>
        <span v-else>{{ formatNumber(items.length) }} on this page.</span>
      </p>
    </section>

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :is-loading="isLoading || isQueueing || massActionInProgress"
      @navigate="loadContent"
    />

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading media health"
      :ui="{ icon: 'animate-spin' }"
    />

    <UEmpty
      v-if="!isLoading && !loadError && filteredItems.length < 1 && filter && items.length > 0"
      icon="i-lucide-search"
      title="No items found"
    >
      <template #description>
        The filter <code>{{ filter }}</code> did not match any records on this page.
      </template>
    </UEmpty>

    <section v-else-if="0 < filteredItems.length" aria-label="Audit results" class="space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-square-check"
            :disabled="isLoading || isQueueing || massActionInProgress || !!loadError"
            @click="selected_ids = filteredItems.map((item) => item.state_id)"
          >
            Select shown records
          </UButton>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-square"
            :disabled="isLoading || isQueueing || massActionInProgress || 1 > selected_ids.length"
            @click="selected_ids = []"
          >
            Clear selection
          </UButton>
          <span class="text-sm text-toned">{{ selected_ids.length }} selected</span>
        </div>
        <UButton
          v-if="0 < selected_ids.length"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="massActionInProgress"
          :disabled="isLoading || isQueueing || massActionInProgress || !!loadError"
          @click="massDelete"
        >
          Delete from history
        </UButton>
      </div>

      <div class="ws-card divide-y divide-default">
        <Lazy
          v-for="item in filteredItems"
          :key="item.id"
          :unrender="true"
          :min-height="152"
          class="block min-w-0"
        >
          <article
            :aria-labelledby="`media-health-item-${item.id}`"
            class="flex min-w-0 items-start gap-3 p-4"
          >
            <StatisticsPoster
              v-if="poster_enable"
              :key="`main:${item.state_id}`"
              :history-id="item.state_id"
              :title="item.title"
              user="main"
              preview
              class="hidden w-16 shrink-0 sm:block"
            />
            <div class="min-w-0 flex-1 space-y-3">
              <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1.5">
                <div class="flex min-w-0 items-start gap-2">
                  <UIcon :name="mediaIcon(item.type)" class="mt-0.5 size-4 shrink-0 text-toned" />

                  <h2
                    :id="`media-health-item-${item.id}`"
                    class="min-w-0 flex-1 text-base font-semibold text-highlighted"
                  >
                    <UTooltip
                      :text="String(makeName(item as unknown as JsonObject))"
                      :content="{ align: 'start' }"
                    >
                      <NuxtLink
                        :to="`/history/${item.state_id}`"
                        class="block w-fit max-w-full truncate text-highlighted hover:text-primary"
                      >
                        {{ makeName(item as unknown as JsonObject) }}
                      </NuxtLink>
                    </UTooltip>
                  </h2>
                </div>

                <div class="shrink-0 pt-0.5">
                  <UTooltip
                    :text="selected_ids.includes(item.state_id) ? 'Unselect item' : 'Select item'"
                  >
                    <UCheckbox
                      color="primary"
                      :aria-label="`Select ${item.title}`"
                      :disabled="isLoading || isQueueing || massActionInProgress || !!loadError"
                      :model-value="selected_ids.includes(item.state_id)"
                      @update:model-value="toggleSelected(item.state_id, $event)"
                    />
                  </UTooltip>
                </div>
                <div class="col-span-2 flex flex-wrap items-center gap-2">
                  <UBadge
                    :color="statusColor(item.status, item.severity)"
                    :icon="statusPresentation[item.status]?.icon ?? 'i-lucide-circle-help'"
                    variant="soft"
                  >
                    {{ statusPresentation[item.status]?.label ?? 'Unknown issue' }}
                  </UBadge>
                  <UBadge color="neutral" variant="soft" icon="i-lucide-link">
                    Matched IDs: {{ objectEntries(item.signals.guids).length }}
                  </UBadge>
                </div>
              </div>

              <MediaHealthFindings
                :item="item"
                @toggle-file-paths="item.expand_file_checks = !item.expand_file_checks"
                @toggle-duplicate-path="item.expand_duplicate_path = !item.expand_duplicate_path"
              />

              <details
                :open="item.show_details"
                class="space-y-3 border-t border-default pt-3"
                @toggle="item.show_details = ($event.target as HTMLDetailsElement).open"
              >
                <summary class="cursor-pointer text-sm font-medium text-highlighted">
                  <span class="inline-flex items-center gap-2">
                    <UIcon name="i-lucide-list-tree" class="size-4 text-toned" />
                    <span>Supporting details</span>
                  </span>
                </summary>
                <p class="text-xs text-toned">
                  {{ item.backend_count }} of {{ item.expected_backend_count }} backends reported
                  this record.
                </p>

                <div v-if="0 < pathRows(item).length" class="space-y-2">
                  <h3 class="flex items-center gap-2 text-sm font-medium text-highlighted">
                    <UIcon name="i-lucide-file-text" class="size-4 text-toned" />
                    <span>Backend paths</span>
                  </h3>

                  <div class="space-y-3">
                    <div
                      v-for="group in pathRows(item)"
                      :key="`${item.id}-path-${group.path}`"
                      class="min-w-0 space-y-1.5 text-sm text-default"
                    >
                      <div class="flex min-w-0 flex-wrap gap-1.5">
                        <UTooltip
                          v-for="backend in group.backends"
                          :key="`${item.id}-path-${group.path}-${backend}`"
                          :text="item.signals.file_checks?.[backend]?.message"
                          :ui="{
                            content: 'h-auto max-w-xs',
                            text: 'whitespace-normal wrap-anywhere',
                          }"
                        >
                          <BackendItemLink
                            :backend="backend"
                            :url="backendItemUrl(item, backend)"
                            :color="
                              false === item.signals.file_checks?.[backend]?.status
                                ? 'warning'
                                : 'neutral'
                            "
                            :icon="
                              undefined === item.signals.file_checks?.[backend]
                                ? 'i-lucide-server'
                                : item.signals.file_checks[backend].status
                                  ? 'i-lucide-circle-check'
                                  : 'i-lucide-file-x-2'
                            "
                          />
                        </UTooltip>
                      </div>
                      <div class="flex min-w-0 items-start gap-2">
                        <UTooltip
                          v-if="group.path"
                          :text="
                            item.expand_paths ? 'Truncate backend paths' : 'Wrap backend paths'
                          "
                        >
                          <UButton
                            color="neutral"
                            :variant="item.expand_paths ? 'soft' : 'outline'"
                            size="xs"
                            square
                            icon="i-lucide-wrap-text"
                            class="shrink-0"
                            :aria-label="
                              item.expand_paths ? 'Truncate backend paths' : 'Wrap backend paths'
                            "
                            :aria-pressed="true === item.expand_paths"
                            @click="item.expand_paths = !item.expand_paths"
                          />
                        </UTooltip>
                        <UTooltip v-if="group.path" :text="group.path">
                          <NuxtLink
                            :to="makeSearchLink('path', group.path)"
                            class="min-w-0 flex-1 hover:text-primary"
                            :class="item.expand_paths ? 'wrap-anywhere' : 'truncate'"
                          >
                            {{ group.path }}
                          </NuxtLink>
                        </UTooltip>
                        <span v-else class="min-w-0 flex-1 text-toned">No path reported</span>
                        <UTooltip v-if="group.path" text="Copy backend path">
                          <UButton
                            color="neutral"
                            variant="ghost"
                            size="xs"
                            square
                            icon="i-lucide-copy"
                            aria-label="Copy backend path"
                            @click="copyText(group.path, false)"
                          />
                        </UTooltip>
                      </div>
                    </div>
                  </div>
                </div>

                <div v-if="0 < objectEntries(item.signals.guids).length" class="space-y-2">
                  <h3 class="flex items-center gap-2 text-sm font-medium text-highlighted">
                    <UIcon name="i-lucide-link" class="size-4 text-toned" />
                    <span>Matched IDs</span>
                  </h3>
                  <p class="text-sm text-toned">IDs used to match this item to backend records.</p>

                  <div class="flex flex-wrap gap-2">
                    <UBadge
                      v-for="[key, value] in objectEntries(item.signals.guids)"
                      :key="`${item.id}-guid-${key}-${value}`"
                      color="neutral"
                      variant="soft"
                      class="max-w-full"
                    >
                      <NuxtLink
                        :to="guidLink(item, key, String(value))"
                        target="_blank"
                        class="block max-w-64 truncate hover:text-primary"
                      >
                        {{ guidSource(key) }}://{{ value }}
                      </NuxtLink>
                    </UBadge>
                  </div>
                </div>
              </details>
            </div>
          </article>
        </Lazy>
      </div>
    </section>

    <UEmpty
      v-else-if="!isLoading && report && !loadError && 0 === report.summary.total"
      icon="i-lucide-library"
      title="No records audited"
    />

    <UEmpty
      v-else-if="
        report &&
        !isLoading &&
        !loadError &&
        UNHEALTHY_FILTER_VALUE === status &&
        ALL_FILTER_VALUE === type &&
        !filter &&
        report.summary.total === statusCount('healthy')
      "
      icon="i-lucide-check-circle"
      title="No issues detected"
    />

    <UEmpty
      v-else-if="!isLoading && report && !loadError"
      icon="i-lucide-search"
      title="No records match these filters"
    />

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :is-loading="isLoading || isQueueing || massActionInProgress"
      @navigate="loadContent"
    />

    <details v-if="report" class="ws-panel-padded space-y-3">
      <summary class="cursor-pointer text-sm font-medium text-highlighted">
        <span class="inline-flex items-center gap-2">
          <UIcon name="i-lucide-wrench" class="size-4 text-toned" />
          <span>Correcting audit findings</span>
        </span>
      </summary>
      <p class="text-sm text-toned">
        After correcting backend metadata, force a metadata import from the affected backends, then
        run a new audit.
      </p>
      <p class="text-sm text-toned">
        File checks use paths accessible to WatchState, not the backend filesystem.
      </p>
    </details>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UCheckbox,
  UDropdownMenu,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  USelect,
  UTooltip,
} from '#components';
import BackendItemLink from '~/components/BackendItemLink.vue';
import Lazy from '~/components/Lazy.vue';
import MediaHealthFindings from '~/components/MediaHealthFindings.vue';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import StatCard from '~/components/StatCard.vue';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { mediaHealthGuidLink as guidLink } from '~/utils/mediaHealth';
import {
  copyText,
  makeName,
  makeSearchLink,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type {
  JsonObject,
  MediaHealthItem,
  MediaHealthItemWithUI,
  MediaHealthReport,
  MediaHealthResponse,
  MediaHealthRunResponse,
  MediaHealthStatus,
  PaginatedResponse,
} from '~/types';

const pageShell = requireTopLevelPageShell('media-health');
const route = useRoute();
const router = useRouter();
const apiUser = useStorage<string>('api_user', 'main', undefined, { flush: 'sync' });
let pendingMainPath: string | null = null;

useHead({ title: 'Media Health' });

type SelectItem = {
  label: string;
  value: string;
};

type ItemsResponse = PaginatedResponse<MediaHealthItemWithUI> & {
  report: MediaHealthReport;
};

const ALL_FILTER_VALUE = 'all';
const UNHEALTHY_FILTER_VALUE = 'unhealthy';

const poster_enable = useStorage('poster_enable', true);
const reportState = ref<MediaHealthResponse | null>(null);
const report = ref<MediaHealthReport | null>(null);
const items = ref<Array<MediaHealthItemWithUI>>([]);
const page = ref<number>(Number(route.query.page ?? 1));
const perpage = ref<number>(Number(route.query.perpage ?? 50));
const total = ref<number>(0);
const last_page = computed<number>(() => Math.max(1, Math.ceil(total.value / perpage.value)));
const routeStatus = Array.isArray(route.query.status) ? route.query.status[0] : route.query.status;
const routeType = Array.isArray(route.query.type) ? route.query.type[0] : route.query.type;
const status = ref<string>(routeStatus ? String(routeStatus) : UNHEALTHY_FILTER_VALUE);
const type = ref<string>(routeType ? String(routeType) : ALL_FILTER_VALUE);
const filter = ref<string>(String(route.query.filter ?? ''));
const isLoading = ref<boolean>(false);
const loadError = ref('');
const isQueueing = ref<boolean>(false);
const selected_ids = ref<Array<number>>([]);
const massActionInProgress = ref<boolean>(false);
const exporting = ref<'markdown' | 'json' | 'csv' | null>(null);
let loadRequest = 0;

watch(
  () => router.currentRoute.value.path,
  (path) => {
    if (pendingMainPath && pendingMainPath === path) {
      apiUser.value = 'main';
      pendingMainPath = null;
    }
  },
  { flush: 'sync' },
);

const openMainLink = async (event: MouseEvent): Promise<void> => {
  const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
  if (!(anchor instanceof HTMLAnchorElement)) {
    return;
  }

  const target = new URL(anchor.href, window.location.href);
  if (
    window.location.origin !== target.origin ||
    !/^\/(?:history(?:\/|$)|backend\/)/.test(target.pathname) ||
    'main' === apiUser.value
  ) {
    return;
  }

  event.preventDefault();
  event.stopPropagation();
  if ('auxclick' === event.type || 'contextmenu' === event.type) {
    notification(
      'info',
      'Main database link',
      'Select the main identity before opening this link in another tab.',
    );
    return;
  }
  if (pendingMainPath) {
    return;
  }

  pendingMainPath = target.pathname;
  try {
    await navigateTo(`${target.pathname}${target.search}${target.hash}`);
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected navigation error';
    notification('error', 'Could not open main database link', message);
  } finally {
    pendingMainPath = null;
  }
};

const statusOptions = computed<Array<SelectItem>>(() => [
  { label: 'Needs review', value: UNHEALTHY_FILTER_VALUE },
  { label: 'All records', value: ALL_FILTER_VALUE },
  ...Object.keys(report.value?.summary.statuses ?? {}).map((value) => ({
    label: statusPresentation[value as MediaHealthStatus]?.label ?? 'Unknown issue',
    value,
  })),
]);

const typeOptions: Array<SelectItem> = [
  { label: 'All types', value: ALL_FILTER_VALUE },
  { label: 'Movie', value: 'movie' },
  { label: 'Episode', value: 'episode' },
];

const filteredItems = computed<Array<MediaHealthItemWithUI>>(() => {
  const term = filter.value.trim().toLowerCase();
  if ('' === term) {
    return items.value;
  }

  return items.value.filter((item) => {
    const haystack = [
      item.title,
      item.type,
      item.status,
      statusPresentation[item.status]?.label,
      String(item.state_id),
      ...backendNames(item),
      ...missingBackendNames(item),
      ...Object.values(item.signals.paths ?? {}),
      ...Object.values(item.signals.backend_items ?? {}).map((backend) => backend.path ?? ''),
      ...missingFileChecks(item).map((check) => `${check.backend} ${check.path} ${check.message}`),
      ...metadataConflictEntries(item).flatMap(([field, values]) => [
        field,
        ...Object.keys(values),
      ]),
      ...Object.entries(item.signals.guid_conflicts ?? {}).flatMap(([provider, values]) => [
        provider,
        ...Object.keys(values),
      ]),
      ...Object.values(item.signals.guids ?? {}).map(String),
      ...item.reasons,
    ]
      .join(' ')
      .toLowerCase();

    return haystack.includes(term);
  });
});

const toggleSelected = (id: number, value: boolean | 'indeterminate'): void => {
  if (true === value) {
    if (!selected_ids.value.includes(id)) {
      selected_ids.value.push(id);
    }
    return;
  }

  selected_ids.value = selected_ids.value.filter((itemId) => itemId !== id);
};

const loadSummary = async (): Promise<MediaHealthResponse> => {
  const response = await request('/state/media-health');
  const json = await parse_api_response<MediaHealthResponse>(response);
  if ('error' in json) {
    throw new Error(`${json.error.code}: ${json.error.message}`);
  }

  return json;
};

const loadContent = async (pageNumber: number): Promise<void> => {
  pageNumber = Number(pageNumber);
  if (Number.isNaN(pageNumber) || 1 > pageNumber) {
    pageNumber = 1;
  }

  const requestId = ++loadRequest;
  isLoading.value = true;
  loadError.value = '';
  selected_ids.value = [];

  try {
    const summary = await loadSummary();
    if (requestId !== loadRequest) {
      return;
    }

    reportState.value = summary;
    if (!summary.report) {
      report.value = null;
      items.value = [];
      total.value = 0;
      return;
    }

    const search = new URLSearchParams();
    search.set('page', String(pageNumber));
    search.set('perpage', String(perpage.value));
    if (UNHEALTHY_FILTER_VALUE === status.value) {
      search.set('unhealthy', '1');
    } else if (ALL_FILTER_VALUE !== status.value) {
      search.set('status', status.value);
    }
    if (ALL_FILTER_VALUE !== type.value) {
      search.set('type', type.value);
    }

    const response = await request(`/state/media-health/items?${search.toString()}`);
    const json = await parse_api_response<ItemsResponse>(response);
    if (requestId !== loadRequest) {
      return;
    }
    if ('error' in json) {
      loadError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Audit not loaded', `${json.error.code}: ${json.error.message}`);
      return;
    }

    report.value = json.report;
    reportState.value = { ...summary, report: json.report };
    items.value = json.items;
    page.value = json.paging.current_page;
    perpage.value = json.paging.perpage;
    total.value = json.paging.total;

    await router.replace({
      path: '/media_health',
      query: {
        ...Object.fromEntries(search),
        page: String(page.value),
        status: status.value,
        type: type.value,
        filter: filter.value.trim() || undefined,
      },
    });
  } catch (e: unknown) {
    if (requestId !== loadRequest) {
      return;
    }
    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Audit not loaded', loadError.value);
  } finally {
    if (requestId === loadRequest) {
      isLoading.value = false;
    }
  }
};

const massDelete = async (): Promise<void> => {
  if (
    !report.value ||
    'completed' !== report.value.status ||
    isLoading.value ||
    isQueueing.value ||
    massActionInProgress.value ||
    loadError.value ||
    1 > selected_ids.value.length
  ) {
    return;
  }

  const ids = [...selected_ids.value];
  const reportId = report.value.id;
  const requestId = loadRequest;
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Delete selected records?',
    confirmText: 'Delete',
    message: `Delete ${ids.length} selected records from main database history? Backend items and media files are not deleted. Run another audit to update this report.`,
    confirmColor: 'error',
  });

  if (
    true !== confirmStatus ||
    reportId !== report.value?.id ||
    requestId !== loadRequest ||
    isLoading.value ||
    isQueueing.value ||
    massActionInProgress.value ||
    loadError.value ||
    ids.length !== selected_ids.value.length ||
    !ids.every((id) => selected_ids.value.includes(id)) ||
    !ids.every((id) =>
      filteredItems.value.some((item) => id === item.state_id && reportId === item.report_id),
    )
  ) {
    return;
  }

  massActionInProgress.value = true;

  try {
    notification('success', 'Deleting records', `Deleting ${ids.length} selected records.`);

    const requests = await Promise.all(
      ids.map((id) =>
        request(`/history/${id}`, {
          method: 'DELETE',
          headers: { 'X-User': 'main' },
        }),
      ),
    );

    if (!requests.every((response) => 200 === response.status)) {
      notification('error', 'Some records were not deleted', 'Reload before trying again.');
      return;
    }

    items.value = items.value.filter((item) => !ids.includes(item.state_id));
    total.value = Math.max(0, total.value - ids.length);
    notification(
      'success',
      'Records deleted',
      `Deleted ${ids.length} records from the main database.`,
    );
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Records not deleted', error.message);
  } finally {
    massActionInProgress.value = false;
    selected_ids.value = [];
  }
};

const queueReport = async (): Promise<void> => {
  if (
    isLoading.value ||
    isQueueing.value ||
    massActionInProgress.value ||
    reportState.value?.queued
  ) {
    return;
  }

  isQueueing.value = true;
  try {
    const response = await request('/state/media-health/run', { method: 'POST' });
    const json = await parse_api_response<MediaHealthRunResponse>(response);
    if ('error' in json) {
      notification('error', 'Audit not queued', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification(json.queued ? 'success' : 'info', 'Media Health', json.message);
    reportState.value = {
      report: report.value,
      stale: reportState.value?.stale ?? false,
      queued: true,
      queued_event: json.event_id,
    };
    reportState.value = await loadSummary();
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Audit not queued', error.message);
  } finally {
    isQueueing.value = false;
  }
};

const exportReport = async (format: 'markdown' | 'json' | 'csv'): Promise<void> => {
  if (null !== exporting.value) {
    return;
  }

  exporting.value = format;
  try {
    const response = await request(`/state/media-health/export/${format}`);
    if (!response.ok) {
      const json = await parse_api_response<MediaHealthResponse>(response);
      if ('error' in json) {
        notification('error', 'Audit not exported', `${json.error.code}: ${json.error.message}`);
      }
      return;
    }

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `media-health-${format}.zip`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Audit not exported', error.message);
  } finally {
    exporting.value = null;
  }
};

const exportMenuItems = computed(() => [
  [
    {
      label: 'Full completed report',
      type: 'label' as const,
    },
    {
      label: 'Markdown',
      icon: 'i-lucide-file-text',
      disabled: null !== exporting.value,
      onSelect: () => {
        void exportReport('markdown');
      },
    },
    {
      label: 'JSON',
      icon: 'i-lucide-file-json',
      disabled: null !== exporting.value,
      onSelect: () => {
        void exportReport('json');
      },
    },
    {
      label: 'CSV',
      icon: 'i-lucide-table-2',
      disabled: null !== exporting.value,
      onSelect: () => {
        void exportReport('csv');
      },
    },
  ],
]);

const clearFilters = (): void => {
  status.value = UNHEALTHY_FILTER_VALUE;
  type.value = ALL_FILTER_VALUE;
  filter.value = '';
};

const statusCount = (key: string): number => report.value?.summary.statuses[key] ?? 0;

const formatNumber = (value: number): string => new Intl.NumberFormat().format(value);

const formatDate = (value: number | null): string => {
  if (!value) {
    return 'Unknown';
  }

  return moment.unix(value).format(TOOLTIP_DATE_FORMAT.value);
};

const relativeDate = (value: number | null): string => {
  if (!value) {
    return 'Unknown';
  }

  return moment.unix(value).fromNow();
};

const statusPresentation: Record<MediaHealthStatus, { label: string; icon: string }> = {
  healthy: { label: 'No issues detected', icon: 'i-lucide-circle-check' },
  file_missing: { label: 'File check failed', icon: 'i-lucide-file-x-2' },
  partial: { label: 'Missing backend metadata', icon: 'i-lucide-server-off' },
  duplicate_reference: { label: 'Duplicate file path', icon: 'i-lucide-layers-3' },
  guid_conflict: { label: 'ID conflict', icon: 'i-lucide-git-compare-arrows' },
  duplicate_guid: { label: 'Duplicate ID', icon: 'i-lucide-fingerprint' },
  metadata_disagreement: { label: 'Metadata mismatch', icon: 'i-lucide-list-x' },
  path_disagreement: { label: 'Path mismatch', icon: 'i-lucide-file-warning' },
  weak_match: { label: 'Missing external IDs', icon: 'i-lucide-link-2-off' },
};

const mediaIcon = (itemType: string): string =>
  'episode' === itemType.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film';

const statusColor = (
  status: MediaHealthStatus,
  severity: number,
): 'error' | 'warning' | 'success' => {
  if ('healthy' === status) {
    return 'success';
  }

  return 80 <= severity ? 'error' : 'warning';
};

const backendNames = (item: MediaHealthItem): Array<string> => item.signals.backends ?? [];

const missingBackendNames = (item: MediaHealthItem): Array<string> =>
  item.signals.missing_backends ?? [];

const missingFileChecks = (
  item: MediaHealthItem,
): NonNullable<MediaHealthItem['signals']['missing_files']> => item.signals.missing_files ?? [];

const backendItemUrl = (item: MediaHealthItem, backend: string): string | null =>
  item.signals.backend_items?.[backend]?.webUrl ?? null;

const metadataConflictEntries = (
  item: MediaHealthItem,
): Array<[string, Record<string, Array<string>>]> => objectEntries(item.signals.metadata_conflicts);

const pathGroups = (item: MediaHealthItem): Array<{ path: string; backends: Array<string> }> => {
  const groups = new Map<string, Array<string>>();
  const paths = { ...item.signals.paths };

  for (const [backend, metadata] of Object.entries(item.signals.backend_items ?? {})) {
    if (metadata.path) {
      paths[backend] = metadata.path;
    }
  }

  for (const [backend, path] of Object.entries(paths)) {
    const normalizedPath = String(path).trim();
    if ('' === normalizedPath) {
      continue;
    }

    groups.set(normalizedPath, [...(groups.get(normalizedPath) ?? []), backend]);
  }

  return Array.from(groups.entries()).map(([path, backends]) => ({ path, backends }));
};

const pathRows = (item: MediaHealthItem): Array<{ path: string; backends: Array<string> }> => {
  const rows = pathGroups(item);
  const backendsWithoutPath = backendNames(item).filter((backend) => {
    const path = String(
      item.signals.backend_items?.[backend]?.path || item.signals.paths?.[backend] || '',
    ).trim();
    return '' === path;
  });

  if (0 < backendsWithoutPath.length) {
    rows.push({ path: '', backends: backendsWithoutPath });
  }

  return rows;
};

function objectEntries<T>(value: Record<string, T> | undefined): Array<[string, T]> {
  return Object.entries(value ?? {});
}

const guidSource = (key: string): string => key.replace(/^guid_/, '');

watch([status, type], async () => await loadContent(1));
watch(filter, async () => {
  selected_ids.value = [];
  await router.replace({
    path: '/media_health',
    query: {
      ...route.query,
      filter: filter.value.trim() || undefined,
    },
  });
});

onMounted(async () => await loadContent(page.value));

onBeforeUnmount(() => {
  pendingMainPath = null;
  loadRequest++;
});
</script>
