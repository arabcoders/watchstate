<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          :variant="showSearchPanel ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-search"
          :aria-expanded="showSearchPanel"
          aria-controls="events-server-filters"
          @click="showSearchPanel = !showSearchPanel"
        >
          Search events
          <UBadge v-if="appliedFilters.length" color="neutral" variant="soft" size="xs">
            {{ appliedFilters.length }}
          </UBadge>
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :disabled="
            isLoading ||
            deleteSubmitting ||
            !hasResults ||
            !!loadError ||
            searchDirty ||
            0 === total
          "
          @click="openDeleteModal"
        >
          Delete matching
        </UButton>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || deleteSubmitting"
          @click="() => void loadContent(page)"
          aria-label="Refresh events"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Event controls" class="space-y-4">
      <div class="flex flex-wrap items-end gap-3">
        <div class="w-full space-y-1.5 sm:w-44">
          <label for="event-status" class="block text-xs font-medium text-toned">Status</label>
          <USelect
            id="event-status"
            :model-value="appliedSearch.status"
            :items="statusItems"
            value-key="value"
            label-key="label"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-filter"
            class="w-full"
            :disabled="isLoading || deleteSubmitting"
            @update:model-value="applyStatus"
          />
        </div>
        <div class="min-w-0 flex-1 basis-full space-y-1.5 sm:basis-64">
          <label for="display-filter" class="block text-xs font-medium text-toned">
            Filter this page
          </label>
          <UInput
            id="display-filter"
            v-model="displayFilter"
            type="search"
            placeholder="Event name or reference"
            aria-label="Filter events on the current page"
            icon="i-lucide-list-filter"
            size="sm"
            class="w-full"
          />
        </div>
        <div class="w-36 space-y-1.5">
          <label for="events-per-page" class="block text-xs font-medium text-toned">
            Items per page
          </label>
          <USelect
            id="events-per-page"
            v-model="perpage"
            :items="perPageItems"
            value-key="value"
            label-key="label"
            color="neutral"
            variant="outline"
            size="sm"
            class="w-full"
            :disabled="isLoading || deleteSubmitting"
            @update:model-value="() => void loadContent(1)"
          />
        </div>
      </div>

      <form
        v-show="showSearchPanel"
        id="events-server-filters"
        class="space-y-4"
        @submit.prevent="void submitSearch()"
      >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <UFormField label="Event name" name="event_name">
            <UInput
              v-model="search.event"
              type="search"
              placeholder="Search event name"
              icon="i-lucide-tag"
              size="sm"
              class="w-full"
              :disabled="isLoading || deleteSubmitting"
            />
          </UFormField>

          <UFormField label="Reference" name="event_reference">
            <UInput
              v-model="search.reference"
              type="search"
              placeholder="Search reference"
              icon="i-lucide-link"
              size="sm"
              class="w-full"
              :disabled="isLoading || deleteSubmitting"
            />
          </UFormField>

          <UFormField label="Created after" name="event_after">
            <UInput
              v-model="search.after"
              type="text"
              placeholder="e.g. 2 hours ago or 2026-05-11 10:00"
              icon="i-lucide-calendar-range"
              size="sm"
              class="w-full"
              :disabled="isLoading || deleteSubmitting"
            />
          </UFormField>

          <UFormField label="Created before" name="event_before">
            <UInput
              v-model="search.before"
              type="text"
              placeholder="e.g. now, yesterday, or 2026-05-12 10:00"
              icon="i-lucide-calendar-clock"
              size="sm"
              class="w-full"
              :disabled="isLoading || deleteSubmitting"
            />
          </UFormField>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-x"
            type="button"
            :disabled="isLoading || deleteSubmitting"
            @click="resetSearch"
          >
            Clear search
          </UButton>

          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-search"
            type="submit"
            :loading="isLoading"
            :disabled="isLoading || deleteSubmitting"
          >
            Apply search
          </UButton>
        </div>
      </form>

      <div v-if="appliedFilters.length || displayFilter" class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-dimmed">Applied</span>
        <UButton
          v-for="chip in appliedFilters"
          :key="chip.key"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          :aria-label="`Remove filter ${chip.label}`"
          :disabled="isLoading || deleteSubmitting"
          @click="removeFilter(chip.key)"
        >
          {{ chip.label }}
        </UButton>
        <UButton
          v-if="displayFilter"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          :aria-label="`Remove page filter ${displayFilter}`"
          @click="displayFilter = ''"
        >
          This page: {{ displayFilter }}
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-x"
          :disabled="isLoading || deleteSubmitting"
          @click="clearFilters"
        >
          Clear all
        </UButton>
      </div>
      <p v-if="searchDirty" class="text-sm text-warning" role="status">
        Apply your search changes before deleting matching events.
      </p>
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3" aria-live="polite">
      <p v-if="hasResults" class="text-sm text-toned">
        <strong class="font-medium text-highlighted">{{ total.toLocaleString() }}</strong>
        {{ appliedFilters.length ? 'matching events' : 'events' }}
        <span class="text-dimmed">
          · {{ filteredRows.length }}{{ displayFilter ? ` of ${items.length}` : '' }} on this page
        </span>
      </p>
      <Pager
        v-if="total && 1 < last_page"
        :page="page"
        :last_page="last_page"
        :is-loading="isLoading || deleteSubmitting"
        @navigate="loadContent"
      />
    </div>

    <p v-if="isLoading && hasResults" role="status" class="text-xs text-muted">
      Refreshing events…
    </p>
    <UAlert
      v-if="isLoading && !hasResults"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading events"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-circle-alert"
      :title="hasResults ? 'Events could not be refreshed' : 'Events could not be loaded'"
    >
      <template #description>
        <div class="space-y-3">
          <p>{{ loadError }}</p>
          <p v-if="hasResults">These results may be out of date.</p>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            @click="() => void loadContent(page)"
          >
            Retry
          </UButton>
        </div>
      </template>
    </UAlert>

    <UEmpty
      v-if="!isLoading && !loadError && 0 === filteredRows.length"
      icon="i-lucide-search"
      :title="displayFilter || appliedFilters.length ? 'No matching events' : 'No events yet'"
      :description="
        displayFilter
          ? 'Clear the page filter or use Search events to search all pages.'
          : appliedFilters.length
            ? 'Try a broader search or remove an applied filter.'
            : 'Use Tasks to schedule imports & exports.'
      "
    >
      <template v-if="displayFilter || appliedFilters.length" #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-x"
          @click="clearFilters"
        >
          Clear all
        </UButton>
      </template>
    </UEmpty>

    <ul
      v-if="0 < filteredRows.length"
      aria-label="Events"
      class="ws-card divide-y divide-default overflow-hidden"
    >
      <li
        v-for="item in filteredRows"
        :key="item.id"
        class="grid min-w-0 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
      >
        <div class="min-w-0 space-y-2">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <UBadge :color="getEventStatusColor(item.status)" variant="soft" class="shrink-0">
              <UIcon
                :name="getEventStatusIcon(item.status)"
                :class="1 === item.status ? 'size-3.5 animate-spin' : 'size-3.5'"
              />
              {{ getStatusName(item) }}
            </UBadge>
            <button
              type="button"
              class="min-w-0 break-all text-left text-sm font-semibold text-highlighted hover:text-primary focus-visible:outline-primary"
              :aria-label="`View ${item.event}, event ${makeEventName(item.id)}`"
              @click="quick_view = item.id"
            >
              {{ item.event }}
            </button>
            <UTooltip v-if="item.delay_by" text="Dispatch delay">
              <span class="inline-flex items-center gap-1 text-xs text-warning">
                <UIcon name="i-lucide-clock-3" class="size-3.5" />
                {{ item.delay_by }}s delay
              </span>
            </UTooltip>
          </div>
          <p v-if="item.reference" class="break-all text-sm text-default">
            <span class="text-toned">Reference:</span> {{ item.reference }}
          </p>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-toned">
            <UTooltip :text="item.id">
              <span class="font-mono"> #{{ makeEventName(item.id) }} </span>
            </UTooltip>
            <UTooltip :text="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)">
              <span>Created {{ moment(item.created_at).fromNow() }}</span>
            </UTooltip>
            <UTooltip
              v-if="item.updated_at"
              :text="moment(item.updated_at).format(TOOLTIP_DATE_FORMAT)"
            >
              <span>Updated {{ moment(item.updated_at).fromNow() }}</span>
            </UTooltip>
          </div>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
          <UTooltip
            :text="
              1 === item.status
                ? 'Running events cannot be run again or cancelled.'
                : 0 === item.status
                  ? 'Cancel stops dispatch and clears this event’s saved logs.'
                  : 'Run again queues this action again and clears its saved logs.'
            "
          >
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              :icon="0 === item.status ? 'i-lucide-ban' : 'i-lucide-rotate-ccw'"
              :disabled="1 === item.status || isLoading || !!loadError || deleteSubmitting"
              @click="resetEvent(item, 0 === item.status ? 4 : 0)"
            >
              {{ 0 === item.status ? 'Cancel' : 'Run again' }}
            </UButton>
          </UTooltip>
          <UTooltip
            :text="1 === item.status ? 'Running events cannot be deleted.' : 'Delete event'"
          >
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :aria-label="`Delete event ${makeEventName(item.id)}`"
              :disabled="1 === item.status || isLoading || !!loadError || deleteSubmitting"
              @click="deleteItem(item)"
            >
              Delete
            </UButton>
          </UTooltip>
        </div>
      </li>
    </ul>

    <div v-if="total && 1 < last_page" class="flex flex-wrap items-center justify-between gap-3">
      <Pager
        :page="page"
        :last_page="last_page"
        :is-loading="isLoading || deleteSubmitting"
        @navigate="loadContent"
      />
      <div class="text-xs text-toned">Page {{ page }} of {{ last_page }}</div>
    </div>

    <section class="space-y-3">
      <button
        type="button"
        class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
        :aria-expanded="show_page_tips"
        aria-controls="event-action-help"
        @click="show_page_tips = !show_page_tips"
      >
        <UIcon
          :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          class="size-4 text-toned"
        />
        <UIcon name="i-lucide-info" class="size-4 text-toned" />
        <span>About event actions</span>
      </button>
      <div v-if="show_page_tips" id="event-action-help" class="text-sm leading-6 text-toned">
        <ul class="list-disc space-y-2 pl-5">
          <li>Run again queues the action again and clears its saved logs.</li>
          <li>Cancel stops a pending event from being dispatched and clears its saved logs.</li>
          <li>Running events cannot be run again, cancelled, or deleted.</li>
          <li>
            Delete matching applies across all pages, not the page filter. Pending events are
            included only when you opt in.
          </li>
        </ul>
      </div>
    </section>

    <UModal v-model:open="quickViewOpen" :title="quickViewTitle" :ui="quickViewModalUi">
      <template #body>
        <EventView
          v-if="quick_view"
          :id="quick_view"
          :actions-target="`#${eventActionsId}`"
          @deleted="(item) => deletedItem(item.id)"
          @open-event="(id) => (quick_view = id)"
        />
      </template>
      <template #footer>
        <div :id="eventActionsId" class="w-full" />
      </template>
    </UModal>

    <UModal
      v-model:open="deleteModalOpen"
      title="Delete events"
      :dismissible="!deleteSubmitting"
      :ui="deleteModalUi"
    >
      <template #body>
        <div class="space-y-4">
          <div
            class="rounded-md border border-default bg-elevated/20 px-4 py-3 text-sm text-default"
          >
            <p>
              Delete events matching these search filters across all pages. This cannot be undone.
            </p>
            <p class="mt-2 wrap-break-word font-medium text-highlighted">
              {{
                deleteFilterSummary ||
                'Any status, all event names and references, any creation time'
              }}
            </p>
            <p class="mt-2 text-toned">
              Running events are never deleted. Pending events are excluded unless you opt in below.
              The page filter does not limit deletion.
            </p>
          </div>

          <label
            class="flex items-start gap-3 rounded-md border border-default bg-elevated/30 px-4 py-3 text-sm text-default"
          >
            <UCheckbox v-model="deleteIncludePending" color="primary" />
            <span class="font-medium text-highlighted">Include pending</span>
          </label>
        </div>
      </template>

      <template #footer>
        <div class="flex w-full flex-wrap items-center justify-end gap-2">
          <UButton
            color="error"
            variant="solid"
            size="sm"
            icon="i-lucide-trash-2"
            :loading="deleteSubmitting"
            :disabled="deleteSubmitting"
            @click="confirmDeleteAll"
          >
            Delete events
          </UButton>
        </div>
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, useId, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import {
  UAlert,
  UBadge,
  UButton,
  UCheckbox,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  UModal,
  USelect,
  UTooltip,
} from '#components';
import EventView from '~/components/EventView.vue';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import { useDialog } from '~/composables/useDialog';
import type { EventsItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  TOOLTIP_DATE_FORMAT,
  getEventStatusColor,
  getEventStatusIcon,
  makeEventName,
  notification,
  parse_api_response,
  request,
} from '~/utils';

type EventStatus = {
  id: number;
  name: string;
};

type EventsResponse = {
  items: Array<EventsItem>;
  statuses: Array<EventStatus>;
  paging: { page: number; perpage: number; total: number };
};

type SearchState = {
  status: string;
  event: string;
  reference: string;
  before: string;
  after: string;
};

const ANY_STATUS = '__any__';
const DEFAULT_PER_PAGE = 25;

const pageShell = requireTopLevelPageShell('events');

const route = useRoute();
const router = useRouter();
useHead({ title: 'Events' });

const getRouteQueryValue = (
  value: string | null | Array<string | null> | undefined,
  fallback = '',
): string => {
  if (Array.isArray(value)) {
    return value[0] ?? fallback;
  }

  return value ?? fallback;
};

const toPositiveInt = (value: string, fallback: number): number => {
  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) || 1 > parsed ? fallback : parsed;
};

const parseStatusScope = (value: string): string => {
  const normalized = value.trim();
  return '' === normalized || ANY_STATUS === normalized ? ANY_STATUS : normalized;
};

const defaultSearchState = (): SearchState => ({
  status: ANY_STATUS,
  event: '',
  reference: '',
  before: '',
  after: '',
});

const createSearchState = (query: typeof route.query): SearchState => ({
  status: parseStatusScope(getRouteQueryValue(query.status, ANY_STATUS)),
  event: getRouteQueryValue(query.event, ''),
  reference: getRouteQueryValue(query.reference, ''),
  before: getRouteQueryValue(query.before, ''),
  after: getRouteQueryValue(query.after, ''),
});

const total = ref<number>(0);
const page = ref<number>(toPositiveInt(getRouteQueryValue(route.query.page, '1'), 1));
const perpage = ref<number>(
  toPositiveInt(
    getRouteQueryValue(route.query.perpage, String(DEFAULT_PER_PAGE)),
    DEFAULT_PER_PAGE,
  ),
);
const isLoading = ref<boolean>(true);
const hasResults = ref(false);
const loadError = ref('');
const deleteSubmitting = ref<boolean>(false);
const items = ref<Array<EventsItem>>([]);
const statuses = ref<Array<EventStatus>>([]);
const displayFilter = ref<string>(getRouteQueryValue(route.query.filter, ''));
const showSearchPanel = ref<boolean>(
  ['event', 'reference', 'before', 'after'].some((key) => {
    const value = route.query[key];
    return Array.isArray(value) ? !!value[0] : !!value;
  }),
);
const search = ref<SearchState>(createSearchState(route.query));
const appliedSearch = ref<SearchState>({ ...search.value });
const deleteSearch = ref<SearchState>(defaultSearchState());
const quick_view = ref<string | null>(null);
const show_page_tips = useStorage<boolean>('show_page_tips', true);
const deleteModalOpen = ref<boolean>(false);
const deleteIncludePending = ref<boolean>(false);
let loadController: AbortController | null = null;
let loadId = 0;
let disposed = false;

const quickViewOpen = computed({
  get: () => null !== quick_view.value,
  set: (value: boolean) => {
    if (false === value) {
      quick_view.value = null;
    }
  },
});

const quickViewTitle = computed(() =>
  null === quick_view.value ? 'Event' : `#${makeEventName(quick_view.value)}`,
);

const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));
const perPageItems = computed(() =>
  [...new Set([DEFAULT_PER_PAGE, 50, 100, 200, perpage.value])]
    .sort((left, right) => left - right)
    .map((value) => ({ label: String(value), value })),
);

const eventActionsId = useId();
const quickViewModalUi = {
  content: 'max-w-5xl',
  body: 'p-4 sm:p-5',
};

const deleteModalUi = {
  content: 'max-w-2xl',
  body: 'p-4 sm:p-5',
  footer: 'border-t border-default/70 px-4 py-4 sm:px-5',
};

const statusLookup = computed<Record<number, string>>(() =>
  statuses.value.reduce((map: Record<number, string>, item) => {
    map[item.id] = item.name;
    return map;
  }, {}),
);

const statusItems = computed<Array<{ label: string; value: string }>>(() => [
  { label: 'Any status', value: ANY_STATUS },
  ...statuses.value.map((status) => ({
    label: status.name,
    value: String(status.id),
  })),
]);

const filterLabels: Record<keyof SearchState, string> = {
  status: 'Status',
  event: 'Event name',
  reference: 'Reference',
  after: 'Created after',
  before: 'Created before',
};

const describeFilters = (state: SearchState) => {
  return (Object.keys(filterLabels) as Array<keyof SearchState>).flatMap((key) => {
    const value = state[key].trim();
    if (!value || ('status' === key && ANY_STATUS === value)) {
      return [];
    }

    const display =
      'status' === key
        ? statusItems.value.find((item) => value === item.value)?.label || value
        : value;

    return [{ key, label: `${filterLabels[key]}: ${display}` }];
  });
};

const appliedFilters = computed(() => describeFilters(appliedSearch.value));
const deleteFilterSummary = computed(() =>
  describeFilters(deleteSearch.value)
    .map((chip) => chip.label)
    .join(' · '),
);
const searchDirty = computed(() =>
  (Object.keys(filterLabels) as Array<keyof SearchState>).some(
    (key) => search.value[key].trim() !== appliedSearch.value[key].trim(),
  ),
);

const filteredRows = computed<Array<EventsItem>>(() => {
  if (!displayFilter.value) {
    return items.value;
  }

  const toLower = displayFilter.value.toLowerCase();

  return items.value.filter((item) => {
    return Object.keys(item).some((key) => {
      const value = item[key as keyof EventsItem] as unknown;

      if ('object' === typeof value && null !== value) {
        return Object.values(value).some((nestedValue) => {
          return 'string' === typeof nestedValue
            ? nestedValue.toLowerCase().includes(toLower)
            : false;
        });
      }

      return 'string' === typeof value ? value.toLowerCase().includes(toLower) : false;
    });
  });
});

const getStatusName = (item: EventsItem): string => {
  return item.status_name || statusLookup.value[item.status] || String(item.status);
};

const buildServerQuery = (pageNumber: number, requestedPerPage: number): URLSearchParams => {
  const queryParams = new URLSearchParams();
  queryParams.append('page', pageNumber.toString());
  queryParams.append('perpage', requestedPerPage.toString());
  queryParams.append('all', '1');

  for (const [key, value] of Object.entries(appliedSearch.value)) {
    if ('status' === key && ANY_STATUS === value) {
      continue;
    }

    const normalized = value.trim();
    if (normalized) {
      queryParams.append(key, normalized);
    }
  }

  return queryParams;
};

const buildRouteQuery = (
  pageNumber: number,
  requestedPerPage: number,
): Record<string, string | number | undefined> => {
  const historyQuery: Record<string, string | number | undefined> = {
    perpage: requestedPerPage,
    page: pageNumber,
  };

  if (displayFilter.value) {
    historyQuery.filter = displayFilter.value;
  }

  for (const [key, value] of Object.entries(appliedSearch.value)) {
    if ('status' === key && ANY_STATUS === value) {
      continue;
    }

    if (!value.trim()) {
      continue;
    }

    historyQuery[key] = value.trim();
  }

  return historyQuery;
};

let loadedQuery = '';

const loadContent = async (
  pageNumber: number = 1,
  updateHistory: boolean = true,
): Promise<void> => {
  if (disposed || 'events' !== route.name) {
    return;
  }

  loadController?.abort();
  const active = new AbortController();
  loadController = active;
  const id = ++loadId;
  pageNumber = toPositiveInt(pageNumber.toString(), 1);
  page.value = pageNumber;
  const requestedPerPage = toPositiveInt(perpage.value.toString(), DEFAULT_PER_PAGE);
  const queryParams = buildServerQuery(pageNumber, requestedPerPage);

  isLoading.value = true;
  loadError.value = '';
  if (loadedQuery !== queryParams.toString()) {
    hasResults.value = false;
    items.value = [];
    total.value = 0;
  }

  try {
    const response = await request(`/system/events?${queryParams.toString()}`, {
      signal: active.signal,
    });
    const json = await parse_api_response<EventsResponse>(response);
    if (active.signal.aborted || id !== loadId || 'events' !== route.name) {
      return;
    }

    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const lastAvailablePage = Math.max(1, Math.ceil(json.paging.total / json.paging.perpage));
    if (lastAvailablePage < pageNumber) {
      await loadContent(lastAvailablePage);
      return;
    }

    page.value = json.paging.page;
    perpage.value = json.paging.perpage;
    total.value = json.paging.total;
    items.value = json.items ?? [];
    statuses.value = json.statuses ?? [];
    hasResults.value = true;
    loadedQuery = queryParams.toString();

    const titleParts = [`Events - Page #${pageNumber}`];
    if (appliedFilters.value.length) {
      titleParts.push(appliedFilters.value.map((chip) => chip.label).join(' | '));
    }
    if (displayFilter.value) {
      titleParts.push(`this page: ${displayFilter.value}`);
    }
    useHead({ title: titleParts.join(' | ') });

    if (true === updateHistory) {
      await router.push({ path: '/events', query: buildRouteQuery(page.value, perpage.value) });
    }
  } catch (e: unknown) {
    if (!active.signal.aborted && id === loadId) {
      loadError.value = e instanceof Error ? e.message : String(e);
      notification('error', 'Events', loadError.value);
    }
  } finally {
    if (!active.signal.aborted && id === loadId) {
      isLoading.value = false;
    }
  }
};

const submitSearch = async (): Promise<void> => {
  showSearchPanel.value = true;
  search.value = Object.fromEntries(
    Object.entries(search.value).map(([key, value]) => [key, value.trim()]),
  ) as SearchState;
  appliedSearch.value = { ...search.value };
  await loadContent(1);
};

const applyStatus = (status: string): void => {
  search.value.status = status;
  appliedSearch.value = { ...appliedSearch.value, status };
  void loadContent(1);
};

const removeFilter = (key: keyof SearchState): void => {
  const value = 'status' === key ? ANY_STATUS : '';
  search.value[key] = value;
  appliedSearch.value = { ...appliedSearch.value, [key]: value };
  void loadContent(1);
};

const resetSearch = (): void => {
  search.value = defaultSearchState();
  appliedSearch.value = { ...search.value };
  void loadContent(1);
};

const clearFilters = (): void => {
  displayFilter.value = '';
  resetSearch();
};

onMounted(() => void loadContent(page.value));

onUnmounted(() => {
  disposed = true;
  loadController?.abort();
  loadId++;
});

watch(
  () => route.query,
  (query, previous) => {
    if ('events' !== route.name) {
      return;
    }
    displayFilter.value = getRouteQueryValue(query.filter, '');
    if (
      ['page', 'perpage', 'status', 'event', 'reference', 'before', 'after'].every(
        (key) => getRouteQueryValue(query[key]) === getRouteQueryValue(previous[key]),
      )
    ) {
      return;
    }

    const nextPage = toPositiveInt(getRouteQueryValue(query.page, '1'), 1);
    const nextPerPage = toPositiveInt(
      getRouteQueryValue(query.perpage, String(DEFAULT_PER_PAGE)),
      DEFAULT_PER_PAGE,
    );
    const nextSearch = createSearchState(query);
    if (
      nextPage === page.value &&
      nextPerPage === perpage.value &&
      JSON.stringify(nextSearch) === JSON.stringify(appliedSearch.value)
    ) {
      return;
    }

    page.value = nextPage;
    perpage.value = nextPerPage;
    search.value = nextSearch;
    appliedSearch.value = { ...nextSearch };
    showSearchPanel.value = ['event', 'reference', 'before', 'after'].some(
      (key) => !!nextSearch[key as keyof SearchState],
    );
    deleteModalOpen.value = false;
    void loadContent(nextPage, false);
  },
);

const deletedItem = (id: string): void => {
  const removed = items.value.some((item) => id === item.id);
  items.value = items.value.filter((item) => item.id !== id);
  if (removed) {
    total.value = Math.max(0, total.value - 1);
  }

  if (id === quick_view.value) {
    quick_view.value = null;
  }

  void loadContent(page.value, false);
};

const deleteItem = async (item: EventsItem): Promise<void> => {
  if (1 === item.status) {
    notification('warning', 'Unavailable', 'Running events cannot be deleted.');
    return;
  }

  const generation = loadId;
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Delete event?',
    message: `Delete #${makeEventName(item.id)} & its saved logs? This can't be undone.`,
    confirmText: 'Delete',
    confirmColor: 'error',
  });

  if (true !== confirmStatus || disposed || generation !== loadId) {
    return;
  }

  try {
    const response = await request(`/system/events/${item.id}`, { method: 'DELETE' });
    const json = await parse_api_response<EventsItem>(response);
    if ('error' in json) {
      notification('error', 'Could not delete event', `${json.error.code}: ${json.error.message}`);
      return;
    }
    if (!response.ok) {
      notification('error', 'Could not delete event', `${response.status}: ${response.statusText}`);
      return;
    }

    if (disposed) {
      return;
    }
    deletedItem(item.id);
    notification(
      'success',
      'Event deleted',
      `Deleted #${makeEventName(item.id)} & its saved logs.`,
    );
  } catch (e: unknown) {
    notification('error', 'Could not delete event', e instanceof Error ? e.message : 'Try again.');
  }
};

const resetEvent = async (item: EventsItem, status: number = 0): Promise<void> => {
  if (1 === item.status) {
    notification('warning', 'Unavailable', 'Running events cannot be run again or cancelled.');
    return;
  }

  const generation = loadId;
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 4 === status ? 'Cancel queued event?' : 'Run event again?',
    message:
      4 === status
        ? `Cancel #${makeEventName(item.id)}? Its saved logs will be cleared.`
        : `Queue #${makeEventName(item.id)} again? This repeats its action & clears its saved logs.`,
    confirmColor: 'neutral',
    confirmText: 4 === status ? 'Cancel event' : 'Run again',
  });

  if (true !== confirmStatus || disposed || generation !== loadId) {
    return;
  }

  try {
    const response = await request(`/system/events/${item.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        status,
        reset_logs: true,
      }),
    });

    const json = await parse_api_response<EventsItem>(response);

    if ('error' in json) {
      notification('error', 'Could not change event', `${json.error.code}: ${json.error.message}`);
      return;
    }

    if (200 !== response.status) {
      notification('error', 'Could not change event', `${response.status}: ${response.statusText}`);
      return;
    }

    if (disposed) {
      return;
    }

    notification(
      'success',
      'Event updated',
      `Event '${makeEventName(item.id)}' ${4 === status ? 'cancelled' : 'queued again'}.`,
    );
    await loadContent(page.value, false);
  } catch (e: unknown) {
    notification('error', 'Could not change event', e instanceof Error ? e.message : 'Try again.');
  }
};

const buildDeleteQuery = (): URLSearchParams => {
  const queryParams = new URLSearchParams();

  if (deleteIncludePending.value) {
    queryParams.append('include_pending', '1');
  }

  for (const [key, value] of Object.entries(deleteSearch.value)) {
    if ('status' === key && ANY_STATUS === value) {
      continue;
    }

    const normalized = value.trim();
    if (!normalized) {
      continue;
    }

    queryParams.append(key, normalized);
  }

  return queryParams;
};

const openDeleteModal = (): void => {
  deleteSearch.value = { ...appliedSearch.value };
  deleteIncludePending.value = false;
  deleteModalOpen.value = true;
};

const confirmDeleteAll = async (): Promise<void> => {
  try {
    deleteSubmitting.value = true;

    const response = await request(`/system/events/?${buildDeleteQuery().toString()}`, {
      method: 'DELETE',
    });

    const json = await parse_api_response<{ deleted: number; matched: number }>(response);
    if (disposed) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Could not delete events', `${json.error.code}: ${json.error.message}`);
      return;
    }
    if (!response.ok) {
      notification(
        'error',
        'Could not delete events',
        `${response.status}: ${response.statusText}`,
      );
      return;
    }

    deleteModalOpen.value = false;
    quick_view.value = null;
    notification(
      'success',
      'Events deleted',
      `Deleted ${json.deleted} of ${json.matched} matching events.`,
    );
    await loadContent(page.value);
  } catch (e: unknown) {
    notification('error', 'Could not delete events', e instanceof Error ? e.message : 'Try again.');
  } finally {
    deleteSubmitting.value = false;
  }
};

watch(displayFilter, (value: string) => {
  if ('events' !== route.name) {
    return;
  }
  if (!value) {
    if (!route.query.filter) {
      return;
    }

    void router.push({ path: '/events', query: { ...route.query, filter: undefined } });
    return;
  }

  if (route.query.filter === value) {
    return;
  }

  void router.push({ path: '/events', query: { ...route.query, filter: value } });
});
</script>
