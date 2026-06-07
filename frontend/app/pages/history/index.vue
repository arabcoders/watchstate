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
          <span class="hidden sm:inline">Filter</span>
        </UButton>

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
          @update:model-value="() => void loadContent(1, false)"
        />

        <UButton
          color="neutral"
          :variant="searchForm ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-search"
          @click="searchForm = !searchForm"
        >
          <span class="hidden sm:inline">Search</span>
        </UButton>

        <UButton
          color="neutral"
          :variant="selectAll ? 'soft' : 'outline'"
          size="sm"
          :icon="selectAll ? 'i-lucide-square' : 'i-lucide-square-check-big'"
          @click="selectAll = !selectAll"
        >
          <span class="hidden sm:inline">{{ selectAll ? 'Unselect' : 'Select' }}</span>
        </UButton>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent(page, true)"
        >
          <span class="hidden sm:inline">Reload</span>
        </UButton>
      </div>
    </div>

    <UCard v-if="searchForm" class="border border-default/70 shadow-sm" :ui="panelCardUi">
      <template #header>
        <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
          <UIcon name="i-lucide-search" class="size-4 text-toned" />
          <span>Search History</span>
        </div>
      </template>

      <form class="space-y-3" @submit.prevent="void loadContent(1)">
        <div class="space-y-3">
          <div
            v-for="(searchFilter, index) in searchFilters"
            :key="searchFilter.id"
            class="rounded-md border border-default bg-elevated/20 p-3"
          >
            <div class="grid gap-3 lg:grid-cols-[14rem_minmax(0,1fr)_auto] lg:items-start">
              <USelect
                v-model="searchFilter.field"
                :items="getSearchFieldItems(index)"
                value-key="value"
                label-key="label"
                color="neutral"
                variant="outline"
                size="sm"
                placeholder="Select field"
                icon="i-lucide-folder-tree"
                :disabled="isLoading"
              />

              <UInput
                v-model="searchFilter.value"
                type="search"
                placeholder="Search..."
                icon="i-lucide-search"
                size="sm"
                :disabled="'' === searchFilter.field || isLoading"
              />

              <UTooltip text="Remove filter">
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  square
                  icon="i-lucide-trash-2"
                  type="button"
                  :disabled="isLoading"
                  aria-label="Remove filter"
                  @click="removeSearchFilter(index)"
                >
                  <span class="inline sm:hidden">Remove filter</span>
                </UButton>
              </UTooltip>
            </div>

            <p v-if="getHelpText(searchFilter.field)" class="mt-2 text-sm text-toned">
              {{ getHelpText(searchFilter.field) }}
            </p>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-plus"
            type="button"
            :disabled="isLoading || !canAddSearchFilter"
            @click="addSearchFilter"
          >
            Add Filter
          </UButton>

          <div class="flex flex-wrap items-center justify-end gap-2">
            <UButton
              color="primary"
              size="sm"
              icon="i-lucide-search"
              type="submit"
              :disabled="!canSubmitSearch"
              :loading="isLoading"
            >
              Search
            </UButton>

            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-x"
              type="button"
              :disabled="isLoading"
              @click="clearSearch"
            >
              Reset
            </UButton>
          </div>
        </div>
      </form>
    </UCard>

    <div
      v-if="selected_ids.length > 0"
      class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-default px-3 py-3"
    >
      <div class="flex flex-wrap items-center gap-2">
        <UBadge color="neutral" variant="soft" size="sm">{{ selected_ids.length }}</UBadge>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          :loading="massActionInProgress"
          :disabled="massActionInProgress"
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
          :disabled="massActionInProgress"
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
          :disabled="massActionInProgress"
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

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <Lazy
        v-for="item in filteredItems"
        :key="item.id"
        :unrender="true"
        :min-height="260"
        class="min-h-65"
      >
        <UCard
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
                        <NuxtLink
                          :to="`/history/${item.id}`"
                          class="block truncate text-highlighted hover:text-primary"
                        >
                          {{ item.full_title || makeName(item as unknown as JsonObject) }}
                        </NuxtLink>
                      </UTooltip>
                    </FloatingImage>

                    <UTooltip
                      v-else
                      :text="String(item.full_title || makeName(item as unknown as JsonObject))"
                    >
                      <NuxtLink
                        :to="`/history/${item.id}`"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item.full_title || makeName(item as unknown as JsonObject) }}
                      </NuxtLink>
                    </UTooltip>
                  </div>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-2">
                <UTooltip :text="selected_ids.includes(item.id) ? 'Unselect item' : 'Select item'">
                  <UCheckbox
                    color="primary"
                    :model-value="selected_ids.includes(item.id)"
                    @update:model-value="toggleSelected(item.id, $event)"
                  />
                </UTooltip>
              </div>
            </div>
          </template>

          <div class="space-y-3">
            <div
              v-if="item.content_title"
              class="flex items-start justify-between gap-3 rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            >
              <div
                class="min-w-0 flex-1 cursor-pointer"
                :class="item.expand_title ? '' : 'overflow-hidden text-ellipsis whitespace-nowrap'"
                @click="item.expand_title = !item.expand_title"
              >
                <span class="inline-flex items-center gap-2 text-sm font-medium text-default">
                  <UIcon name="i-lucide-heading" class="size-4 shrink-0 text-toned" />
                  <NuxtLink
                    :to="makeSearchLink('subtitle', item.content_title ?? '')"
                    class="hover:text-primary"
                  >
                    {{ item.content_title }}
                  </NuxtLink>
                </span>
              </div>

              <UTooltip text="Copy subtitle">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  square
                  icon="i-lucide-copy"
                  aria-label="Copy subtitle"
                  @click="() => void copyText(item.content_title ?? '', false)"
                />
              </UTooltip>
            </div>

            <div
              v-if="item.content_path"
              class="flex items-start justify-between gap-3 rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            >
              <div
                class="min-w-0 flex-1 cursor-pointer"
                :class="item.expand_path ? '' : 'overflow-hidden text-ellipsis whitespace-nowrap'"
                @click="item.expand_path = !item.expand_path"
              >
                <span class="inline-flex items-center gap-2 text-sm font-medium text-default">
                  <UIcon name="i-lucide-file-text" class="size-4 shrink-0 text-toned" />
                  <NuxtLink
                    :to="makeSearchLink('path', item.content_path ?? '')"
                    class="hover:text-primary"
                  >
                    {{ item.content_path }}
                  </NuxtLink>
                </span>
              </div>

              <UTooltip text="Copy file path">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  square
                  icon="i-lucide-copy"
                  aria-label="Copy file path"
                  @click="() => void copyText(item.content_path ?? '', false)"
                />
              </UTooltip>
            </div>
          </div>

          <template #footer>
            <div
              :class="[
                'grid grid-cols-2 gap-2.5',
                item.progress ? 'xl:grid-cols-4' : 'xl:grid-cols-3',
              ]"
            >
              <div
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
                      .filter((i) => i !== item.via)
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
                <span>{{ item.event ?? '-' }}</span>
              </div>

              <div
                v-if="item.progress"
                :class="[
                  'flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default',
                  !item.updated_at ? 'col-span-2 xl:col-span-1' : '',
                ]"
              >
                <UIcon name="i-lucide-gauge" class="size-4 shrink-0 text-toned" />
                <span>{{ formatDuration(item.progress as number) }}</span>
              </div>
            </div>
          </template>
        </UCard>
      </Lazy>
    </div>

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
      <div class="text-xs text-toned">Page {{ page }} of {{ last_page }}</div>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter, useHead } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import type { LocationQuery } from 'vue-router';
import Lazy from '~/components/Lazy.vue';
import Pager from '~/components/Pager.vue';
import { NuxtLink } from '#components';
import FloatingImage from '~/components/FloatingImage.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  request,
  awaitElement,
  copyText,
  formatDuration,
  makeName,
  makeSearchLink,
  notification,
  TOOLTIP_DATE_FORMAT,
  parse_api_response,
} from '~/utils';
import type { HistoryItem, JsonObject, PaginationInfo, RequestOptions } from '~/types';
import { useDialog } from '~/composables/useDialog.ts';

type HistoryPagination = PaginationInfo;

const pageShell = requireTopLevelPageShell('history');

type HistorySearchableField = {
  key: string;
  display?: string;
  description?: string;
  type?: string | Array<string>;
};

type HistorySearchFilter = {
  id: string;
  field: string;
  value: string;
};

const route = useRoute();
const router = useRouter();

useHead({ title: 'History' });

const poster_enable = useStorage('poster_enable', true);

type HistoryItemWithUIState = Omit<
  HistoryItem,
  'metadata' | 'extra' | 'files' | 'parent' | 'rguids'
> & {
  metadata?: Record<string, { via?: string }>;
  extra?: Record<string, unknown>;
  files?: Array<unknown>;
  parent?: Record<string, string>;
  rguids?: Record<string, string>;
  full_title?: string;
  showRawData?: boolean;
  expand_title?: boolean;
  expand_path?: boolean;
};

const jsonFields = ref<Array<string>>(['metadata', 'extra']);
const items = ref<Array<HistoryItemWithUIState>>([]);
const searchable = ref<Array<HistorySearchableField>>([
  { key: 'id', description: 'Search using local history id.', type: 'int' },
  { key: 'watched', description: 'Search using watched status.', type: ['0', '1'] },
  { key: 'via', display: 'Backend', description: 'Search using the backend name.', type: 'string' },
  { key: 'year', description: 'Search using the year.', type: 'int' },
  { key: 'type', description: 'Search using the content type.', type: ['movie', 'episode'] },
  { key: 'title', description: 'Search using the title.', type: 'string' },
  { key: 'season', description: 'Search using the season number.', type: 'int' },
  { key: 'episode', description: 'Search using the episode number.', type: 'int' },
  {
    key: 'parent',
    display: 'Series GUID',
    description: 'Search using the parent GUID.',
    type: 'provider://id',
  },
  {
    key: 'guids',
    display: 'Content GUID',
    description: 'Search using the GUID.',
    type: 'provider://id',
  },
  {
    key: 'metadata',
    description: 'Search using the metadata JSON field. Searching this field might be slow.',
    type: 'backend.field://value',
  },
  {
    key: 'extra',
    description: 'Search using the extra JSON field. Searching this field might be slow.',
    type: 'backend.field://value',
  },
  {
    key: 'rguid',
    description: 'Search using the rGUID.',
    type: 'guid://parentID/seasonNumber[/episodeNumber]',
  },
  {
    key: 'path',
    description: 'Search using file path. Searching this field might be slow.',
    type: 'string',
  },
  {
    key: 'subtitle',
    display: 'Subtitle',
    description: 'Search using subtitle. Searching this field will be slow.',
    type: 'string',
  },
  {
    key: 'genres',
    display: 'Genre',
    description: 'Search using genres. Searching this field will be slow.',
    type: 'string',
  },
]);
const error = ref('');

const page = ref<number>(parseInt(route.query.page as string) || 1);
const perpage = ref<number>(parseInt(route.query.perpage as string) || 50);
const total = ref<number>(0);
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));

const isLoading = ref(false);
const filter = ref<string>((route.query.filter as string) || '');
const showFilter = ref<boolean>(Boolean(filter.value));
const searchForm = ref(false);
const selectAll = ref(false);
const selected_ids = ref<Array<number>>([]);
const massActionInProgress = ref(false);
let searchFilterCounter = 0;

const panelCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const historyCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const perPageItems = [50, 100, 200, 400, 500].map((value) => ({
  label: `${value} per page`,
  value,
}));

const createSearchFilter = (field = '', value = ''): HistorySearchFilter => ({
  id: `filter-${searchFilterCounter++}`,
  field,
  value,
});

const emptySearchFilter = (): HistorySearchFilter => createSearchFilter('', '');

const searchFilters = ref<Array<HistorySearchFilter>>([emptySearchFilter()]);

const isJsonSearchField = (fieldKey: string): boolean => jsonFields.value.includes(fieldKey);

const normalizeSearchFilters = (
  filters: Array<HistorySearchFilter>,
): Array<HistorySearchFilter> => {
  const normalized = filters
    .map((item) => createSearchFilter(item.field, item.value.trim()))
    .filter((item) => item.field || item.value);

  if (normalized.length < 1) {
    return [emptySearchFilter()];
  }

  return normalized;
};

const getAvailableFields = (selectedField = ''): Array<HistorySearchableField> => {
  const used = new Set(
    searchFilters.value
      .map((item) => item.field)
      .filter((field) => field && field !== selectedField),
  );

  const activeJsonField = searchFilters.value.find(
    (item) => item.field && item.field !== selectedField && isJsonSearchField(item.field),
  )?.field;

  return searchable.value.filter((field) => {
    if (used.has(field.key) && field.key !== selectedField) {
      return false;
    }

    if (activeJsonField && isJsonSearchField(field.key) && field.key !== activeJsonField) {
      return field.key === selectedField;
    }

    return true;
  });
};

const getSearchFieldItems = (index: number): Array<{ label: string; value: string }> => {
  const selectedField = searchFilters.value[index]?.field ?? '';

  return getAvailableFields(selectedField).map((field) => ({
    label: field.display ?? field.key,
    value: field.key,
  }));
};

const canAddSearchFilter = computed<boolean>(() => {
  return getAvailableFields('').length > 0 && searchFilters.value.every((item) => item.field);
});

const canSubmitSearch = computed<boolean>(() => {
  if (isLoading.value) {
    return false;
  }

  const activeFilters = searchFilters.value.filter((item) => item.field || item.value.trim());

  if (activeFilters.length < 1) {
    return false;
  }

  return activeFilters.every((item) => item.field && item.value.trim());
});

const activeSearchSummary = computed<string>(() => {
  const activeFilters = searchFilters.value.filter((item) => item.field && item.value.trim());

  return activeFilters.map((item) => `${item.field}: ${item.value.trim()}`).join(', ');
});

const getHelpText = (key: string): string => {
  if (!key) {
    return '';
  }

  const field = searchable.value.find((entry) => entry.key === key);
  if (!field?.description) {
    return '';
  }

  let text = field.description;

  if (field.type) {
    text += ` Expected value: ${Array.isArray(field.type) ? field.type.join(' or ') : field.type}`;
  }

  return text;
};

const stringifyItem = (item: HistoryItemWithUIState): string => JSON.stringify(item).toLowerCase();

const filteredRows = (input: Array<HistoryItemWithUIState>): Array<HistoryItemWithUIState> => {
  if (!filter.value) {
    return input;
  }

  return input.filter((item) => stringifyItem(item).includes(filter.value.toLowerCase()));
};

const filteredItems = computed(() => filteredRows(items.value));

watch(selectAll, (value: boolean) => {
  selected_ids.value = value ? filteredItems.value.map((item) => item.id) : [];
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

const navigatePage = (pageNumber: number): void => {
  void loadContent(pageNumber);
};

const addSearchFilter = (): void => {
  if (!canAddSearchFilter.value) {
    return;
  }

  searchFilters.value = [...searchFilters.value, emptySearchFilter()];
};

const removeSearchFilter = (index: number): void => {
  if (searchFilters.value.length <= 1) {
    searchFilters.value = [emptySearchFilter()];
    return;
  }

  searchFilters.value = searchFilters.value.filter((_, itemIndex) => itemIndex !== index);
};

const buildSearchParams = (
  filters: Array<HistorySearchFilter>,
): { params: URLSearchParams; titleParts: Array<string>; queryState: Record<string, string> } => {
  const params = new URLSearchParams();
  const titleParts: Array<string> = [];
  const queryState: Record<string, string> = {};

  for (const item of filters) {
    const field = item.field;
    const value = item.value.trim();

    if (!field || !value) {
      continue;
    }

    titleParts.push(`${field}: ${value}`);

    if (isJsonSearchField(field)) {
      const [jsonKey, jsonValue] = splitQuery(value, '://');

      if (-1 === value.indexOf('://') || !jsonValue || !jsonKey) {
        throw new Error(`Invalid search format for '${field}'.`);
      }

      params.set(field, '1');
      params.set('key', jsonKey);
      params.set('value', jsonValue);
      queryState[field] = value;
      continue;
    }

    params.set(field, value);
    queryState[field] = value;
  }

  return { params, titleParts, queryState };
};

const readSearchFiltersFromQuery = (queryParams: LocationQuery): Array<HistorySearchFilter> => {
  const filters: Array<HistorySearchFilter> = [];

  const singleKey = typeof queryParams.key === 'string' ? queryParams.key : '';
  const singleValue = typeof queryParams.q === 'string' ? queryParams.q : '';

  if (singleKey && singleValue) {
    filters.push(createSearchFilter(singleKey, singleValue));
  }

  for (const field of searchable.value) {
    if (
      ['key', 'q', 'page', 'perpage', 'filter', 'sort', 'view', 'with_duplicates'].includes(
        field.key,
      )
    ) {
      continue;
    }

    const rawValue = queryParams[field.key];
    const value = Array.isArray(rawValue) ? rawValue[0] : rawValue;

    if (typeof value !== 'string' || !value) {
      continue;
    }

    if (filters.some((item) => item.field === field.key)) {
      continue;
    }

    if (isJsonSearchField(field.key)) {
      const rawKey = queryParams.key;
      const rawJsonValue = queryParams.value;
      const nestedKey = Array.isArray(rawKey) ? rawKey[0] : rawKey;
      const nestedValue = Array.isArray(rawJsonValue) ? rawJsonValue[0] : rawJsonValue;

      if ('1' !== value) {
        filters.push(createSearchFilter(field.key, value));
        continue;
      }

      if (
        typeof nestedKey === 'string' &&
        typeof nestedValue === 'string' &&
        nestedKey &&
        nestedValue
      ) {
        filters.push(createSearchFilter(field.key, `${nestedKey}://${nestedValue}`));
      }

      continue;
    }

    filters.push(createSearchFilter(field.key, value));
  }

  return normalizeSearchFilters(filters);
};

const loadContent = async (pageNumber: number, fromPopState: boolean = false): Promise<void> => {
  pageNumber = parseInt(pageNumber.toString());

  if (Number.isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1;
  }

  let title = `History: Page #${pageNumber}`;

  const search = new URLSearchParams();
  search.set('perpage', perpage.value.toString());
  search.set('page', pageNumber.toString());

  const activeFilters = searchFilters.value
    .map((item) => ({ ...item, value: item.value.trim() }))
    .filter((item) => item.field || item.value);

  if (filter.value) {
    title += `. (Filter: ${filter.value})`;
  }

  try {
    if (activeFilters.some((item) => !item.field || !item.value)) {
      notification('error', 'Error', 'Each search filter requires both a field and a value.');
      return;
    }

    const { params, titleParts, queryState } = buildSearchParams(activeFilters);

    params.forEach((value, key) => search.set(key, value));

    if (titleParts.length > 0) {
      title += `. (Search: ${titleParts.join(', ')})`;
    }

    useHead({ title });

    const routeSearch = new URLSearchParams();
    routeSearch.set('perpage', perpage.value.toString());
    routeSearch.set('page', pageNumber.toString());

    Object.entries(queryState).forEach(([key, value]) => routeSearch.set(key, value));

    if (filter.value) {
      routeSearch.set('filter', filter.value);
    }

    const targetRouteUrl = `${window.location.pathname}?${routeSearch.toString()}`;

    isLoading.value = true;
    items.value = [];

    const response = await request(`/history?${search.toString()}`);
    const json = await parse_api_response<{
      history: Array<HistoryItem>;
      paging: HistoryPagination;
      searchable: Array<HistorySearchableField>;
      filters?: Record<string, unknown>;
    }>(response);

    if ('error' in json) {
      error.value = json.error?.message || 'Unknown error occurred';
      return;
    }

    if (useRoute().name !== 'history') {
      await unloadPage();
      return;
    }

    const currentUrl = `${window.location.pathname}?${new URLSearchParams(window.location.search).toString()}`;

    if (!fromPopState && currentUrl !== targetRouteUrl) {
      const history_query: Record<string, string | number | undefined> = {
        perpage: perpage.value,
        page: pageNumber,
      };

      Object.assign(history_query, queryState);

      if (filter.value) {
        history_query.filter = filter.value;
      }

      await router.push({ path: '/history', query: history_query });
    }

    if ('paging' in json) {
      page.value = json.paging.current_page;
      perpage.value = json.paging.perpage;
      total.value = json.paging.total;
    } else {
      page.value = 1;
      total.value = 0;
    }

    if (json.history) {
      for (const item of json.history) {
        const fullTitle = makeName(item as unknown as JsonObject);
        if (fullTitle) {
          item.full_title = fullTitle;
        }

        items.value.push(item as unknown as HistoryItemWithUIState);
      }
    }

    if (json.searchable) {
      searchable.value = json.searchable;
    }
  } catch (e) {
    if (e instanceof Error && e.message.startsWith('Invalid search format')) {
      notification('error', 'Error', e.message);
      return;
    }

    console.error('Failed to load content:', e);
  } finally {
    isLoading.value = false;
    selectAll.value = false;
    selected_ids.value = [];
  }
};

const clearSearch = (): void => {
  searchFilters.value = [emptySearchFilter()];
  filter.value = '';
  searchForm.value = false;
  showFilter.value = false;
  void loadContent(1);
};

const splitQuery = (value: string, delimiter: string): Array<string> => {
  const index = value.indexOf(delimiter);
  return -1 === index ? [value] : [value.slice(0, index), value.slice(index + delimiter.length)];
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
  if (0 === selected_ids.value.length) {
    return;
  }

  const title = {
    delete: 'Delete',
    mark_played: 'Mark as played',
    mark_unplayed: 'Mark as unplayed',
  }[action];

  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: `Are you sure you want to '${title}' ${selected_ids.value.length} item/s?`,
    confirmColor: 'delete' === action ? 'error' : 'primary',
  });

  if (true !== confirmStatus) {
    return;
  }

  let urls: Array<string> = [];
  let opts: RequestOptions = {};
  let callback: (() => void) | null = null;

  massActionInProgress.value = true;

  if ('delete' === action) {
    opts = { method: 'DELETE' };
    urls = selected_ids.value.map((id) => `/history/${id}`);
    callback = () => {
      items.value = items.value.filter((item) => !selected_ids.value.includes(item.id));
    };
  }

  if ('mark_played' === action || 'mark_unplayed' === action) {
    opts = { method: 'mark_played' === action ? 'POST' : 'DELETE' };
    const ids = selected_ids.value
      .map((id) => items.value.find((item) => item.id === id))
      .filter((item): item is HistoryItemWithUIState => undefined !== item)
      .filter((item) => ('mark_played' === action ? !item.watched : item.watched))
      .map((item) => item.id);

    urls = ids.map((value) => `/history/${value}/watch`);
    callback = () => {
      items.value.forEach((item) => {
        if (ids.includes(item.id)) {
          item.watched = 'mark_played' === action;
        }
      });
    };
  }

  try {
    notification(
      'success',
      'Action in progress',
      `Processing Mass '${title}' request. Please wait...`,
    );

    const requests = await Promise.all(urls.map((url) => request(url, opts)));
    const all_ok = requests.every((response) => 200 === response.status);

    if (!all_ok) {
      notification(
        'error',
        'Error',
        'Some requests failed. Please check the console for more details.',
      );
    }

    if (all_ok && callback) {
      callback();
    }

    notification('success', 'Success', `Mass '${title}' request completed.`);
  } catch (e) {
    const err = e as Error;
    notification('error', 'Error', `Request error. ${err.message}`);
  } finally {
    massActionInProgress.value = false;
    selected_ids.value = [];
    selectAll.value = false;
  }
};

const stateCallBack = async (event: Event): Promise<void> => {
  const popStateEvent = event as PopStateEvent;
  const customEvent = event as CustomEvent;

  if (!popStateEvent.state && !customEvent.detail) {
    return;
  }

  const state = customEvent.detail ?? popStateEvent.state;
  const currentRoute = useRoute();

  page.value = parseInt(currentRoute.query.page as string) || 1;
  perpage.value = parseInt(currentRoute.query.perpage as string) || 50;
  filter.value = (currentRoute.query.filter as string) || '';

  if (filter.value) {
    showFilter.value = true;
  }

  if ('clear' in state) {
    searchFilters.value = [emptySearchFilter()];
  } else {
    searchFilters.value = readSearchFiltersFromQuery(currentRoute.query);
    if (searchFilters.value.some((item) => item.field && item.value.trim())) {
      searchForm.value = true;
    }
  }

  await loadContent(page.value, true);
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
  () => route.fullPath,
  async () => {
    if ('history' !== route.name) {
      return;
    }

    const nextPage = parseInt(route.query.page as string) || 1;
    const nextPerPage = parseInt(route.query.perpage as string) || 50;
    const nextFilter = (route.query.filter as string) || '';
    const nextSearchFilters = readSearchFiltersFromQuery(route.query);
    const currentSearchFilters = JSON.stringify(
      searchFilters.value.map((item) => ({ field: item.field, value: item.value.trim() })),
    );
    const incomingSearchFilters = JSON.stringify(
      nextSearchFilters.map((item) => ({ field: item.field, value: item.value.trim() })),
    );

    const shouldReload =
      nextPage !== page.value ||
      nextPerPage !== perpage.value ||
      incomingSearchFilters !== currentSearchFilters;

    page.value = nextPage;
    perpage.value = nextPerPage;
    searchFilters.value = nextSearchFilters;
    filter.value = nextFilter;
    showFilter.value = Boolean(nextFilter);
    searchForm.value = nextSearchFilters.some((item) => item.field && item.value.trim());

    if (!shouldReload) {
      return;
    }

    await loadContent(nextPage, true);
  },
);

onMounted(async (): Promise<void> => {
  searchFilters.value = readSearchFiltersFromQuery(route.query);

  if (searchFilters.value.some((item) => item.field && item.value.trim())) {
    searchForm.value = true;
  }

  window.addEventListener('popstate', stateCallBack);
  window.addEventListener('history_main_link_clicked', stateCallBack);
  await loadContent(page.value ?? 1);
});

const unloadPage = async (): Promise<void> => {
  window.removeEventListener('history_main_link_clicked', stateCallBack);
  window.removeEventListener('popstate', stateCallBack);
};

onUnmounted(async (): Promise<void> => {
  await unloadPage();
});
</script>
