<template>
  <div class="space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="This page shows local database records not being reported by the specified number of backends."
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
        >
          <span class="hidden sm:inline">Filter</span>
        </UButton>

        <UTooltip v-if="min && max" text="Minimum number of backends">
          <USelect
            v-model="min"
            :items="minItems"
            value-key="value"
            size="sm"
            class="w-20"
            :disabled="isDeleting || isLoading"
          />
        </UTooltip>

        <UTooltip text="Delete The reported records">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            :loading="isDeleting"
            :disabled="isDeleting || isLoading || items.length < 1"
            @click="deleteData"
          >
            <span class="hidden sm:inline">Delete</span>
          </UButton>
        </UTooltip>

        <UButton
          color="neutral"
          :variant="selectAll ? 'soft' : 'outline'"
          size="sm"
          :icon="!selectAll ? 'i-lucide-square-check' : 'i-lucide-square'"
          @click="selectAll = !selectAll"
        >
          <span class="hidden sm:inline">{{ !selectAll ? 'Select' : 'Unselect' }}</span>
        </UButton>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click.prevent="loadContent(page, true, true)"
        >
          <span class="hidden sm:inline">Reload</span>
        </UButton>
      </template>
    </PageHeader>

    <Pager
      v-if="total && last_page > 1"
      :page="page"
      :last_page="last_page"
      :isLoading="isLoading"
      @navigate="loadContent"
    />

    <div
      v-if="selected_ids.length > 0"
      class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default bg-elevated/30 px-3 py-3"
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
          @click="massDelete"
        >
          Delete
        </UButton>
      </div>

      <div class="text-xs text-toned">{{ filteredRows(items).length }} displayed</div>
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
      v-else-if="filteredRows(items).length < 1 && filter && items.length > 1"
      color="warning"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Information"
    >
      <template #description>
        <p class="text-sm text-default">
          The filter <code>{{ filter }}</code> did not match any records.
        </p>
      </template>
    </UAlert>

    <UAlert
      v-else-if="filteredRows(items).length < 1"
      color="success"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Success"
    >
      <template #description>
        <p class="text-sm text-default">
          WatchState did not find any records matching the criteria. All records has at least
          <code>{{ min }}</code> backends reporting it.
        </p>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <Lazy
        v-for="item in items"
        v-show="filterItem(item)"
        :key="item.id"
        :unrender="true"
        :min-height="343"
        class="block"
      >
        <UCard
          class="h-full shadow-sm"
          :class="item.watched ? 'ring-1 ring-success/20' : ''"
          :ui="itemCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex flex-1 items-start gap-2">
                <UIcon
                  :name="'episode' === item.type.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film'"
                  class="mt-0.5 size-4 shrink-0 text-toned"
                />

                <div class="min-w-0 flex-1 text-base font-semibold text-highlighted">
                  <FloatingImage :image="`/history/${item.id}/images/poster`" v-if="poster_enable">
                    <UTooltip :text="String(makeName(item))">
                      <NuxtLink
                        :to="`/history/${item.id}`"
                        class="block truncate text-highlighted hover:text-primary"
                        >{{ makeName(item) }}</NuxtLink
                      >
                    </UTooltip>
                  </FloatingImage>
                  <UTooltip v-else :text="String(makeName(item))">
                    <NuxtLink
                      :to="`/history/${item.id}`"
                      class="block truncate text-highlighted hover:text-primary"
                      >{{ makeName(item) }}</NuxtLink
                    >
                  </UTooltip>
                </div>
              </div>

              <UTooltip :text="selected_ids.includes(item.id) ? 'Unselect item' : 'Select item'">
                <UCheckbox
                  color="primary"
                  :model-value="selected_ids.includes(item.id)"
                  @update:model-value="toggleSelected(item.id, $event)"
                />
              </UTooltip>
            </div>
          </template>

          <div class="space-y-3">
            <div
              class="flex items-start justify-between gap-3 rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            >
              <div
                class="min-w-0 flex-1 cursor-pointer"
                :class="
                  item?.expand_title
                    ? 'wrap-break-word'
                    : 'overflow-hidden text-ellipsis whitespace-nowrap'
                "
                @click="item.expand_title = !item?.expand_title"
              >
                <span class="inline-flex items-center gap-2 text-sm font-medium text-default">
                  <UIcon name="i-lucide-heading" class="size-4 shrink-0 text-toned" />
                  <NuxtLink
                    :to="makeSearchLink('subtitle', item?.content_title || item.title)"
                    class="hover:text-primary"
                  >
                    {{ item?.content_title || item.title }}
                  </NuxtLink>
                </span>
              </div>

              <UTooltip text="Copy title">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  square
                  icon="i-lucide-copy"
                  aria-label="Copy title"
                  @click="copyText(item?.content_title ?? item.title, false)"
                />
              </UTooltip>
            </div>

            <div
              class="flex items-start justify-between gap-3 rounded-md border border-default bg-elevated/40 px-3 py-2.5"
            >
              <div
                class="min-w-0 flex-1 cursor-pointer"
                :class="
                  item?.expand_path
                    ? 'wrap-break-word'
                    : 'overflow-hidden text-ellipsis whitespace-nowrap'
                "
                @click="item.expand_path = !item?.expand_path"
              >
                <span class="inline-flex items-center gap-2 text-sm font-medium text-default">
                  <UIcon name="i-lucide-file-text" class="size-4 shrink-0 text-toned" />
                  <NuxtLink
                    v-if="item?.content_path"
                    :to="makeSearchLink('path', item.content_path)"
                    class="hover:text-primary"
                  >
                    {{ item.content_path }}
                  </NuxtLink>
                  <span v-else>No path found.</span>
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
                  @click="copyText(item?.content_path || '', false)"
                />
              </UTooltip>
            </div>

            <div class="rounded-md border border-default bg-elevated/40 px-3 py-2.5">
              <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                <UIcon name="i-lucide-server" class="size-4 text-toned" />
                <span>Has metadata from</span>
              </div>

              <div class="flex flex-wrap gap-2">
                <NuxtLink
                  v-for="reportedBackend in item.reported_by"
                  :key="`${item.id}-rb-${reportedBackend}`"
                  :to="'/backend/' + reportedBackend"
                  class="inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default"
                >
                  <UIcon name="i-lucide-server" class="size-3.5" />
                  {{ reportedBackend }}
                </NuxtLink>
                <NuxtLink
                  v-for="missingBackend in item.not_reported_by"
                  :key="`${item.id}-nrb-${missingBackend}`"
                  :to="'/backend/' + missingBackend"
                  class="inline-flex items-center gap-1.5 rounded-md border border-error/30 bg-error/10 px-2.5 py-1 text-xs font-medium text-error"
                >
                  <UIcon name="i-lucide-circle-alert" class="size-3.5" />
                  {{ missingBackend }}
                </NuxtLink>
              </div>
            </div>
          </div>

          <template #footer>
            <div class="grid grid-cols-2 gap-2.5">
              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon
                  :name="item.watched ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                  class="size-4 text-toned"
                />
                <span :class="item.watched ? 'text-success' : 'text-error'">{{
                  item.watched ? 'Played' : 'Unplayed'
                }}</span>
              </div>
              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
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
          You can specify the minimum number of backends that need to report the record to be
          considered valid.
        </li>
        <li>
          By clicking the
          <UIcon name="i-lucide-trash-2" class="inline size-4 align-text-bottom" /> icon you will
          delete the the reported items from the local database. If the items are not fixed by the
          time <code>import</code> is run, they will re-appear.
        </li>
        <li>
          Deleting records works by deleting everything at or below the specified number of
          backends. For example, if you set the minimum to <code>3</code>, all records that are
          reported by <code>3</code> or fewer backends will be deleted.
        </li>
        <li>
          Records showing here most likely means your backends, are not reporting same data. This
          could be due to many reasons, including using different external databases i.e.
          <code>TheMovieDB</code> vs <code>TheTVDB</code>.
        </li>
        <li>
          The results are cached in your browser temporarily to provide faster response, as the
          operation to generate the report is quite intensive. If you want to refresh the data,
          click the
          <UIcon name="i-lucide-refresh-cw" class="inline size-4 align-text-bottom" /> icon.
        </li>
      </ul>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { useSessionCache } from '~/utils/cache';
import {
  request,
  awaitElement,
  copyText,
  makeName,
  makeSearchLink,
  notification,
  parse_api_response,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import moment from 'moment';
import { NuxtLink } from '#components';
import FloatingImage from '~/components/FloatingImage.vue';
import { useDialog } from '~/composables/useDialog';
import type { ParityItem, PaginatedResponse, ExpandableUIState } from '~/types';

type ParityItemWithUI = ParityItem & ExpandableUIState;

type APIResponse = PaginatedResponse<ParityItemWithUI>;

const pageShell = requireTopLevelPageShell('parity');

type SelectItem = {
  label: string;
  value: number;
};

const route = useRoute();
const router = useRouter();

useHead({ title: 'Parity' });

const show_page_tips = useStorage('show_page_tips', true);
const api_user = useStorage('api_user', 'main');
const poster_enable = useStorage('poster_enable', true);

const items = ref<Array<ParityItemWithUI>>([]);
const page = ref<number>(Number(route.query.page ?? 1));
const perpage = ref<number>(Number(route.query.perpage ?? 100));
const total = ref<number>(0);
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));
const isLoading = ref<boolean>(false);
const isDeleting = ref<boolean>(false);
const filter = ref<string>(String(route.query.filter ?? ''));
const showFilter = ref<boolean>(!!filter.value);
const min = ref<number | null>(route.query.min ? Number(route.query.min) : null);
const max = ref<number>();
const cacheKey = computed<string>(() => `parity_v1-${min.value}-${page.value}-${perpage.value}`);

const selectAll = ref<boolean>(false);
const selected_ids = ref<Array<string | number>>([]);
const massActionInProgress = ref<boolean>(false);

const cache = useSessionCache(api_user.value);

const itemCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const minItems = computed<Array<SelectItem>>(() =>
  !max.value ? [] : numberRange(1, max.value + 1).map((value) => ({ label: String(value), value })),
);

watch(selectAll, (v) => {
  selected_ids.value = v ? filteredRows(items.value).map((i) => i.id) : [];
});

const toggleSelected = (id: string | number, value: boolean | 'indeterminate'): void => {
  if (true === value) {
    if (!selected_ids.value.includes(id)) {
      selected_ids.value.push(id);
    }
    return;
  }

  selected_ids.value = selected_ids.value.filter((itemId) => itemId !== id);
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value) {
    filter.value = '';
    return;
  }
  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const loadContent = async (
  pageNumber: number,
  fromPopState = false,
  fromReload = false,
): Promise<void> => {
  pageNumber = Number(pageNumber);
  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1;
  }

  const search = new URLSearchParams();
  search.set('perpage', String(perpage.value));
  search.set('page', String(pageNumber));
  let pageTitle = `Parity: Page #${pageNumber}`;

  if (min.value) {
    search.set('min', String(min.value));
    pageTitle += ` - Min: ${min.value}`;
  }

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
    let json;

    if (true === fromReload) {
      clearCache();
    }

    if (cache.has(cacheKey.value)) {
      json = cache.get(cacheKey.value) as APIResponse;
    } else {
      const response = await request(`/system/parity/?${search.toString()}`);
      json = await parse_api_response<APIResponse>(response);

      if ('parity' !== useRoute().name) {
        return;
      }

      if ('error' in json) {
        notification(
          'error',
          'Error',
          `API Error. ${json.error?.code ?? ''}: ${json.error?.message ?? ''}`,
        );
        return;
      }

      cache.set(cacheKey.value, json);
    }

    if (!fromPopState && window.location.href !== newUrl) {
      await router.push({ path: '/parity', query: Object.fromEntries(search) });
    }

    if ('paging' in json) {
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
    selectAll.value = false;
    selected_ids.value = [];
  }
};

const massDelete = async (): Promise<void> => {
  if (0 === selected_ids.value.length) {
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete '${selected_ids.value.length}' item/s?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    massActionInProgress.value = true;
    const urls = selected_ids.value.map((id) => `/history/${id}`);

    notification(
      'success',
      'Action in progress',
      `Deleting '${urls.length}' item/s. Please wait...`,
    );

    const requests = await Promise.all(urls.map((url) => request(url, { method: 'DELETE' })));

    if (!requests.every((response) => 200 === response.status)) {
      notification(
        'error',
        'Error',
        `Some requests failed. Please check the console for more details.`,
      );
    } else {
      items.value = items.value.filter((i) => !selected_ids.value.includes(i.id));
      try {
        cache.remove(cacheKey.value);
      } catch {}
    }

    notification('success', 'Success', `Deleting '${urls.length}' item/s completed.`);
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`);
  } finally {
    massActionInProgress.value = false;
    selected_ids.value = [];
    selectAll.value = false;
  }
};

const deleteData = async (): Promise<void> => {
  if (isDeleting.value) {
    return;
  }

  if (!min.value) {
    notification('error', 'Error', 'Minimum number of backends is not set.');
    return;
  }

  if (items.value.length < 1) {
    notification('error', 'Error', 'There are no reported records to delete.');
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete all reported records?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  isDeleting.value = true;

  try {
    const response = await request(`/system/parity`, {
      method: 'DELETE',
      body: JSON.stringify({ min: min.value }),
    });

    const json = await response.json();
    if (response.status !== 200) {
      notification('error', 'Error', `${json.error?.code ?? ''}: ${json.error?.message ?? ''}`);
      return;
    }

    notification('success', 'Success!', `Deleted '${json.deleted_records ?? 0}' records.`);

    items.value = [];
    total.value = 0;
    filter.value = '';
    page.value = 1;

    clearCache();
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', error.message);
  } finally {
    isDeleting.value = false;
  }
};

onMounted(async () => {
  const response = await request(`/backends/`);
  const json: Array<string> = await response.json();
  cache.setNameSpace(api_user.value);

  max.value = json.length;

  if (null === min.value) {
    min.value = json.length;
  } else {
    await loadContent(page.value ?? 1);
  }

  window.addEventListener('popstate', stateCallBack);
});

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack));

const numberRange = (start: number, end: number): Array<number> =>
  new Array(end - start).fill(0).map((_, i) => i + start);

const filteredRows = (items: Array<ParityItemWithUI>): Array<ParityItemWithUI> => {
  if (!filter.value) {
    return items;
  }

  return items.filter((i) =>
    Object.values(i).some((v) =>
      typeof v === 'string' ? v.toLowerCase().includes(filter.value.toLowerCase()) : false,
    ),
  );
};

const filterItem = (item: ParityItemWithUI): boolean => {
  if (!filter.value || !item) {
    return true;
  }

  return Object.values(item).some((v) =>
    typeof v === 'string' ? v.toLowerCase().includes(filter.value.toLowerCase()) : false,
  );
};

watch(min, async () => await loadContent(page.value ?? 1));
watch(filter, (val) => {
  if (!val) {
    if (!route?.query['filter']) {
      return;
    }

    router.push({ path: '/parity', query: { ...route.query, filter: undefined } });
    return;
  }

  if (route?.query['filter'] === val) {
    return;
  }

  router.push({ path: '/parity', query: { ...route.query, filter: val } });
});

const clearCache = (): void => {
  cache.clear((k: string) => k.startsWith(`${api_user.value}:parity`));
};

const stateCallBack = async (e: PopStateEvent): Promise<void> => {
  if (!e.state && !(e as { detail?: unknown }).detail) {
    return;
  }

  const route = useRoute();
  page.value = Number(route.query.page ?? 1);
  perpage.value = Number(route.query.perpage ?? 50);
  filter.value = String(route.query.filter ?? '');
  if (filter.value) {
    showFilter.value = true;
  }
  await loadContent(page.value, true);
};
</script>
