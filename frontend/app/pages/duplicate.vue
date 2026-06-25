<template>
  <div class="space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="This tool is useful to discover if your backends are reporting different metadata for same files."
    >
      <template #actions>
        <UInput
          v-if="showFilter"
          id="filter"
          v-model.lazy="filter"
          type="search"
          icon="i-lucide-filter"
          placeholder="Filter displayed results."
          size="sm"
          class="w-full sm:w-72"
        />

        <UButton
          color="neutral"
          :variant="showFilter ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-filter"
          @click="toggleFilter"
          label="Filter"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-trash-2"
          @click="deleteRecords"
          label="Delete"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click.prevent="loadContent(page, true, true)"
          label="Reload"
        />
      </template>
    </PageHeader>

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :isLoading="isLoading"
      @navigate="loadContent"
    />

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
      v-else-if="filteredItems.length < 1 && filter && items.length > 1"
      color="warning"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Information"
    >
      <template #description>
        <p class="text-sm text-default">
          The filter <code>{{ filter }}</code> did not match any thing.
        </p>
      </template>
    </UAlert>

    <UAlert
      v-else-if="filteredItems.length < 1"
      color="success"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Success"
      description="There are no duplicate file references in the database."
    />

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <Lazy
        v-for="item in filteredItems"
        :key="item.id"
        :unrender="true"
        :min-height="270"
        class="block"
      >
        <UCard
          class="h-full shadow-sm"
          :class="item.watched ? 'ring-1 ring-success/20' : ''"
          :ui="itemCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex min-w-0 items-start gap-2">
                  <UIcon
                    :name="'episode' === item.type.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />

                  <div class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                    <FloatingImage
                      :image="`/history/${item.id}/images/poster`"
                      v-if="poster_enable"
                    >
                      <UTooltip
                        :text="String(item?.full_title || makeName(item as unknown as JsonObject))"
                      >
                        <NuxtLink
                          :to="'/history/' + item.id"
                          class="block truncate text-highlighted hover:text-primary"
                        >
                          {{ item?.full_title || makeName(item as unknown as JsonObject) }}
                        </NuxtLink>
                      </UTooltip>
                    </FloatingImage>

                    <UTooltip
                      v-else
                      :text="String(item?.full_title || makeName(item as unknown as JsonObject))"
                    >
                      <NuxtLink
                        :to="'/history/' + item.id"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item?.full_title || makeName(item as unknown as JsonObject) }}
                      </NuxtLink>
                    </UTooltip>
                  </div>

                  <div class="flex shrink-0 items-center gap-2">
                    <Popover
                      v-if="(item?.duplicate_reference_ids?.length || 0) > 0"
                      placement="top"
                      :trigger="duplicatePopoverTrigger"
                      :show-delay="200"
                      :hide-delay="200"
                      :offset="8"
                      content-class="p-0"
                    >
                      <template #trigger>
                        <span
                          class="inline-flex items-center gap-1 rounded-md border border-warning/30 bg-warning/10 px-2 py-1 text-xs font-semibold text-warning"
                        >
                          <UIcon name="i-lucide-layers-3" class="size-3.5" />
                          <span>{{ item.duplicate_reference_ids?.length }}</span>
                        </span>
                      </template>

                      <template #content>
                        <DuplicateRecordList :ids="item.duplicate_reference_ids ?? []" />
                      </template>
                    </Popover>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <div class="space-y-3">
            <div
              class="flex items-start gap-2 rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
            >
              <button
                type="button"
                class="mt-0.5 shrink-0 text-toned hover:text-primary"
                @click="item.expand_title = !item?.expand_title"
              >
                <UIcon name="i-lucide-heading" class="size-4" />
              </button>

              <div
                class="min-w-0 flex-1"
                :class="item?.expand_title ? 'wrap-break-word' : 'truncate'"
              >
                <NuxtLink
                  :to="makeSearchLink('subtitle', item?.content_title || item.title)"
                  class="hover:text-primary"
                >
                  {{ item?.content_title || item.title }}
                </NuxtLink>
              </div>

              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                square
                icon="i-lucide-copy"
                aria-label="Copy title"
                @click="copyText(item?.content_title ?? item.title, false)"
              />
            </div>

            <div
              class="flex items-start gap-2 rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
            >
              <button
                type="button"
                class="mt-0.5 shrink-0 text-toned hover:text-primary"
                @click="item.expand_path = !item?.expand_path"
              >
                <UIcon name="i-lucide-file-text" class="size-4" />
              </button>

              <div class="min-w-0 flex-1">
                <Popover
                  v-if="item?.content_path && hasFileDifferences(item)"
                  placement="bottom-start"
                  :trigger="duplicatePopoverTrigger"
                  :show-delay="200"
                  :hide-delay="200"
                  :offset="8"
                  content-class="p-0"
                >
                  <template #trigger>
                    <NuxtLink
                      :to="makeSearchLink('path', item.content_path)"
                      :class="item?.expand_path ? 'wrap-break-word' : 'block truncate'"
                      class="hover:text-primary"
                    >
                      {{ item.content_path }}
                    </NuxtLink>
                  </template>

                  <template #content>
                    <div class="min-w-75 max-w-125 p-3">
                      <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-warning">
                        <UIcon name="i-lucide-triangle-alert" class="size-4" />
                        <span>Path Differences Found</span>
                      </div>
                      <FileDiff :items="getFileDiffData(item)" />
                    </div>
                  </template>
                </Popover>

                <NuxtLink
                  v-else-if="item?.content_path"
                  :to="makeSearchLink('path', item.content_path)"
                  :class="item?.expand_path ? 'wrap-break-word' : 'block truncate'"
                  class="hover:text-primary"
                >
                  {{ item.content_path }}
                </NuxtLink>

                <span v-else>No path found.</span>
              </div>

              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                square
                icon="i-lucide-copy"
                aria-label="Copy path"
                @click="copyText(item?.content_path || '', false)"
              />
            </div>

            <div
              class="rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                <UIcon name="i-lucide-server" class="size-4 text-toned" />
                <span>Has metadata from</span>
              </div>

              <div class="flex flex-wrap gap-2">
                <NuxtLink
                  v-for="backend in item.reported_by"
                  :key="`${item.id}-rb-${backend}`"
                  :to="`/backend/${backend}`"
                  class="inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default"
                >
                  <UIcon name="i-lucide-server" class="size-3.5" />
                  {{ backend }}
                </NuxtLink>

                <NuxtLink
                  v-for="backend in item.not_reported_by"
                  :key="`${item.id}-nrb-${backend}`"
                  :to="`/backend/${backend}`"
                  class="inline-flex items-center gap-1.5 rounded-md border border-error/30 bg-error/10 px-2.5 py-1 text-xs font-medium text-error"
                >
                  <UIcon name="i-lucide-circle-alert" class="size-3.5" />
                  {{ backend }}
                </NuxtLink>
              </div>
            </div>
          </div>

          <template #footer>
            <div class="grid grid-cols-2 gap-2">
              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-sm text-default"
              >
                <UIcon
                  :name="item.watched ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                  class="size-4 text-toned"
                />
                <span :class="item.watched ? 'text-success' : 'text-error'">
                  {{ item.watched ? 'Played' : 'Unplayed' }}
                </span>
              </div>

              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-sm text-default"
              >
                <UIcon name="i-lucide-calendar" class="size-4 text-toned" />
                <UTooltip
                  :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  <span class="cursor-help">{{ moment.unix(item.updated_at).fromNow() }}</span>
                </UTooltip>
              </div>
            </div>
          </template>
        </UCard>
      </Lazy>
    </div>

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </span>

          <span class="inline-flex items-center gap-1 text-xs font-medium text-toned">
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4"
            />
            <span>{{ show_page_tips ? 'Hide' : 'Show' }}</span>
          </span>
        </button>
      </template>

      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li>
          This checker will only works
          <b>if your media servers are actually using same file paths</b>.
        </li>
        <li>
          If you see multi-episode records, that mean your metadata need to be forcibly updated. Go
          to backends page and select the <code>9th</code> option to force metadata update for that
          backend.
        </li>
        <li>
          The initial request is quite slow as we traverse the entire database looking for duplicate
          file references. Once the initial request is done, the subsequent requests will be much
          faster as we cache the results. To force cache invalidation, you have to click on the
          reload button.
        </li>
      </ul>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useBreakpoints, useStorage } from '@vueuse/core';
import moment from 'moment';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import FloatingImage from '~/components/FloatingImage.vue';
import FileDiff from '~/components/FileDiff.vue';
import Popover from '~/components/Popover.vue';
import DuplicateRecordList from '~/components/DuplicateRecordList.vue';
import Pager from '~/components/Pager.vue';
import { NuxtLink } from '#components';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  awaitElement,
  copyText,
  makeName,
  makeSearchLink,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import type {
  FileDiffInput,
  GenericError,
  GenericResponse,
  HistoryItem,
  JsonObject,
} from '~/types';

const pageShell = requireTopLevelPageShell('duplicate');

type DuplicateItemWithUI = HistoryItem & {
  expand_title?: boolean;
  expand_path?: boolean;
};

const route = useRoute();
const router = useRouter();

useHead({ title: 'DFR' });

const show_page_tips = useStorage('show_page_tips', true);
const poster_enable = useStorage('poster_enable', true);
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });

const items = ref<Array<DuplicateItemWithUI>>([]);
const page = ref<number>(Number(route.query.page) || 1);
const perpage = ref<number>(Number(route.query.perpage) || 50);
const total = ref<number>(0);
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));
const isLoading = ref<boolean>(false);
const filter = ref<string>(String(route.query.filter || ''));
const showFilter = ref<boolean>(!!filter.value);

const itemCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default px-4 py-4',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value) {
    filter.value = '';
    return;
  }

  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const hasFileDifferences = (item: DuplicateItemWithUI): boolean => getFileDiffData(item).length > 0;

const getFileDiffData = (item: DuplicateItemWithUI): Array<FileDiffInput> => {
  if (!item?.metadata) {
    return [];
  }

  const fileGroups: Record<string, Array<string>> = {};

  for (const bName of Object.keys(item.metadata)) {
    const bNameTyped = bName as keyof typeof item.metadata;
    if (!item.metadata[bNameTyped]) {
      continue;
    }

    const file = item.metadata[bNameTyped]?.path || '';

    if (!file) {
      continue;
    }

    if (!fileGroups[file]) {
      fileGroups[file] = [];
    }

    fileGroups[file].push(bName);
  }

  let referenceFile = '';
  let maxBackends = 0;

  for (const [file, backends] of Object.entries(fileGroups)) {
    if (backends.length > maxBackends) {
      maxBackends = backends.length;
      referenceFile = file;
    }
  }

  if (!referenceFile || Object.keys(fileGroups).length <= 1) {
    return [];
  }

  const diffItems: Array<FileDiffInput> = [];
  const referenceBackends = fileGroups[referenceFile] || [];
  const referenceBackendName =
    referenceBackends.length > 1 ? referenceBackends.sort().join(', ') : referenceBackends[0] || '';

  diffItems.push({
    backend: referenceBackendName,
    file: referenceFile,
  });

  for (const [file, backends] of Object.entries(fileGroups)) {
    if (file !== referenceFile) {
      const mergedBackendName =
        backends.length > 1 ? backends.sort().join(', ') : backends[0] || '';
      diffItems.push({
        backend: mergedBackendName,
        file: file,
      });
    }
  }

  return diffItems;
};

const loadContent = async (
  pageNumber: number,
  fromPopState = false,
  fromReload = false,
): Promise<void> => {
  pageNumber = parseInt(String(pageNumber));

  if (isNaN(pageNumber) || 1 > pageNumber) {
    pageNumber = 1;
  }

  const search = new URLSearchParams();
  search.set('perpage', String(perpage.value));
  search.set('page', String(pageNumber));

  let pageTitle = `DFR: Page #${pageNumber}`;

  if (filter.value) {
    search.set('filter', filter.value);
    pageTitle += ` - Filter: ${filter.value}`;
  }

  useHead({ title: pageTitle });

  const newUrl = window.location.pathname + '?' + search.toString();
  isLoading.value = true;
  items.value = [];

  page.value = pageNumber;

  try {
    if (true === fromReload) {
      search.set('no_cache', '1');
    }

    const response = await request(`/system/duplicate/?${search.toString()}`);

    const json = await parse_api_response<{
      items: Array<DuplicateItemWithUI>;
      paging: { current_page: number; perpage: number; total: number };
    }>(response);

    if ('duplicate' !== useRoute().name) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
      return;
    }

    if (!fromPopState && newUrl !== window.location.href) {
      await router.push({ path: '/duplicate', query: Object.fromEntries(search) });
    }

    if ('paging' in json && json.paging) {
      page.value = json.paging.current_page;
      perpage.value = json.paging.perpage;
      total.value = json.paging.total;
    } else {
      page.value = 1;
      total.value = 0;
    }

    if (json.items) {
      items.value = json.items;
    }
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async () => {
  await loadContent(page.value || 1);
  window.addEventListener('popstate', stateCallBack);
});

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack));

const filteredRows = (items: Array<DuplicateItemWithUI>): Array<DuplicateItemWithUI> => {
  if (!filter.value) {
    return items;
  }

  return items.filter((i) => stringifyItem(i).includes(filter.value.toLowerCase()));
};

const filteredItems = computed(() => filteredRows(items.value as Array<DuplicateItemWithUI>));

const duplicatePopoverTrigger = computed<'click' | 'hover'>(() =>
  'mobile' === breakpoints.active().value ? 'click' : 'hover',
);

const stringifyItem = (item: DuplicateItemWithUI): string => {
  return JSON.stringify(item).toLowerCase();
};

watch(filter, (val: string) => {
  if (!val) {
    if (!route?.query['filter']) {
      return;
    }

    router.push({
      path: '/duplicate',
      query: {
        ...route.query,
        filter: undefined,
      },
    });
    return;
  }

  if (val === route?.query['filter']) {
    return;
  }

  router.push({
    path: '/duplicate',
    query: {
      ...route.query,
      filter: val,
    },
  });
});

const stateCallBack = async (e: PopStateEvent): Promise<void> => {
  if (!e.state) {
    return;
  }

  const route = useRoute();
  page.value = Number(route.query.page) || 1;
  perpage.value = Number(route.query.perpage) || 50;
  filter.value = String(route.query.filter || '');
  if (filter.value) {
    showFilter.value = true;
  }
  await loadContent(page.value, true);
};

const deleteRecords = async (): Promise<void> => {
  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: `Delete '${total.value}' items?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request('/system/duplicate', { method: 'DELETE' });
    if (!response.ok) {
      const json = await parse_api_response<GenericResponse>(response);
      if ('error' in json) {
        const errorJson = json as GenericError;
        notification(
          'error',
          'Error',
          `API Error. ${errorJson.error.code}: ${errorJson.error.message}`,
        );
      } else {
        notification('error', 'Error', 'API Error.');
      }
      return;
    }

    notification('success', 'Success', `Successfully deleted '${total.value}' items.`);
    await loadContent(page.value, true, true);
  } catch (error: unknown) {
    const err = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${err}`);
  }
};
</script>
