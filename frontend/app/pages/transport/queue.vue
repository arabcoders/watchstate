<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent(requestedPage)"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Transport filters" class="space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <div class="w-full space-y-1.5 sm:w-44">
          <label for="transport-state" class="text-xs font-medium text-toned">State</label>
          <USelect
            id="transport-state"
            v-model="stateFilter"
            :items="stateItems"
            value-key="value"
            label-key="label"
            color="neutral"
            variant="outline"
            icon="i-lucide-filter"
            class="w-full"
            :disabled="isLoading"
            @update:model-value="navigatePage(1)"
          />
        </div>
        <div class="min-w-0 flex-1 basis-full space-y-1.5 sm:basis-64">
          <label for="transport-filter" class="text-xs font-medium text-toned">
            Filter this page
          </label>
          <UInput
            id="transport-filter"
            v-model="filter"
            type="search"
            placeholder="Event, envelope ID, state, or created time"
            icon="i-lucide-list-filter"
            class="w-full"
          />
        </div>
        <div class="w-36 space-y-1.5">
          <label for="transport-perpage" class="text-xs font-medium text-toned"
            >Items per page</label
          >
          <USelect
            id="transport-perpage"
            v-model="perpage"
            :items="perPageItems"
            value-key="value"
            label-key="label"
            color="neutral"
            variant="outline"
            class="w-full"
            :disabled="isLoading"
            @update:model-value="navigatePage(1)"
          />
        </div>
      </div>
      <div
        v-if="'__all__' !== stateFilter || filter.trim()"
        class="flex flex-wrap items-center gap-2"
        aria-label="Active filters"
      >
        <span class="text-xs text-muted">Applied</span>
        <UButton
          v-if="'__all__' !== stateFilter"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          aria-label="Remove state filter"
          :disabled="isLoading"
          @click="
            () => {
              stateFilter = '__all__';
              navigatePage(1);
            }
          "
        >
          State: {{ stateFilter }}
        </UButton>
        <UButton
          v-if="filter.trim()"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          aria-label="Remove page filter"
          class="max-w-full"
          @click="filter = ''"
        >
          <span class="truncate">This page: {{ filter.trim() }}</span>
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-x"
          :disabled="isLoading"
          @click="clearFilters"
        >
          Clear all
        </UButton>
      </div>
    </section>

    <section aria-label="Transport items" :aria-busy="isLoading" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="space-y-1 text-sm text-toned" aria-live="polite" aria-atomic="true">
          <p v-if="hasResults">
            <strong class="font-medium text-highlighted">{{ filteredItems.length }}</strong>
            {{ filter.trim() ? `of ${items.length} items` : 'items' }} on this page
          </p>
          <p v-if="isLoading" class="inline-flex items-center gap-1.5 text-xs">
            <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />
            {{ hasResults ? 'Refreshing transport items…' : 'Loading transport items…' }}
          </p>
        </div>
        <Pager
          v-if="0 < total && 1 < last_page"
          :page="page"
          :last_page="last_page"
          :is-loading="isLoading"
          @navigate="navigatePage"
        />
      </div>

      <UAlert
        v-if="error"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="
          hasResults
            ? 'Transport items could not be refreshed'
            : 'Transport items could not be loaded'
        "
      >
        <template #description>
          <div class="space-y-2">
            <p class="wrap-break-word">{{ error }}</p>
            <p v-if="hasResults">
              Showing the last loaded results. They may be out of date or use different filters.
            </p>
          </div>
        </template>
      </UAlert>

      <UEmpty
        v-if="hasResults && !isLoading && !error && 0 === filteredItems.length"
        :icon="filter.trim() ? 'i-lucide-search' : 'i-lucide-inbox'"
        :title="
          filter.trim()
            ? 'No matches on this page'
            : '__all__' !== stateFilter
              ? `No ${stateFilter} transport items`
              : 'Transport queue is empty'
        "
      >
        <template v-if="filter.trim() || '__all__' !== stateFilter" #actions>
          <UButton
            v-if="filter.trim()"
            color="neutral"
            variant="outline"
            icon="i-lucide-x"
            @click="filter = ''"
          >
            Clear page filter
          </UButton>
          <UButton
            v-else
            color="neutral"
            variant="outline"
            icon="i-lucide-x"
            @click="
              () => {
                stateFilter = '__all__';
                navigatePage(1);
              }
            "
          >
            All states
          </UButton>
        </template>
      </UEmpty>

      <div v-if="0 < filteredItems.length" class="ws-card divide-y divide-default overflow-hidden">
        <article
          v-for="item in filteredItems"
          :key="item.id"
          class="grid min-w-0 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center lg:grid-cols-[minmax(0,1fr)_8rem_11rem]"
        >
          <div class="min-w-0 space-y-1">
            <button
              type="button"
              class="block max-w-full truncate text-left text-sm font-semibold text-highlighted hover:text-primary focus-visible:outline-primary"
              :title="item.event"
              :aria-label="`View details for ${item.event}, envelope ${item.id}`"
              @click="() => void openItem(item)"
            >
              {{ item.event }}
            </button>
            <UTooltip :text="item.id">
              <p class="truncate font-mono text-xs text-muted">
                <span class="font-sans">Envelope </span>{{ item.id }}
              </p>
            </UTooltip>
          </div>
          <div>
            <UBadge :color="stateColor(item.state)" variant="soft">
              <UIcon :name="stateIcon(item.state)" class="size-3.5" />
              <span class="capitalize">{{ item.state }}</span>
            </UBadge>
          </div>
          <div class="text-xs text-toned">
            <span class="text-muted">Created </span>
            <UTooltip :text="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)">
              <time :datetime="item.created_at">{{ moment(item.created_at).fromNow() }}</time>
            </UTooltip>
          </div>
        </article>
      </div>

      <div
        v-if="0 < total && 1 < last_page"
        class="flex flex-wrap items-center justify-between gap-3"
      >
        <p class="text-xs text-muted">Page {{ page }} of {{ last_page }}</p>
        <Pager
          :page="page"
          :last_page="last_page"
          :is-loading="isLoading"
          @navigate="navigatePage"
        />
      </div>
    </section>

    <UModal
      v-model:open="detailOpen"
      :title="selectedSummary ? `Transport item #${shortId(selectedSummary.id)}` : 'Transport item'"
      :ui="{ content: 'max-w-4xl', body: 'p-4 sm:p-5' }"
    >
      <template #body>
        <UAlert
          v-if="detailLoading"
          color="info"
          variant="soft"
          icon="i-lucide-loader-circle"
          title="Loading envelope"
          :ui="{ icon: 'animate-spin' }"
        />
        <UAlert
          v-else-if="detailError"
          color="error"
          variant="soft"
          icon="i-lucide-circle-alert"
          title="Unable to load envelope"
        >
          <template #description>
            <div class="space-y-2">
              <p>{{ detailError }}</p>
              <p>Items can leave the queue. Close this view and refresh the list to check again.</p>
            </div>
          </template>
        </UAlert>
        <TransportQueueView v-else-if="selectedItem" :item="selectedItem" />
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import {
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UIcon,
  UInput,
  UModal,
  USelect,
  UTooltip,
} from '#components';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import TransportQueueView from '~/components/TransportQueueView.vue';
import type { TransportQueueDetail, TransportQueueItem } from '~/types';
import { notification, parse_api_response, request, TOOLTIP_DATE_FORMAT } from '~/utils';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

type StateFilter = '__all__' | TransportQueueItem['state'];

const pageShell = requireTopLevelPageShell('transport-queue');
const route = useRoute();
const router = useRouter();
const routeState = String(route.query.state ?? '__all__');
const routePage = Number(route.query.page ?? 1);
const routePerpage = Number(route.query.perpage ?? 25);
const items = ref<Array<TransportQueueItem>>([]);
const isLoading = ref<boolean>(false);
const hasResults = ref<boolean>(false);
const error = ref<string>('');
const filter = ref<string>(String(route.query.filter ?? ''));
const stateFilter = ref<StateFilter>(
  ['pending', 'processing', 'failed'].includes(routeState)
    ? (routeState as StateFilter)
    : '__all__',
);
const availableStates = ref<Array<TransportQueueItem['state']>>([]);
const selectedSummary = ref<TransportQueueItem | null>(null);
const selectedItem = ref<TransportQueueDetail | null>(null);
const detailLoading = ref<boolean>(false);
const detailError = ref<string>('');
const detailOpen = ref<boolean>(false);
const requestedPage = ref<number>(Number.isInteger(routePage) && 0 < routePage ? routePage : 1);
const page = ref<number>(requestedPage.value);
const perpage = ref<number>(
  Number.isInteger(routePerpage) && 0 < routePerpage ? Math.min(100, routePerpage) : 25,
);
const loadedPerpage = ref<number>(perpage.value);
const total = ref<number>(0);
let listRequest = 0;
let detailRequest = 0;
let requestedKey = '';

const stateItems = computed(() => [
  { label: 'All states', value: '__all__' },
  ...availableStates.value.map((state) => ({
    label: state.charAt(0).toUpperCase() + state.slice(1),
    value: state,
  })),
]);
const perPageItems = [25, 50, 100].map((value) => ({
  label: String(value),
  value,
}));
const last_page = computed<number>(() => Math.max(1, Math.ceil(total.value / loadedPerpage.value)));
const filteredItems = computed<Array<TransportQueueItem>>(() => {
  const query = filter.value.trim().toLowerCase();
  if (!query) {
    return items.value;
  }

  return items.value.filter((item) => JSON.stringify(item).toLowerCase().includes(query));
});

useHead({ title: 'Transport Queue' });

const stateColor = (state: TransportQueueItem['state']): 'neutral' | 'warning' | 'error' => {
  switch (state) {
    case 'processing':
      return 'warning';
    case 'failed':
      return 'error';
    default:
      return 'neutral';
  }
};

const stateIcon = (state: TransportQueueItem['state']): string => {
  switch (state) {
    case 'processing':
      return 'i-lucide-loader-circle';
    case 'failed':
      return 'i-lucide-circle-x';
    default:
      return 'i-lucide-clock-3';
  }
};

const shortId = (id: string): string => `${id.slice(0, 8)}...${id.slice(-4)}`;

const openItem = async (item: TransportQueueItem): Promise<void> => {
  const requestId = ++detailRequest;
  selectedSummary.value = item;
  selectedItem.value = null;
  detailError.value = '';
  detailLoading.value = true;
  detailOpen.value = true;

  try {
    const response = await request(`/system/transport/queue/${encodeURIComponent(item.id)}`);
    const json = await parse_api_response<TransportQueueDetail>(response);

    if (requestId !== detailRequest || 'transport-queue' !== route.name) {
      return;
    }

    if ('error' in json) {
      detailError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Unable to load envelope', detailError.value);
      return;
    }

    selectedItem.value = json;
  } catch (caughtError: unknown) {
    if (requestId === detailRequest && 'transport-queue' === route.name) {
      detailError.value = caughtError instanceof Error ? caughtError.message : String(caughtError);
      notification('error', 'Unable to load envelope', detailError.value);
    }
  } finally {
    if (requestId === detailRequest) {
      detailLoading.value = false;
    }
  }
};

const navigatePage = (pageNumber: number): void => {
  void router.push({
    path: '/transport/queue',
    query: {
      ...route.query,
      page: pageNumber,
      perpage: perpage.value,
      state: '__all__' === stateFilter.value ? undefined : stateFilter.value,
      filter: filter.value.trim() || undefined,
    },
  });
};

const clearFilters = (): void => {
  void router.push({
    path: '/transport/queue',
    query: { ...route.query, page: 1, state: undefined, filter: undefined },
  });
};

const loadContent = async (pageNumber: number): Promise<void> => {
  const requestId = ++listRequest;
  requestedPage.value = Number.isInteger(pageNumber) && 0 < pageNumber ? pageNumber : 1;
  requestedKey = `${requestedPage.value}:${perpage.value}:${stateFilter.value}`;
  error.value = '';
  isLoading.value = true;

  try {
    const query = new URLSearchParams({
      page: String(requestedPage.value),
      perpage: String(perpage.value),
    });
    if ('__all__' !== stateFilter.value) {
      query.set('state', stateFilter.value);
    }

    const response = await request(`/system/transport/queue?${query.toString()}`);
    const json = await parse_api_response<{
      items: Array<TransportQueueItem>;
      paging: {
        page: number;
        total: number;
        perpage: number;
        next: number | null;
        previous: number | null;
      };
      states: Array<TransportQueueItem['state']>;
    }>(response);

    if (requestId !== listRequest || 'transport-queue' !== route.name) {
      return;
    }

    if ('error' in json) {
      error.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Unable to load transport items', error.value);
      return;
    }

    items.value = json.items;
    page.value = json.paging.page;
    requestedPage.value = json.paging.page;
    total.value = json.paging.total;
    perpage.value = json.paging.perpage;
    loadedPerpage.value = json.paging.perpage;
    availableStates.value = json.states;
    hasResults.value = true;
    requestedKey = `${requestedPage.value}:${perpage.value}:${stateFilter.value}`;

    await router.replace({
      path: '/transport/queue',
      query: {
        ...route.query,
        page: page.value,
        perpage: perpage.value,
        state: '__all__' === stateFilter.value ? undefined : stateFilter.value,
        filter: filter.value.trim() || undefined,
      },
    });
  } catch (caughtError: unknown) {
    if (requestId === listRequest && 'transport-queue' === route.name) {
      error.value = caughtError instanceof Error ? caughtError.message : String(caughtError);
      notification('error', 'Unable to load transport items', error.value);
    }
  } finally {
    if (requestId === listRequest) {
      isLoading.value = false;
    }
  }
};

watch(filter, (value: string) => {
  const nextFilter = value.trim();
  if (String(route.query.filter ?? '') === nextFilter) {
    return;
  }

  void router.replace({
    path: '/transport/queue',
    query: { ...route.query, filter: nextFilter || undefined },
  });
});

watch(detailOpen, (open) => {
  if (!open) {
    ++detailRequest;
    detailLoading.value = false;
  }
});

watch(
  () => route.fullPath,
  () => {
    if ('transport-queue' !== route.name) {
      return;
    }

    const nextPage = Number(route.query.page ?? 1);
    const nextPerpage = Number(route.query.perpage ?? 25);
    const rawState = String(route.query.state ?? '__all__');
    requestedPage.value = Number.isInteger(nextPage) && 0 < nextPage ? nextPage : 1;
    perpage.value =
      Number.isInteger(nextPerpage) && 0 < nextPerpage ? Math.min(100, nextPerpage) : 25;
    stateFilter.value = ['pending', 'processing', 'failed'].includes(rawState)
      ? (rawState as StateFilter)
      : '__all__';
    filter.value = String(route.query.filter ?? '');

    if (`${requestedPage.value}:${perpage.value}:${stateFilter.value}` !== requestedKey) {
      void loadContent(requestedPage.value);
    }
  },
);

onMounted(() => void loadContent(requestedPage.value));
onBeforeUnmount(() => {
  ++listRequest;
  ++detailRequest;
});
</script>
