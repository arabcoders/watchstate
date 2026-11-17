<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-download"
          :disabled="(!matchingEnabled && !selectedEnabled) || exporting"
          @click="exportOpen = true"
        >
          Export
        </UButton>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          aria-label="Refresh history"
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent(requestedPage)"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="History controls" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div
          role="group"
          class="inline-flex items-center gap-1 rounded-lg bg-elevated/20 p-1"
          aria-label="History view"
        >
          <UButton
            color="neutral"
            :variant="'compact' === historyView ? 'soft' : 'outline'"
            icon="i-lucide-layout-grid"
            :aria-pressed="'compact' === historyView"
            size="sm"
            @click="historyView = 'compact'"
          >
            Grid
          </UButton>
          <UButton
            color="neutral"
            :variant="'detailed' === historyView ? 'soft' : 'outline'"
            icon="i-lucide-list"
            :aria-pressed="'detailed' === historyView"
            size="sm"
            @click="historyView = 'detailed'"
          >
            List
          </UButton>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <UButton
            color="neutral"
            :variant="showFilters ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-search"
            :aria-expanded="showFilters"
            aria-controls="history-server-filters"
            @click="showFilters = !showFilters"
          >
            Search history
            <UBadge v-if="appliedFilters.length" color="neutral" variant="soft" size="xs">
              {{ appliedFilters.length }}
            </UBadge>
          </UButton>
          <UButton
            color="neutral"
            :variant="showFilter ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-list-filter"
            :aria-expanded="showFilter"
            aria-controls="history-page-filter"
            @click="toggleFilter"
          >
            Filter this page
          </UButton>
          <USelect
            v-model="perpage"
            :items="perPageItems"
            value-key="value"
            label-key="label"
            color="neutral"
            variant="outline"
            size="sm"
            class="w-36"
            aria-label="Records per page"
            :disabled="isLoading || massActionInProgress"
            @update:model-value="() => void loadContent(1)"
          />
        </div>
      </div>

      <div v-show="showFilters" id="history-server-filters">
        <HistoryFilters
          :query="query"
          :searchable="searchable"
          :options="filterOptions"
          :loading="isLoading || massActionInProgress"
          @apply="applyFilters"
          @draft-state="draftBlocked = !$event.valid || $event.dirty"
        />
      </div>

      <div v-if="showFilter" id="history-page-filter" class="flex flex-wrap items-center gap-3">
        <UInput
          id="filter"
          v-model="filter"
          type="search"
          placeholder="Title, backend, or file path"
          aria-label="Filter records on the current page"
          icon="i-lucide-list-filter"
          size="sm"
          class="w-full sm:w-80"
        />
      </div>

      <div v-if="appliedFilters.length || filter" class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-dimmed">Applied</span>
        <UButton
          v-for="chip in appliedFilters"
          :key="`${chip.key}:${chip.value}`"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          :aria-label="`Remove filter ${chip.label}`"
          :disabled="isLoading || massActionInProgress"
          @click="removeFilter(chip.key, chip.value)"
        >
          {{ chip.label }}
        </UButton>
        <UButton
          v-if="filter"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          :aria-label="`Remove page filter ${filter}`"
          @click="filter = ''"
        >
          This page: {{ filter }}
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-x"
          :disabled="isLoading || massActionInProgress"
          @click="clearFilters"
        >
          Clear all
        </UButton>
      </div>

      <p v-if="draftBlocked" class="text-sm text-warning" role="status">
        Apply your search changes to select, edit, or export records.
      </p>
      <p v-else-if="filter" class="text-xs text-toned">
        Clear the page filter to export all search matches, or export selected records.
      </p>
    </section>

    <HistoryExportModal
      v-model:open="exportOpen"
      :matching-count="matchingCount"
      :selected-count="selected_ids.length"
      :matching-enabled="matchingEnabled"
      :selected-enabled="selectedEnabled"
      :exporting="exporting"
      :page-filter-active="!!filter"
      :blocked="exportBlocked"
      @export="downloadHistory"
    />

    <section
      v-if="0 < selected_ids.length"
      class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/25 bg-primary/5 px-4 py-3"
      aria-label="Selected record actions"
    >
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-highlighted">{{ selected_ids.length }} selected</span>
        <span class="text-xs text-toned">across pages</span>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-eye"
          :loading="massActionInProgress"
          :disabled="exportBlocked || !selection.some((item) => !item.watched)"
          @click="() => void massAction('mark_played')"
        >
          Mark watched
        </UButton>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-eye-off"
          :loading="massActionInProgress"
          :disabled="exportBlocked || !selection.some((item) => item.watched)"
          @click="() => void massAction('mark_unplayed')"
        >
          Mark unwatched
        </UButton>
      </div>

      <div class="flex items-center gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-download"
          :disabled="!selectedEnabled || exporting"
          @click="exportOpen = true"
        >
          Export selected
        </UButton>
        <UDropdownMenu :items="selectionActions">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-ellipsis"
            aria-label="More selected record actions"
            :disabled="massActionInProgress"
          >
            More actions
          </UButton>
        </UDropdownMenu>
      </div>
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="space-y-1 text-sm text-toned" aria-live="polite" aria-atomic="true">
        <p v-if="hasResults">
          <strong class="font-medium text-highlighted">{{ total.toLocaleString() }}</strong>
          {{ appliedFilters.length ? 'matching records' : 'history records' }}
          <span class="text-dimmed">
            · {{ filteredItems.length }}{{ filter ? ` of ${items.length}` : '' }} on this page
          </span>
        </p>
        <p v-if="isLoading" class="inline-flex items-center gap-1.5 text-xs">
          <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />
          {{ hasResults ? 'Refreshing this page…' : 'Loading history…' }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <UButton
          color="neutral"
          :variant="pageSelected ? 'soft' : 'outline'"
          size="sm"
          :icon="pageSelected ? 'i-lucide-square-minus' : 'i-lucide-square-check-big'"
          :disabled="exportBlocked || 0 === filteredItems.length"
          @click="selectRecords(filteredItems, !pageSelected)"
        >
          {{ pageSelected ? 'Unselect page' : 'Select page' }}
        </UButton>
        <Pager
          v-if="total && 1 < last_page"
          :page="page"
          :last_page="last_page"
          :is-loading="isLoading"
          @navigate="navigatePage"
        />
      </div>
    </div>

    <UAlert
      v-if="isLoading && !hasResults"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading history"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-circle-alert"
      title="History couldn't be loaded"
    >
      <template #description>
        <div class="space-y-3">
          <p>{{ error }}</p>
          <p v-if="hasResults">
            These records may be out of date. Retry before changing or exporting them.
          </p>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :disabled="isLoading"
            @click="() => void loadContent(requestedPage)"
          >
            Retry
          </UButton>
        </div>
      </template>
    </UAlert>

    <UEmpty
      v-if="!isLoading && !error && hasResults && 0 === filteredItems.length"
      icon="i-lucide-search"
      :title="filter || appliedFilters.length ? 'No matching records' : 'No history yet'"
      :description="
        filter
          ? 'Clear the page filter or use Search history to search all pages.'
          : appliedFilters.length
            ? 'Try a broader search or remove an applied filter.'
            : 'Check backend import settings or webhook setup to start collecting history.'
      "
    />

    <HistoryCompactView
      v-if="0 < filteredItems.length && 'compact' === historyView"
      :items="filteredItems"
      :selected-ids="selected_ids"
      :selection-disabled="exportBlocked"
      :poster-enable="poster_enable"
      :user="apiUser"
      @select="toggleSelected"
    />

    <HistoryDetailedView
      v-else-if="0 < filteredItems.length"
      :items="filteredItems"
      :selected-ids="selected_ids"
      :selection-disabled="exportBlocked"
      :poster-enable="poster_enable"
      :user="apiUser"
      @select="toggleSelected"
      @expand="toggleExpanded"
    />

    <div v-if="total && 1 < last_page" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
      <div class="text-xs text-toned">Page {{ page }} of {{ last_page }}</div>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { useRoute, useRouter, useHead } from '#app';
import { useStorage } from '@vueuse/core';
import Pager from '~/components/Pager.vue';
import {
  UAlert,
  UBadge,
  UButton,
  UDropdownMenu,
  UEmpty,
  UIcon,
  UInput,
  USelect,
} from '#components';
import HistoryCompactView from '~/components/HistoryCompactView.vue';
import HistoryDetailedView from '~/components/HistoryDetailedView.vue';
import HistoryFilters from '~/components/HistoryFilters.vue';
import HistoryExportModal from '~/components/HistoryExportModal.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { request, awaitElement, makeName, notification, parse_api_response } from '~/utils';
import type {
  HistoryItem,
  HistoryListItem,
  JsonObject,
  PaginationInfo,
  RequestOptions,
  HistorySearchableField,
  HistoryFilterQuery,
  HistoryFilterOptions,
  HistoryExportFilters,
  HistoryExportChoice,
} from '~/types';
import { useDialog } from '~/composables/useDialog.ts';
import { useHistoryCuration } from '~/composables/useHistoryCuration';

type HistoryPagination = PaginationInfo;

const pageShell = requireTopLevelPageShell('history');

const route = useRoute();
const router = useRouter();

useHead({ title: 'History' });

const poster_enable = useStorage('poster_enable', true);
const apiUser = useStorage<string>('api_user', 'main');
const historyView = useStorage<'detailed' | 'compact'>('history_view', 'compact');

const items = ref<Array<HistoryListItem>>([]);
const searchable = ref<Array<HistorySearchableField>>([]);
const error = ref('');

const page = ref<number>(parseInt(route.query.page as string) || 1);
const requestedPage = ref(page.value);
const perpage = ref<number>(parseInt(route.query.perpage as string) || 50);
const total = ref<number>(0);
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));

const isLoading = ref(false);
const filter = ref<string>((route.query.filter as string) || '');
const showFilter = ref<boolean>(Boolean(filter.value));
const showFilters = ref(false);
const massActionInProgress = ref(false);
const query = shallowRef<HistoryFilterQuery>({});
const draftBlocked = ref(false);
const hasResults = ref(false);
const exportOpen = ref(false);
const exportBlocked = computed(
  () =>
    isLoading.value ||
    massActionInProgress.value ||
    draftBlocked.value ||
    !hasResults.value ||
    !!error.value,
);
const filterOptions = shallowRef<HistoryFilterOptions>({ genres: [], libraries: [] });
const {
  selectedIds: selected_ids,
  selection,
  matchingCount,
  matchingEnabled,
  selectedEnabled,
  exporting,
  beginQuery,
  applyQuery,
  selectRecords,
  clearSelection,
  exportHistory,
  readQuery,
  buildFilters,
} = useHistoryCuration(apiUser, exportBlocked, filter);
let loadController = new AbortController();
let optionsController = new AbortController();
let bulkController = new AbortController();
let loadId = 0;
let writingRoute = false;
let loadedRequest = '';

const perPageItems = [50, 100, 200, 400, 500].map((value) => ({
  label: `${value} per page`,
  value,
}));

const filterLabels: Record<string, string> = {
  title: 'Title',
  type: 'Type',
  status: 'Watch status',
  watched: 'Watched',
  in_progress: 'In progress',
  genres: 'Genre',
  library: 'Library',
  year_min: 'Year from',
  year_max: 'Year to',
  year: 'Year',
  via: 'Backend',
  id: 'History ID',
  parent: 'Series GUID',
  guids: 'Content GUID',
  rguid: 'Relative GUID',
  subtitle: 'Subtitle',
  path: 'Path',
  sort: 'Sort',
  with_duplicates: 'Include duplicates',
};

const appliedFilters = computed(() =>
  Object.entries(query.value).flatMap(([key, raw]) => {
    const label =
      filterLabels[key] ??
      searchable.value.find((field) => key === field.key)?.display ??
      key.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
    const values = Array.isArray(raw) ? raw : [raw];

    return values.map((value) => {
      let display = value;
      if ('library' === key) {
        display =
          filterOptions.value.libraries.find((library) => value === library.value)?.label ?? value;
      } else if (['watched', 'in_progress', 'with_duplicates'].includes(key)) {
        display = '1' === value ? 'Yes' : '0' === value ? 'No' : value;
      } else if (['status', 'type'].includes(key)) {
        display =
          (
            {
              watched: 'Watched',
              in_progress: 'In progress',
              not_started: 'Not started',
              movie: 'Movies',
              episode: 'Episodes',
            } as Record<string, string>
          )[value] ?? value;
      }

      return { key, value, label: `${label}: ${display}` };
    });
  }),
);

const activeSearchSummary = computed(() =>
  appliedFilters.value.map((chip) => chip.label).join(', '),
);

const selectionActions = computed(() => [
  {
    label: 'Clear selection',
    icon: 'i-lucide-square',
    disabled: massActionInProgress.value,
    onSelect: clearSelection,
  },
  {
    label: 'Delete selected records',
    icon: 'i-lucide-trash-2',
    color: 'neutral' as const,
    disabled: exportBlocked.value,
    onSelect: (): void => {
      void massAction('delete');
    },
  },
]);

const stringifyItem = (item: HistoryListItem): string => JSON.stringify(item).toLowerCase();

const filteredRows = (input: Array<HistoryListItem>): Array<HistoryListItem> => {
  if (!filter.value) {
    return input;
  }

  return input.filter((item) => stringifyItem(item).includes(filter.value.toLowerCase()));
};

const filteredItems = computed(() => filteredRows(items.value));

const pageSelected = computed(
  () =>
    0 < filteredItems.value.length &&
    filteredItems.value.every((item) => selected_ids.value.includes(item.id)),
);

const toggleSelected = (id: number, value: boolean | 'indeterminate'): void => {
  const item = items.value.find((item) => id === item.id);
  if (item) {
    selectRecords([item], true === value);
  }
};

const toggleExpanded = (id: number, field: 'expand_title' | 'expand_path'): void => {
  const item = items.value.find((item) => id === item.id);
  if (undefined !== item) {
    item[field] = !item[field];
  }
};

const navigatePage = (pageNumber: number): void => {
  void loadContent(pageNumber);
};

const applyFilters = (next: HistoryFilterQuery): void => {
  query.value = next;
  draftBlocked.value = false;
  void loadContent(1);
};

const removeFilter = (key: string, value: string): void => {
  const { [key]: _removed, ...next } = query.value;
  const current = query.value[key];
  if (Array.isArray(current)) {
    const remaining = current.filter((entry) => value !== entry);
    if (0 < remaining.length) {
      next[key] = remaining;
    }
  }

  applyFilters(next);
};

const clearFilters = (): void => {
  filter.value = '';
  applyFilters({});
};

const downloadHistory = async (choice: HistoryExportChoice): Promise<void> => {
  if (await exportHistory(choice)) {
    exportOpen.value = false;
  }
};

const loadContent = async (pageNumber: number, fromPopState: boolean = false): Promise<void> => {
  if (massActionInProgress.value) {
    return;
  }
  pageNumber = Math.max(1, parseInt(String(pageNumber)) || 1);
  loadController.abort();
  loadController = new AbortController();
  const active = loadController;
  const id = ++loadId;
  const selectedUser = apiUser.value;
  const requestedQuery: HistoryFilterQuery = Object.fromEntries(
    Object.entries(query.value).map(([key, value]) => [
      key,
      Array.isArray(value) ? [...value] : value,
    ]),
  );
  requestedPage.value = pageNumber;
  const requestKey = JSON.stringify([
    selectedUser,
    pageNumber,
    perpage.value,
    Object.entries(requestedQuery).sort(([left], [right]) => left.localeCompare(right)),
  ]);

  if (!hasResults.value || requestKey !== loadedRequest) {
    hasResults.value = false;
    items.value = [];
    total.value = 0;
  }

  isLoading.value = true;
  error.value = '';

  const search = new URLSearchParams({ perpage: String(perpage.value), page: String(pageNumber) });
  try {
    let filters: HistoryExportFilters;
    try {
      filters = buildFilters(requestedQuery);
    } catch (cause: unknown) {
      beginQuery(requestedQuery);
      throw cause;
    }
    beginQuery(filters);
    for (const [key, value] of Object.entries(filters)) {
      if (Array.isArray(value)) {
        for (const entry of value) {
          search.append(`${key}[]`, entry);
        }
      } else {
        search.set(key, String(value));
      }
    }

    const response = await request(`/history?${search}`, {
      headers: { 'X-User': selectedUser },
      signal: active.signal,
    });
    const json = await parse_api_response<{
      history: Array<HistoryItem>;
      paging: HistoryPagination;
      searchable: Array<HistorySearchableField>;
    }>(response);
    if (
      active.signal.aborted ||
      id !== loadId ||
      selectedUser !== apiUser.value ||
      'history' !== route.name
    ) {
      return;
    }

    if ('error' in json) {
      if (404 !== response.status || !('filters' in json)) {
        throw new Error(json.error.message || 'Failed to load History.');
      }

      page.value = 1;
      total.value = 0;
      items.value = [];
      clearSelection();
    } else {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      page.value = json.paging.current_page;
      perpage.value = json.paging.perpage;
      total.value = json.paging.total;
      items.value = json.history.map(
        (item) =>
          ({
            ...item,
            full_title: makeName(item as unknown as JsonObject),
          }) as unknown as HistoryListItem,
      );
      searchable.value = json.searchable ?? [];
    }

    applyQuery(filters, total.value, items.value);
    hasResults.value = true;
    requestedPage.value = page.value;
    loadedRequest = JSON.stringify([
      selectedUser,
      page.value,
      perpage.value,
      Object.entries(requestedQuery).sort(([left], [right]) => left.localeCompare(right)),
    ]);
    useHead({
      title: `History: Page #${page.value}${activeSearchSummary.value ? `. (${activeSearchSummary.value})` : ''}`,
    });

    if (!fromPopState) {
      const nextQuery: Record<string, string | Array<string>> = {
        page: String(page.value),
        perpage: String(perpage.value),
      };
      for (const [key, value] of Object.entries(requestedQuery)) {
        nextQuery[Array.isArray(value) ? `${key}[]` : key] = value;
      }
      if (filter.value) {
        nextQuery.filter = filter.value;
      }
      writingRoute = true;
      try {
        await router.push({ path: '/history', query: nextQuery });
      } finally {
        writingRoute = false;
      }
    }
  } catch (cause: unknown) {
    if (!active.signal.aborted && id === loadId && selectedUser === apiUser.value) {
      error.value = cause instanceof Error ? cause.message : String(cause);
      notification('error', 'History', error.value);
    }
  } finally {
    if (!active.signal.aborted && id === loadId) {
      isLoading.value = false;
    }
  }
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value) {
    filter.value = '';
    return;
  }

  awaitElement('#filter', (_, element) => (element as HTMLInputElement).focus());
};

const massAction = async (action: 'delete' | 'mark_played' | 'mark_unplayed'): Promise<void> => {
  if (0 === selected_ids.value.length || exportBlocked.value) {
    return;
  }

  const selectedUser = apiUser.value;
  const selected = [...selection.value];
  const generation = loadId;
  const active = bulkController;

  const title = {
    delete: 'Delete',
    mark_played: 'Mark watched',
    mark_unplayed: 'Mark unwatched',
  }[action];

  const { status: confirmStatus } = await useDialog().confirmDialog({
    message:
      'delete' === action
        ? `Delete ${selected_ids.value.length} selected ${1 === selected_ids.value.length ? 'record' : 'records'}? This can't be undone.`
        : `Mark ${selected_ids.value.length} selected ${1 === selected_ids.value.length ? 'record' : 'records'} as ${'mark_played' === action ? 'watched' : 'unwatched'}?`,
    confirmText: title,
    confirmColor: 'delete' === action ? 'error' : 'neutral',
  });

  if (
    true !== confirmStatus ||
    selectedUser !== apiUser.value ||
    active.signal.aborted ||
    generation !== loadId ||
    exportBlocked.value
  ) {
    return;
  }

  let urls: Array<string> = [];
  const opts: RequestOptions = { headers: { 'X-User': selectedUser }, signal: active.signal };

  massActionInProgress.value = true;

  if ('delete' === action) {
    opts.method = 'DELETE';
    urls = selected.map((item) => `/history/${item.id}`);
  }

  if ('mark_played' === action || 'mark_unplayed' === action) {
    opts.method = 'mark_played' === action ? 'POST' : 'DELETE';
    const ids = selected
      .filter((item) => ('mark_played' === action ? !item.watched : item.watched))
      .map((item) => item.id);

    urls = ids.map((value) => `/history/${value}/watch`);
  }

  try {
    notification('success', 'Action in progress', 'Updating selected history records.');

    const requests = await Promise.all(urls.map((url) => request(url, opts)));
    if (active.signal.aborted || selectedUser !== apiUser.value) {
      return;
    }
    const all_ok = requests.every((response) => 200 === response.status);

    if (!all_ok) {
      notification('error', 'Error', 'Some actions failed. Refresh history before retrying.');
    }

    if (all_ok) {
      notification(
        'success',
        'Action completed',
        'delete' === action
          ? 'Selected history records deleted.'
          : `Selected history records marked as ${'mark_played' === action ? 'watched' : 'unwatched'}.`,
      );
    }
  } catch (e) {
    if (!active.signal.aborted && selectedUser === apiUser.value) {
      notification(
        'error',
        'Error',
        `Request error. ${e instanceof Error ? e.message : String(e)}`,
      );
    }
  } finally {
    if (!active.signal.aborted && selectedUser === apiUser.value) {
      massActionInProgress.value = false;
      clearSelection();
      void loadContent(1);
    }
  }
};

const stateCallBack = async (event: Event): Promise<void> => {
  const state = (event as CustomEvent<{ clear?: boolean }>).detail;
  if (state?.clear) {
    filter.value = '';
    showFilter.value = false;
    showFilters.value = false;
    query.value = {};
    draftBlocked.value = false;
    await loadContent(1);
  }
};

watch(filter, (value: string) => {
  const currentRoute = useRoute();
  const currentRouter = useRouter();

  if (JSON.stringify(readQuery(currentRoute.query)) !== JSON.stringify(query.value)) {
    return;
  }

  if (!value) {
    if (!currentRoute?.query.filter) {
      return;
    }

    currentRouter.push({
      path: '/history',
      query: {
        ...currentRoute.query,
        filter: undefined,
      },
    });
    return;
  }

  if (currentRoute?.query.filter === value) {
    return;
  }

  currentRouter.push({
    path: '/history',
    query: {
      ...currentRoute.query,
      filter: value,
    },
  });
});

watch(
  () => JSON.stringify([route.query.page, route.query.perpage, readQuery(route.query)]),
  async () => {
    if ('history' !== route.name || writingRoute) {
      return;
    }

    const nextPage = parseInt(route.query.page as string) || 1;
    const nextPerPage = parseInt(route.query.perpage as string) || 50;
    query.value = readQuery(route.query);
    perpage.value = nextPerPage;
    draftBlocked.value = false;
    await loadContent(nextPage, true);
  },
);

watch(
  () => route.query.filter,
  (value) => {
    filter.value = 'string' === typeof value ? value : '';
    showFilter.value = !!filter.value;
  },
);

const loadFilterOptions = async (): Promise<void> => {
  optionsController.abort();
  optionsController = new AbortController();
  const active = optionsController;
  const selectedUser = apiUser.value;
  try {
    const response = await request('/history/options', {
      headers: { 'X-User': selectedUser },
      signal: active.signal,
    });
    const json = await parse_api_response<HistoryFilterOptions>(response);
    if (active.signal.aborted || selectedUser !== apiUser.value) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    filterOptions.value = json;
  } catch (cause: unknown) {
    if (!active.signal.aborted && selectedUser === apiUser.value) {
      notification(
        'error',
        'History filters',
        cause instanceof Error ? cause.message : String(cause),
      );
    }
  }
};

watch(
  apiUser,
  () => {
    loadController.abort();
    bulkController.abort();
    bulkController = new AbortController();
    items.value = [];
    total.value = 0;
    hasResults.value = false;
    exportOpen.value = false;
    massActionInProgress.value = false;
    filterOptions.value = { genres: [], libraries: [] };
    searchable.value = [];
    loadedRequest = '';
    void loadFilterOptions();
    void loadContent(1);
  },
  { flush: 'sync' },
);

query.value = readQuery(route.query);

onMounted(async (): Promise<void> => {
  window.addEventListener('history_main_link_clicked', stateCallBack);
  void loadFilterOptions();
  await loadContent(page.value ?? 1);
});

onUnmounted((): void => {
  loadController.abort();
  optionsController.abort();
  bulkController.abort();
  ++loadId;
  window.removeEventListener('history_main_link_clicked', stateCallBack);
});
</script>
