<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <NuxtLink to="/backends" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
        <span>/</span>
        <NuxtLink
          :to="`/backend/${backend}`"
          class="hover:text-primary normal-case tracking-normal"
          >{{ backend }}</NuxtLink
        >
        <span>/</span>
        <span class="text-highlighted normal-case tracking-normal">Search</span>
      </template>
    </PageHeader>

    <UCard class="shadow-sm" :ui="panelCardUi">
      <template #header>
        <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
          <UIcon name="i-lucide-search" class="size-4 text-toned" />
          <span>Search Remote Content</span>
        </div>
      </template>

      <form class="space-y-5" @submit.prevent="void searchContent(false)">
        <div class="grid gap-4 lg:grid-cols-[14rem_10rem_minmax(0,1fr)]">
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
              placeholder="Search..."
              icon="i-lucide-search"
              size="sm"
              class="w-full"
              :disabled="'' === searchField || isLoading"
            />
          </UFormField>
        </div>

        <div class="flex gap-3 flex-row justify-end">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-x"
            type="button"
            class="justify-center"
            :disabled="isLoading"
            @click="clearSearch"
          >
            Reset
          </UButton>

          <UButton
            color="primary"
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
    </UCard>

    <UAlert
      v-if="isLoading && items.length < 1 && hasSearched"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="items.length < 1 && hasSearched"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No items found"
      close
      @update:open="(open) => (!open ? clearSearch() : undefined)"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p v-if="error?.message">{{ error.message }}</p>
          <p v-else>
            No items found.
            <span v-if="query">
              Search query
              <code
                ><strong>{{ searchField }}</strong
                >: <strong>{{ query }}</strong></code
              >
            </span>
          </p>
        </div>
      </template>
    </UAlert>

    <div v-else-if="items.length > 0" class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="item in items"
        :key="item.id"
        class="h-full shadow-sm"
        :class="item.watched ? 'ring-1 ring-success/20' : ''"
        :ui="resultCardUi"
      >
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <div
                class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
              >
                <UIcon
                  :name="getItemTypeIcon(item.type)"
                  class="mt-0.5 size-4 shrink-0 text-toned"
                />

                <div class="min-w-0 flex-1">
                  <FloatingImage :image="`/history/${item.id}/images/poster`" v-if="poster_enable">
                    <NuxtLink
                      v-if="item.webUrl"
                      :to="item.webUrl"
                      target="_blank"
                      class="block truncate text-highlighted hover:text-primary"
                    >
                      <UTooltip :text="String(makeName(item))">
                        <span class="block truncate">{{ makeName(item) }}</span>
                      </UTooltip>
                    </NuxtLink>
                    <UTooltip v-else :text="String(makeName(item))">
                      <span class="block truncate text-highlighted">{{ makeName(item) }}</span>
                    </UTooltip>
                  </FloatingImage>

                  <template v-else>
                    <NuxtLink
                      v-if="item.webUrl"
                      :to="item.webUrl"
                      target="_blank"
                      class="block truncate text-highlighted hover:text-primary"
                    >
                      <UTooltip :text="String(makeName(item))">
                        <span class="block truncate">{{ makeName(item) }}</span>
                      </UTooltip>
                    </NuxtLink>
                    <UTooltip v-else :text="String(makeName(item))">
                      <span class="block truncate text-highlighted">{{ makeName(item) }}</span>
                    </UTooltip>
                  </template>
                </div>
              </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-bug"
                @click="openRawData(item)"
              >
                <span class="hidden sm:inline">Raw Data</span>
              </UButton>

              <UButton
                v-if="'show' !== item.type && item.id"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-history"
                :to="`/history/${item.id}`"
              >
                <span class="hidden sm:inline">Local</span>
              </UButton>
            </div>
          </div>
        </template>

        <div class="space-y-3">
          <div
            v-if="item.title"
            class="rounded-md border border-default bg-elevated/40 px-3 py-2.5"
          >
            <div class="cursor-pointer text-sm font-medium text-default" @click="toggleOverflow">
              <div class="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap">
                <span class="inline-flex items-center gap-2">
                  <UIcon name="i-lucide-heading" class="size-4 shrink-0 text-toned" />
                  <NuxtLink
                    :to="makeSearchLink('title', item.title)"
                    class="text-highlighted hover:text-primary"
                  >
                    {{ item.title }}
                  </NuxtLink>
                </span>
              </div>
            </div>
          </div>

          <div
            v-if="item.content_path"
            class="cursor-pointer rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            @click="toggleFirstChildOverflow"
          >
            <div
              class="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-sm font-medium text-default"
            >
              <span class="inline-flex items-center gap-2">
                <UIcon name="i-lucide-file-text" class="size-4 shrink-0 text-toned" />
                <NuxtLink
                  :to="makeSearchLink('path', item.content_path)"
                  class="hover:text-primary"
                >
                  {{ item.content_path }}
                </NuxtLink>
              </span>
            </div>
          </div>
        </div>

        <template #footer>
          <div class="space-y-3">
            <div class="grid grid-cols-2 gap-2.5">
              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
                <UTooltip :text="moment.unix(getItemTimestamp(item)).format(TOOLTIP_DATE_FORMAT)">
                  <span class="cursor-help">{{
                    moment.unix(getItemTimestamp(item)).fromNow()
                  }}</span>
                </UTooltip>
              </div>

              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon name="i-lucide-database" class="size-4 shrink-0 text-toned" />
                <span :class="item.id ? '' : 'text-error'">{{
                  item.id ? 'Imported locally' : 'Not imported'
                }}</span>
              </div>
            </div>

            <div class="flex items-center justify-end gap-2">
              <UButton
                v-if="'show' === item.type"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-link"
                :to="
                  makeSearchLink(
                    'metadata',
                    `${item.via}.show://${ag(item, `metadata.${item.via}.id`)}`,
                  )
                "
              >
                View linked items
              </UButton>
            </div>
          </div>
        </template>
      </UCard>
    </div>

    <TextModal
      :open="selectedRawItem !== null"
      :title="selectedRawTitle"
      :text="selectedRawText"
      @update:open="handleRawModalOpenChange"
    />

    <UCard class="shadow-sm" :ui="panelCardUi">
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
          <li>Items marked as <code>Not imported</code> are not yet in the local database.</li>
          <li>The items shown here come from remote backend data queried directly.</li>
          <li>Clicking an item title opens the item page on the backend itself.</li>
        </ul>
      </div>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter, useHead } from '#app';
import { useStorage } from '@vueuse/core';
import FloatingImage from '~/components/FloatingImage.vue';
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
import type { SearchItem } from '~/types';

type SearchItemWithUI = SearchItem & {
  showItem?: boolean;
};

const route = useRoute();
const router = useRouter();
const poster_enable = useStorage('poster_enable', true);

const panelCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const resultCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default/70 px-4 py-4',
};

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
const show_page_tips = useStorage('show_page_tips', true);
const selectedRawItem = ref<SearchItemWithUI | null>(null);

const limitItems = computed<Array<{ label: string; value: number }>>(() =>
  limits.map((item) => ({ label: item.toString(), value: item })),
);

const searchFieldItems = computed<Array<{ label: string; value: string }>>(() =>
  searchable.map((item) => ({ label: item, value: item })),
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

const toggleOverflow = (event: Event): void => {
  (event.target as HTMLElement)?.classList?.toggle('overflow-hidden');
  (event.target as HTMLElement)?.classList?.toggle('text-ellipsis');
  (event.target as HTMLElement)?.classList?.toggle('whitespace-nowrap');
};

const toggleFirstChildOverflow = (event: Event): void => {
  const target = event.target as HTMLElement | null;

  target?.firstElementChild?.classList?.toggle('overflow-hidden');
  target?.firstElementChild?.classList?.toggle('text-ellipsis');
  target?.firstElementChild?.classList?.toggle('whitespace-nowrap');
};

const searchContent = async (fromPopState: boolean = false): Promise<void> => {
  const search = new URLSearchParams();

  if (!query.value || '' === searchField.value) {
    notification('error', 'Error', 'Search field and query are required.');
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
      error.value = { message: data.error.message, code: data.error.code };
      return;
    }

    items.value = data;
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Error', `Request error. ${errorMessage}`);
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
