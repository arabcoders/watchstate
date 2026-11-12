<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UInput
          v-if="showFilter"
          id="filter"
          v-model="filter"
          type="search"
          placeholder="Filter displayed results"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <UButton
          color="neutral"
          :variant="showFilter ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-filter"
          @click="toggleFilter"
        >
          <span class="hidden sm:inline">Page filter</span>
        </UButton>

        <USelect
          v-model="historyView"
          :items="historyViewItems"
          value-key="value"
          label-key="label"
          color="neutral"
          variant="outline"
          size="sm"
          class="w-36"
          aria-label="History view"
        />

        <USelect
          v-model="perpage"
          :items="perPageItems"
          value-key="value"
          label-key="label"
          color="neutral"
          variant="outline"
          size="sm"
          class="w-40"
          :disabled="isLoading"
          @update:model-value="() => void loadContent(1)"
        />

        <UButton
          color="neutral"
          :variant="showFilters ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-search"
          @click="showFilters = !showFilters"
        >
          <span class="hidden sm:inline">Filters</span>
        </UButton>

        <UButton
          color="neutral"
          :variant="pageSelected ? 'soft' : 'outline'"
          size="sm"
          :icon="pageSelected ? 'i-lucide-square' : 'i-lucide-square-check-big'"
          :disabled="isLoading || massActionInProgress || 0 === filteredItems.length"
          @click="selectRecords(filteredItems, !pageSelected)"
        >
          <span class="hidden sm:inline">{{ pageSelected ? 'Unselect page' : 'Select page' }}</span>
        </UButton>

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
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent(page)"
        >
          <span class="hidden sm:inline">Reload</span>
        </UButton>
      </template>
    </PageHeader>

    <HistoryFilters
      v-show="showFilters"
      :query="query"
      :searchable="searchable"
      :options="filterOptions"
      :loading="isLoading || massActionInProgress"
      @apply="applyFilters"
      @draft-state="draftBlocked = !$event.valid || $event.dirty"
    />

    <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-toned">
      <span
        >{{ matchingCount }} matching records<span v-if="filter">
          · {{ filteredItems.length }} displayed on this page</span
        ></span
      >
      <span v-if="draftBlocked">Apply filters to update results &amp; export.</span>
      <span v-else-if="filter">Matching export is disabled while the page filter is active.</span>
    </div>

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

    <div
      v-if="selected_ids.length > 0"
      class="ws-card flex flex-wrap items-center justify-between gap-3 px-3 py-3"
    >
      <div class="flex flex-wrap items-center gap-2">
        <UBadge color="neutral" variant="soft" size="sm">{{ selected_ids.length }}</UBadge>
        <span class="text-xs text-toned">selected across pages</span>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          :disabled="massActionInProgress"
          @click="clearSelection"
          >Clear selection</UButton
        >

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="massActionInProgress"
          :disabled="massActionInProgress || isLoading || draftBlocked"
          @click="() => void massAction('delete')"
        >
          Delete
        </UButton>

        <UButton
          color="neutral"
          variant="soft"
          size="sm"
          icon="i-lucide-eye"
          :loading="massActionInProgress"
          :disabled="massActionInProgress || isLoading || draftBlocked"
          @click="() => void massAction('mark_played')"
        >
          Mark Played
        </UButton>

        <UButton
          color="neutral"
          variant="soft"
          size="sm"
          icon="i-lucide-eye-off"
          :loading="massActionInProgress"
          :disabled="massActionInProgress || isLoading || draftBlocked"
          @click="() => void massAction('mark_unplayed')"
        >
          Mark Unplayed
        </UButton>
      </div>

      <div class="text-xs text-toned">{{ filteredItems.length }} displayed</div>
    </div>

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
    </div>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="filteredItems.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No items found"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>
            No items found.
            <span v-if="activeSearchSummary">
              For
              <code>{{ activeSearchSummary }}</code>
            </span>
            <span v-if="filter">
              For
              <code
                ><strong>Filter</strong>: <strong>{{ filter }}</strong></code
              >
            </span>
          </p>

          <code
            v-if="error"
            class="block rounded-md border border-default bg-elevated/60 p-3 text-xs"
          >
            {{ error }}
          </code>
        </div>
      </template>
    </UAlert>

    <HistoryCompactView
      v-else-if="'compact' === historyView"
      :items="filteredItems"
      :selected-ids="selected_ids"
      :poster-enable="poster_enable"
      :user="apiUser"
      @select="toggleSelected"
    />

    <HistoryDetailedView
      v-else
      :items="filteredItems"
      :selected-ids="selected_ids"
      :poster-enable="poster_enable"
      :user="apiUser"
      @select="toggleSelected"
      @expand="toggleExpanded"
    />

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
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
import { UAlert, UBadge, UButton, UInput, USelect } from '#components';
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
const historyViewItems = [
  { label: 'Detailed', value: 'detailed', icon: 'i-lucide-layout-grid' },
  { label: 'Compact', value: 'compact', icon: 'i-lucide-list' },
];

const items = ref<Array<HistoryListItem>>([]);
const searchable = ref<Array<HistorySearchableField>>([]);
const error = ref('');

const page = ref<number>(parseInt(route.query.page as string) || 1);
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
  () => isLoading.value || massActionInProgress.value || draftBlocked.value || !hasResults.value,
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

const perPageItems = [50, 100, 200, 400, 500].map((value) => ({
  label: `${value} per page`,
  value,
}));

const activeSearchSummary = computed<string>(() => {
  return Object.entries(query.value)
    .map(([key, value]) => `${key}: ${value}`)
    .join(', ');
});

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
  isLoading.value = true;
  hasResults.value = false;
  error.value = '';
  items.value = [];
  total.value = 0;

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
    if (active.signal.aborted || id !== loadId || selectedUser !== apiUser.value) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error?.message || 'Failed to load History.');
    }
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    if ('history' !== route.name) {
      return;
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
    applyQuery(filters, total.value, items.value);
    hasResults.value = true;
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
    mark_played: 'Mark as played',
    mark_unplayed: 'Mark as unplayed',
  }[action];

  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: `Are you sure you want to '${title}' ${selected_ids.value.length} item/s?`,
    confirmColor: 'delete' === action ? 'error' : 'primary',
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
    notification(
      'success',
      'Action in progress',
      `Processing Mass '${title}' request. Please wait...`,
    );

    const requests = await Promise.all(urls.map((url) => request(url, opts)));
    if (active.signal.aborted || selectedUser !== apiUser.value) {
      return;
    }
    const all_ok = requests.every((response) => 200 === response.status);

    if (!all_ok) {
      notification(
        'error',
        'Error',
        'Some requests failed. Please check the console for more details.',
      );
    }

    if (all_ok) {
      notification('success', 'Success', `Mass '${title}' request completed.`);
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
