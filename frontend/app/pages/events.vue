<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0 space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <span>{{ pageShell.pageLabel }}</span>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-end gap-2">
        <form v-if="showDisplayFilter" class="w-full sm:w-72" @submit.prevent="void loadContent(1)">
          <UInput
            id="display-filter"
            v-model.lazy="displayFilter"
            type="search"
            placeholder="Filter displayed results"
            icon="i-lucide-filter"
            size="sm"
            class="w-full"
          />
        </form>

        <UButton
          color="neutral"
          :variant="showDisplayFilter ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-filter"
          @click="toggleDisplayFilter"
          label="Filter"
        />

        <UButton
          color="neutral"
          :variant="showSearchPanel ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-search"
          @click="showSearchPanel = !showSearchPanel"
          label="Search"
        />

        <UTooltip v-if="items.length > 0" text="Delete events.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            :disabled="isLoading"
            @click="openDeleteModal"
            label="Delete"
          />
        </UTooltip>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent(page, false)"
          label="Reload"
        />
      </div>
    </div>

    <UCard v-if="showSearchPanel" class="border border-default/70 shadow-sm" :ui="searchCardUi">
      <template #header>
        <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
          <UIcon name="i-lucide-search" class="size-4 text-toned" />
          <span>Search Events</span>
        </div>
      </template>

      <form class="space-y-4" @submit.prevent="void submitSearch()">
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
          <UFormField label="Status" name="event_status">
            <USelect
              v-model="search.status"
              :items="statusItems"
              value-key="value"
              label-key="label"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-filter"
              class="w-full"
              :disabled="isLoading"
            />
          </UFormField>

          <UFormField label="Event name" name="event_name">
            <UInput
              v-model="search.event"
              type="search"
              placeholder="Search event name"
              icon="i-lucide-tag"
              size="sm"
              class="w-full"
              :disabled="isLoading"
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
              :disabled="isLoading"
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
              :disabled="isLoading"
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
              :disabled="isLoading"
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
            :disabled="isLoading"
            @click="resetSearch"
          >
            Reset
          </UButton>

          <UButton
            color="primary"
            size="sm"
            icon="i-lucide-search"
            type="submit"
            :loading="isLoading"
          >
            Search
          </UButton>
        </div>
      </form>
    </UCard>

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
      <div class="text-xs text-toned">Page {{ page }} of {{ last_page }}</div>
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
      v-else-if="filteredRows.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No items found"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>No items found.</p>
          <p v-if="hasActiveServerFilters">
            Search filters: <code>{{ activeServerFilterSummary }}</code>
          </p>
          <p v-if="displayFilter">
            Displayed-results filter: <code>{{ displayFilter }}</code>
          </p>
        </div>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="item in filteredRows"
        :key="item.id"
        class="h-full border border-default/70 shadow-sm"
        :ui="eventCardUi"
      >
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <UTooltip :text="`#${makeEventName(item.id)}`">
                <button
                  type="button"
                  class="block truncate text-left text-base font-semibold text-highlighted hover:text-primary"
                  @click="quick_view = item.id"
                >
                  #{{ makeEventName(item.id) }}
                </button>
              </UTooltip>
            </div>

            <div class="flex shrink-0 items-center gap-2">
              <UTooltip
                v-if="item.delay_by"
                text="The event dispatching was delayed by this many seconds."
              >
                <UBadge color="warning" variant="soft">
                  <span class="inline-flex items-center gap-1">
                    <UIcon name="i-lucide-clock-3" class="size-3.5" />
                    <span>{{ item.delay_by }}s</span>
                  </span>
                </UBadge>
              </UTooltip>

              <UBadge :color="getEventStatusColor(item.status)" variant="soft">
                <span class="inline-flex items-center gap-1">
                  <UIcon
                    :name="getEventStatusIcon(item.status)"
                    :class="getEventStatusIconClass(item.status)"
                  />
                  <span>{{ getStatusName(item) }}</span>
                </span>
              </UBadge>
            </div>
          </div>
        </template>

        <div class="space-y-3">
          <div v-if="item.reference" class="flex flex-wrap items-center gap-2">
            <UBadge color="neutral" variant="outline">
              <span class="inline-flex items-center gap-1">
                <UIcon name="i-lucide-link" class="size-3.5" />
                <span>{{ item.reference }}</span>
              </span>
            </UBadge>
          </div>

          <div class="grid grid-cols-2 gap-2.5">
            <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
              <div
                class="flex min-w-0 flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-3"
              >
                <span
                  class="inline-flex shrink-0 items-center gap-2 text-xs font-medium text-toned"
                >
                  <UIcon name="i-lucide-calendar" class="size-4" />
                  <span>Created</span>
                </span>

                <UTooltip
                  :text="`Created at: ${moment(item.created_at).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  <span class="min-w-0 cursor-help text-default sm:ml-auto sm:text-right">
                    {{ moment(item.created_at).fromNow() }}
                  </span>
                </UTooltip>
              </div>
            </div>

            <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
              <div
                class="flex min-w-0 flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-3"
              >
                <span
                  class="inline-flex shrink-0 items-center gap-2 text-xs font-medium text-toned"
                >
                  <UIcon
                    :name="
                      1 === item.status && !item.updated_at
                        ? 'i-lucide-loader-circle'
                        : 'i-lucide-calendar-days'
                    "
                    :class="
                      1 === item.status && !item.updated_at ? 'size-4 animate-spin' : 'size-4'
                    "
                  />
                  <span>Updated</span>
                </span>

                <template v-if="!item.updated_at">
                  <span class="min-w-0 text-default sm:ml-auto sm:text-right">
                    {{ 1 === item.status ? 'Running' : 0 === item.status ? 'Pending' : 'None' }}
                  </span>
                </template>

                <template v-else>
                  <UTooltip
                    :text="`Updated at: ${moment(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                  >
                    <span class="min-w-0 cursor-help text-default sm:ml-auto sm:text-right">
                      {{ moment(item.updated_at).fromNow() }}
                    </span>
                  </UTooltip>
                </template>
              </div>
            </div>
          </div>
        </div>

        <template #footer>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <UBadge color="neutral" variant="soft">
              <span class="inline-flex items-center gap-1">
                <UIcon name="i-lucide-tag" class="size-3.5" />
                <span>{{ item.event }}</span>
              </span>
            </UBadge>

            <div class="flex flex-wrap items-center justify-end gap-2">
              <UTooltip
                :text="
                  1 === item.status
                    ? 'Running events cannot be reset or cancelled.'
                    : 0 === item.status
                      ? 'Cancel event'
                      : 'Reset event'
                "
              >
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-rotate-ccw"
                  :disabled="1 === item.status"
                  @click="resetEvent(item, 0 === item.status ? 4 : 0)"
                >
                  {{ 0 === item.status ? 'Cancel' : 'Reset' }}
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
                  :disabled="1 === item.status"
                  @click="deleteItem(item)"
                >
                  Delete
                </UButton>
              </UTooltip>
            </div>
          </div>
        </template>
      </UCard>
    </div>

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
      <div class="text-xs text-toned">Page {{ page }} of {{ last_page }}</div>
    </div>

    <UCard class="border border-default/70 shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
          @click="show_page_tips = !show_page_tips"
        >
          <UIcon
            :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-4 text-toned"
          />
          <UIcon name="i-lucide-info" class="size-4 text-toned" />
          <span>Tips</span>
        </button>
      </template>

      <div v-if="show_page_tips" class="text-sm leading-6 text-default">
        <ul class="list-disc space-y-2 pl-5">
          <li>Resetting an event will return it to pending so it can be dispatched again.</li>
          <li>
            Cancelling a pending event marks it as cancelled and stops it from being dispatched.
          </li>
          <li>
            Events with status of <UBadge color="warning" variant="soft" size="sm">Running</UBadge>
            cannot be reset, cancelled, or deleted.
          </li>
          <li>
            Bulk delete removes events matching the current search filters. Pending events are only
            included when you opt in during confirmation.
          </li>
          <li>
            The top <UIcon name="i-lucide-filter" class="inline size-4 align-text-bottom" /> filter
            only narrows the currently displayed cards. Use
            <UIcon name="i-lucide-search" class="inline size-4 align-text-bottom" /> search to query
            the backend.
          </li>
        </ul>
      </div>
    </UCard>

    <UModal v-model:open="quickViewOpen" :title="quickViewTitle" :ui="quickViewModalUi">
      <template #body>
        <EventView v-if="quick_view" :id="quick_view" @delete="(item) => deleteItem(item)" />
      </template>
    </UModal>

    <UModal v-model:open="deleteModalOpen" title="Delete events" :ui="deleteModalUi">
      <template #body>
        <div class="space-y-4">
          <div
            class="rounded-md border border-default bg-elevated/20 px-4 py-3 text-sm text-default"
          >
            <p>Delete the matching events.</p>
          </div>

          <label
            class="flex items-start gap-3 rounded-md border border-default bg-default px-4 py-3 text-sm text-default"
          >
            <UCheckbox v-model="deleteIncludePending" color="warning" />
            <span class="font-medium text-highlighted">Also delete pending events</span>
          </label>
        </div>
      </template>

      <template #footer>
        <div class="flex w-full flex-wrap items-center justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            :disabled="deleteSubmitting"
            @click="deleteModalOpen = false"
          >
            Cancel
          </UButton>

          <UButton
            color="error"
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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import Pager from '~/components/Pager.vue';
import { useDialog } from '~/composables/useDialog';
import type { EventsItem, GenericError, GenericResponse } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  TOOLTIP_DATE_FORMAT,
  awaitElement,
  makeEventName,
  notification,
  parse_api_response,
  request,
} from '~/utils';

type EventStatus = {
  id: number;
  name: string;
};

type EventsFilter = {
  filter: string;
  status: string;
  event: string;
  reference: string;
  before: string;
  after: string;
  sort: string;
  direction: string;
  all: boolean;
};

type EventsResponse = {
  items: Array<EventsItem>;
  statuses: Array<EventStatus>;
  paging: { page: number; perpage: number; total: number };
  filter: EventsFilter;
};

type SearchState = {
  status: string;
  event: string;
  reference: string;
  before: string;
  after: string;
};

const ANY_STATUS = '__any__';

const pageShell = requireTopLevelPageShell('events');

const route = useRoute();
const router = useRouter();

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
  return Number.isNaN(parsed) || parsed < 1 ? fallback : parsed;
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
const perpage = ref<number>(toPositiveInt(getRouteQueryValue(route.query.perpage, '26'), 26));
const isLoading = ref<boolean>(false);
const deleteSubmitting = ref<boolean>(false);
const items = ref<Array<EventsItem>>([]);
const statuses = ref<Array<EventStatus>>([]);
const displayFilter = ref<string>(getRouteQueryValue(route.query.filter, ''));
const showDisplayFilter = ref<boolean>(!!displayFilter.value);
const showSearchPanel = ref<boolean>(
  ['status', 'event', 'reference', 'before', 'after'].some((key) => {
    const value = route.query[key];
    return Array.isArray(value) ? !!value[0] : !!value;
  }),
);
const search = ref<SearchState>(createSearchState(route.query));
const quick_view = ref<string | null>(getRouteQueryValue(route.query.view, '') || null);
const show_page_tips = useStorage<boolean>('show_page_tips', true);
const deleteModalOpen = ref<boolean>(false);
const deleteIncludePending = ref<boolean>(false);

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

const eventCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default/70 px-4 py-4',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const quickViewModalUi = {
  content: 'max-w-5xl',
  body: 'p-4 sm:p-5',
};

const searchCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
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

const hasActiveServerFilters = computed<boolean>(() => {
  return ['status', 'event', 'reference', 'before', 'after'].some((key) => {
    const value = search.value[key as keyof SearchState];
    return 'string' === typeof value && '' !== value.trim();
  });
});

const activeServerFilterSummary = computed<string>(() => {
  const parts: Array<string> = [];

  if (ANY_STATUS !== search.value.status) {
    const matched = statusItems.value.find((item) => item.value === search.value.status);
    parts.push(`status: ${matched?.label || search.value.status}`);
  }

  if (search.value.event.trim()) {
    parts.push(`event: ${search.value.event.trim()}`);
  }

  if (search.value.reference.trim()) {
    parts.push(`reference: ${search.value.reference.trim()}`);
  }

  if (search.value.after.trim()) {
    parts.push(`after: ${search.value.after.trim()}`);
  }

  if (search.value.before.trim()) {
    parts.push(`before: ${search.value.before.trim()}`);
  }

  return parts.join(' | ');
});

watch(showDisplayFilter, () => {
  if (!showDisplayFilter.value) {
    displayFilter.value = '';
    return;
  }

  awaitElement('#display-filter', (_, element) => (element as HTMLInputElement).focus());
});

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

const getEventStatusColor = (status: number) => {
  switch (status) {
    case 1:
      return 'warning';
    case 2:
      return 'success';
    case 3:
    case 4:
      return 'error';
    default:
      return 'neutral';
  }
};

const getStatusName = (item: EventsItem): string => {
  return item.status_name || statusLookup.value[item.status] || String(item.status);
};

const getEventStatusIcon = (status: number): string => {
  switch (status) {
    case 0:
      return 'i-lucide-clock-3';
    case 1:
      return 'i-lucide-loader-circle';
    case 2:
      return 'i-lucide-circle-check';
    case 3:
      return 'i-lucide-circle-x';
    case 4:
      return 'i-lucide-ban';
    default:
      return 'i-lucide-circle-help';
  }
};

const getEventStatusIconClass = (status: number): string => {
  return 1 === status ? 'size-3.5 animate-spin' : 'size-3.5';
};

const buildServerQuery = (pageNumber: number, requestedPerPage: number): URLSearchParams => {
  const queryParams = new URLSearchParams();
  queryParams.append('page', pageNumber.toString());
  queryParams.append('perpage', requestedPerPage.toString());
  queryParams.append('all', '1');

  for (const [key, value] of Object.entries(search.value)) {
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

  if (quick_view.value) {
    historyQuery.view = quick_view.value;
  }

  if (displayFilter.value) {
    historyQuery.filter = displayFilter.value;
  }

  for (const [key, value] of Object.entries(search.value)) {
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

const navigatePage = async (nextPage: number): Promise<void> => {
  await loadContent(nextPage);
};

const loadContent = async (
  pageNumber: number = 1,
  updateHistory: boolean = true,
): Promise<void> => {
  try {
    pageNumber = toPositiveInt(pageNumber.toString(), 1);
    const requestedPerPage = toPositiveInt(perpage.value.toString(), 25);
    const queryParams = buildServerQuery(pageNumber, requestedPerPage);

    isLoading.value = true;
    items.value = [];

    const response = await request(`/system/events?${queryParams.toString()}`);
    const json = await parse_api_response<EventsResponse>(response);

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Events request error. ${json.error.code}: ${json.error.message}`,
      );
      return;
    }

    const titleParts = [`Events - Page #${pageNumber}`];
    if (hasActiveServerFilters.value) {
      titleParts.push(activeServerFilterSummary.value);
    }
    if (displayFilter.value) {
      titleParts.push(`display filter: ${displayFilter.value}`);
    }
    useHead({ title: titleParts.join(' | ') });

    if (true === updateHistory) {
      await router.push({ path: '/events', query: buildRouteQuery(pageNumber, requestedPerPage) });
    }

    page.value = json.paging.page;
    perpage.value = json.paging.perpage;
    total.value = json.paging.total;
    items.value = (json.items ?? []).map((item) => ({ ...item }));
    statuses.value = json.statuses ?? [];
  } catch (e: unknown) {
    console.error(e);
    notification(
      'crit',
      'Error',
      `Events Request failure. ${e instanceof Error ? e.message : String(e)}`,
    );
  } finally {
    isLoading.value = false;
  }
};

const submitSearch = async (): Promise<void> => {
  showSearchPanel.value = true;
  await loadContent(1);
};

const resetSearch = (): void => {
  search.value = defaultSearchState();
  showSearchPanel.value = false;
  void loadContent(1);
};

onMounted(async () => {
  await loadContent(page.value);
  window.addEventListener('popstate', handlePopState);
});

onUnmounted(() => window.removeEventListener('popstate', handlePopState));

const handlePopState = async (): Promise<void> => {
  page.value = toPositiveInt(getRouteQueryValue(route.query.page, '1'), 1);
  perpage.value = toPositiveInt(getRouteQueryValue(route.query.perpage, '26'), 26);
  displayFilter.value = getRouteQueryValue(route.query.filter, '');
  showDisplayFilter.value = !!displayFilter.value;
  search.value = createSearchState(route.query);
  quick_view.value = getRouteQueryValue(route.query.view, '') || null;
  showSearchPanel.value = ['status', 'event', 'reference', 'before', 'after'].some((key) => {
    const value = route.query[key];
    return Array.isArray(value) ? !!value[0] : !!value;
  });

  await loadContent(page.value, false);
};

const deletedItem = (id: string): void => {
  items.value = items.value.filter((item) => item.id !== id);
  total.value = Math.max(0, total.value - 1);

  if (quick_view.value === id) {
    quick_view.value = null;
  }
};

const deleteItem = async (item: EventsItem): Promise<void> => {
  if (1 === item.status) {
    notification('warning', 'Unavailable', 'Running events cannot be deleted.');
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: `Delete '${makeEventName(item.id)}'?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/system/events/${item.id}`, { method: 'DELETE' });

    if (200 !== response.status) {
      const json = await parse_api_response<GenericResponse>(response);
      if ('error' in json) {
        const errorJson = json as GenericError;
        notification(
          'error',
          'Error',
          `Events delete Request error. ${errorJson.error.code}: ${errorJson.error.message}`,
        );
      } else {
        notification('error', 'Error', 'Events delete Request error.');
      }
      return;
    }

    deletedItem(item.id);
    notification('success', 'Success', `Event '${makeEventName(item.id)}' successfully deleted.`);
  } catch (e: unknown) {
    console.error(e);
    notification(
      'crit',
      'Error',
      `Events delete Request failure. ${e instanceof Error ? e.message : String(e)}`,
    );
  }
};

const resetEvent = async (item: EventsItem, status: number = 0): Promise<void> => {
  if (1 === item.status) {
    notification('warning', 'Unavailable', 'Running events cannot be reset or cancelled.');
    return;
  }

  const action = 4 === status ? 'Cancel' : 'Reset';
  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: `${action} '${makeEventName(item.id)}'?`,
    confirmColor: 'warning',
  });

  if (true !== confirmStatus) {
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
      const errorJson = json as GenericError;
      notification(
        'error',
        'Error',
        `Events view patch Request error. ${errorJson.error.code}: ${errorJson.error.message}`,
      );
      return;
    }

    if (200 !== response.status) {
      notification('error', 'Error', 'Events view patch Request error.');
      return;
    }

    const index = items.value.findIndex((currentItem) => currentItem.id === item.id);
    if (index < 0) {
      return;
    }

    items.value[index] = { ...json };
  } catch (e: unknown) {
    console.error(e);
    notification(
      'crit',
      'Error',
      `Events view patch Request failure. ${e instanceof Error ? e.message : String(e)}`,
    );
  }
};

const buildDeleteQuery = (): URLSearchParams => {
  const queryParams = new URLSearchParams();

  if (deleteIncludePending.value) {
    queryParams.append('include_pending', '1');
  }

  for (const [key, value] of Object.entries(search.value)) {
    if ('all' === key) {
      continue;
    }

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

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Failed to delete events. ${json.error.code}: ${json.error.message}`,
      );
      return;
    }

    deleteModalOpen.value = false;
    quick_view.value = null;
    notification(
      'success',
      'Success',
      `Deleted ${json.deleted} event(s) from ${json.matched} match(es).`,
    );
    await loadContent(page.value);
  } catch (e: unknown) {
    console.error(e);
    notification(
      'crit',
      'Error',
      `Events delete Request failure. ${e instanceof Error ? e.message : String(e)}`,
    );
  } finally {
    deleteSubmitting.value = false;
  }
};

const toggleDisplayFilter = (): void => {
  showDisplayFilter.value = !showDisplayFilter.value;
};

watch(quick_view, (value: string | null) => {
  const currentView = getRouteQueryValue(route.query.view, '') || null;

  if (currentView === value) {
    return;
  }

  void router.push({
    path: '/events',
    query: {
      ...route.query,
      view: value ?? undefined,
    },
  });
});

watch(
  () => route.query.view,
  (value) => {
    const nextValue = getRouteQueryValue(value, '') || null;

    if (nextValue === quick_view.value) {
      return;
    }

    quick_view.value = nextValue;
  },
);

watch(displayFilter, (value: string) => {
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
