<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" page-label="Search" description="Search media on this backend.">
      <template #kicker>
        <NuxtLink to="/backends" class="hover:text-primary">Backends</NuxtLink>
        <span>/</span>
        <NuxtLink
          :to="`/backend/${backend}`"
          class="hover:text-primary normal-case tracking-normal"
          >{{ backend }}</NuxtLink
        >
      </template>
    </PageHeader>

    <form
      :aria-busy="isLoading"
      class="grid items-end gap-3 lg:grid-cols-[10rem_8rem_minmax(0,1fr)_auto]"
      @submit.prevent="void searchContent(false)"
    >
      <UFormField label="Search field" name="search_field">
        <USelect
          v-model="searchField"
          :items="searchFieldItems"
          value-key="value"
          label-key="label"
          color="neutral"
          variant="outline"
          size="sm"
          placeholder="Select field"
          icon="i-lucide-folder-tree"
          class="w-full"
          :disabled="isLoading"
        />
      </UFormField>

      <UFormField label="Result limit" name="search_limit">
        <USelect
          v-model="limit"
          :items="limitItems"
          value-key="value"
          label-key="label"
          color="neutral"
          variant="outline"
          size="sm"
          placeholder="Select limit"
          icon="i-lucide-list"
          class="w-full"
          :disabled="isLoading"
        />
      </UFormField>

      <UFormField label="Query" name="search_query">
        <UInput
          v-model="query"
          type="search"
          :placeholder="'id' === searchField ? 'Backend item ID' : 'Title'"
          icon="i-lucide-search"
          size="sm"
          class="w-full"
          :disabled="'' === searchField || isLoading"
        />
      </UFormField>
      <div class="flex justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-rotate-ccw"
          type="button"
          class="justify-center"
          :disabled="isLoading"
          @click="clearSearch"
        >
          Reset
        </UButton>

        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-search"
          type="submit"
          class="justify-center"
          :disabled="!query || '' === searchField || isLoading"
          :loading="isLoading"
        >
          Search
        </UButton>
      </div>
    </form>

    <UAlert
      v-if="isLoading && items.length < 1 && hasSearched"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Searching backend content"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="items.length < 1 && hasSearched && error.message"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Search failed"
      :description="error.message"
      close
      @update:open="(open) => (!open ? clearSearch() : undefined)"
    />

    <UEmpty
      v-else-if="items.length < 1 && hasSearched"
      icon="i-lucide-search"
      title="No items found"
    />

    <div v-else-if="0 < items.length" class="ws-card divide-y divide-default">
      <Lazy
        v-for="item in items"
        :key="`${item.via}:${item.type}:${ag(item, `metadata.${item.via}.id`, item.id)}:${item.content_path ?? item.title}`"
        :unrender="true"
        :min-height="176"
      >
        <article class="flex items-start gap-3 p-4 sm:p-5">
          <StatisticsPoster
            v-if="poster_enable"
            :key="`${apiUser}:${item.poster ?? item.id}`"
            :image="item.poster"
            :history-id="item.id ? Number(item.id) : undefined"
            :title="String(makeName(item))"
            :user="apiUser"
            preview
            class="hidden w-16 shrink-0 sm:block"
          />
          <div class="min-w-0 flex-1 space-y-4">
            <div class="flex min-w-0 flex-1 flex-wrap items-start justify-between gap-3">
              <div class="w-full min-w-0 space-y-2 sm:w-auto sm:flex-1">
                <h2 class="flex min-w-0 items-start gap-2 text-sm font-semibold text-highlighted">
                  <UIcon
                    :name="getItemTypeIcon(item.type)"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />
                  <NuxtLink
                    v-if="item.webUrl"
                    :to="item.webUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="wrap-anywhere hover:text-primary"
                  >
                    {{ makeName(item) }}
                  </NuxtLink>
                  <span v-else class="wrap-anywhere">{{ makeName(item) }}</span>
                </h2>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-toned">
                  <UBadge :color="item.watched ? 'success' : 'neutral'" variant="soft" size="xs">
                    {{ item.watched ? 'Watched' : 'Unwatched' }}
                  </UBadge>
                  <span>{{ item.id ? 'Imported locally' : 'Not imported' }}</span>
                  <UTooltip
                    v-if="getItemTimestamp(item)"
                    :text="moment.unix(getItemTimestamp(item)).format(TOOLTIP_DATE_FORMAT)"
                  >
                    <span class="cursor-help">{{
                      moment.unix(getItemTimestamp(item)).fromNow()
                    }}</span>
                  </UTooltip>
                </div>
              </div>
              <div class="flex flex-wrap gap-2">
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-braces"
                  @click="openRawData(item)"
                >
                  Raw data
                </UButton>
                <UButton
                  v-if="'show' !== item.type && item.id"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  icon="i-lucide-history"
                  :to="`/history/${item.id}`"
                >
                  Local record
                </UButton>
                <UButton
                  v-if="'show' === item.type"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  icon="i-lucide-link"
                  :to="
                    makeSearchLink(
                      'metadata',
                      `${item.via}.show://${ag(item, `metadata.${item.via}.id`)}`,
                    )
                  "
                >
                  Linked items
                </UButton>
              </div>
            </div>

            <dl class="min-w-0 space-y-2 text-sm">
              <div
                v-if="item.title"
                class="grid min-w-0 gap-1 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start"
              >
                <dt class="text-xs text-muted">Title</dt>
                <dd class="flex min-w-0 items-start gap-2">
                  <NuxtLink
                    :to="makeSearchLink('title', item.title)"
                    class="min-w-0 flex-1 hover:text-primary"
                    :class="item.expand_title ? 'wrap-anywhere' : 'truncate'"
                  >
                    {{ item.title }}
                  </NuxtLink>
                  <UTooltip :text="item.expand_title ? 'Collapse title' : 'Expand title'">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      :icon="item.expand_title ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                      :aria-label="item.expand_title ? 'Collapse title' : 'Expand title'"
                      :aria-expanded="!!item.expand_title"
                      @click="item.expand_title = !item.expand_title"
                    />
                  </UTooltip>
                </dd>
              </div>
              <div
                v-if="item.content_path"
                class="grid min-w-0 gap-1 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start"
              >
                <dt class="text-xs text-muted">File</dt>
                <dd class="flex min-w-0 items-start gap-2">
                  <NuxtLink
                    :to="makeSearchLink('path', item.content_path)"
                    class="min-w-0 flex-1 font-mono text-xs hover:text-primary"
                    :class="item.expand_path ? 'wrap-anywhere' : 'truncate'"
                    >{{ item.content_path }}</NuxtLink
                  >
                  <UTooltip :text="item.expand_path ? 'Collapse file path' : 'Expand file path'">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      :icon="item.expand_path ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                      :aria-label="item.expand_path ? 'Collapse file path' : 'Expand file path'"
                      :aria-expanded="!!item.expand_path"
                      @click="item.expand_path = !item.expand_path"
                    />
                  </UTooltip>
                </dd>
              </div>
            </dl>
          </div>
        </article>
      </Lazy>
    </div>

    <TextModal
      :open="selectedRawItem !== null"
      :title="selectedRawTitle"
      :text="selectedRawText"
      @update:open="handleRawModalOpenChange"
    />
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter, useHead } from '#app';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  USelect,
  UTooltip,
} from '#components';
import { useStorage } from '@vueuse/core';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import TextModal from '~/components/TextModal.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import moment from 'moment';
import {
  request,
  makeName,
  makeSearchLink,
  notification,
  TOOLTIP_DATE_FORMAT,
  ag,
  parse_api_response,
} from '~/utils';
import type { ExpandableUIState, SearchItem } from '~/types';

type SearchItemWithUI = SearchItem &
  ExpandableUIState & {
    showItem?: boolean;
  };

const route = useRoute();
const router = useRouter();
const poster_enable = useStorage('poster_enable', true);
const apiUser = useStorage<string>('api_user', 'main');

const items = ref<Array<SearchItemWithUI>>([]);
const limits = [25, 50, 100, 250, 500];
const limit = ref<number>(Number.parseInt((route.query.limit as string) ?? '25', 10));
const searchable = ['id', 'title'];
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const query = ref<string>((route.query.q as string) ?? '');
const searchField = ref<string>((route.query.key as string) ?? 'title');
const isLoading = ref<boolean>(false);
const hasSearched = ref<boolean>(false);
const error = ref<{ message?: string; code?: number }>({});
const selectedRawItem = ref<SearchItemWithUI | null>(null);

const limitItems = computed<Array<{ label: string; value: number }>>(() =>
  limits.map((item) => ({ label: item.toString(), value: item })),
);

const searchFieldItems = computed<Array<{ label: string; value: string }>>(() =>
  searchable.map((item) => ({ label: 'id' === item ? 'Backend ID' : 'Title', value: item })),
);

useHead({ title: `Backends: ${backend} - Search` });

const getItemTimestamp = (item: SearchItemWithUI): number => item.updated_at ?? item.updated ?? 0;

const getItemTypeIcon = (type: SearchItemWithUI['type']): string => {
  if ('show' === type) {
    return 'i-lucide-folder';
  }

  if ('episode' === type) {
    return 'i-lucide-tv';
  }

  return 'i-lucide-film';
};

const selectedRawTitle = computed<string>(() => {
  if (!selectedRawItem.value) {
    return 'Raw Data';
  }

  return makeName(selectedRawItem.value) as string;
});

const selectedRawText = computed<string>(() => {
  if (!selectedRawItem.value) {
    return '';
  }

  const rawRecord = selectedRawItem.value as unknown as Record<string, unknown>;
  const cleaned = Object.keys(rawRecord)
    .filter((key) => !['showItem'].includes(key))
    .reduce(
      (record: Record<string, unknown>, key: string) => {
        record[key] = rawRecord[key];
        return record;
      },
      {} as Record<string, unknown>,
    );

  return JSON.stringify(cleaned, null, 2);
});

const openRawData = (item: SearchItemWithUI): void => {
  selectedRawItem.value = item;
};

const handleRawModalOpenChange = (open: boolean): void => {
  if (open) {
    return;
  }

  selectedRawItem.value = null;
};

const searchContent = async (fromPopState: boolean = false): Promise<void> => {
  const search = new URLSearchParams();

  if (!query.value || '' === searchField.value) {
    notification('error', 'Search incomplete', 'Search field and query are required.');
    return;
  }

  hasSearched.value = true;
  isLoading.value = true;
  items.value = [];
  error.value = {};
  selectedRawItem.value = null;

  search.set('limit', limit.value.toString());
  search.set('raw', 'true');
  search.set('id' === searchField.value ? 'id' : 'q', query.value);

  const title = `Backends: ${backend} - (Search - ${searchField.value}: ${query.value})`;
  useHead({ title });

  try {
    const response = await request(`/backend/${backend}/search?${search.toString()}`);
    const data = await parse_api_response<Array<SearchItemWithUI>>(response);
    const currentUrl =
      window.location.pathname + '?' + new URLSearchParams(window.location.search).toString();
    const newUrl = window.location.pathname + '?' + search.toString();

    if (false === fromPopState && currentUrl !== newUrl) {
      await router.push({
        path: `/backend/${backend}/search`,
        query: { limit: limit.value.toString(), key: searchField.value, q: query.value },
      });
    }

    if ('error' in data) {
      if (404 === response.status && data.error.message.startsWith('No results were found for ')) {
        return;
      }

      error.value = { message: data.error.message, code: data.error.code };
      return;
    }

    items.value = data;
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    error.value = { message: errorMessage };
    notification('error', 'Backend search failed', errorMessage);
  } finally {
    isLoading.value = false;
  }
};

const clearSearch = async (): Promise<void> => {
  query.value = '';
  items.value = [];
  hasSearched.value = false;
  error.value = {};
  selectedRawItem.value = null;
  const title = `Backends: ${backend} - Search`;
  useHead({ title });
  await router.push({ path: `/backend/${backend}/search`, query: {} });
};

const stateCallBack = async (): Promise<void> => {
  const currentRoute = useRoute();

  if (currentRoute.query.key) {
    searchField.value = currentRoute.query.key as string;
  }

  if (currentRoute.query.limit) {
    limit.value = Number.parseInt(currentRoute.query.limit as string, 10);
  }

  if (currentRoute.query.q) {
    query.value = currentRoute.query.q as string;
    await searchContent(true);
  }
};

onMounted(() => {
  if (query.value && searchField.value) {
    void searchContent(false);
  }

  window.addEventListener('popstate', stateCallBack);
});

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack));
</script>
