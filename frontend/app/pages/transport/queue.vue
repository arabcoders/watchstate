<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UInput
          v-if="showFilter"
          id="transport-filter"
          v-model.lazy="filter"
          type="search"
          placeholder="Filter displayed results"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <USelect
          v-model="stateFilter"
          :items="stateItems"
          value-key="value"
          label-key="label"
          color="neutral"
          variant="outline"
          size="sm"
          class="w-full sm:w-40"
          :disabled="isLoading"
          @update:model-value="() => void loadContent(1, false)"
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
          @update:model-value="() => void loadContent(1, false)"
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
      </template>
    </PageHeader>

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
    </div>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading transport items."
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
          <p>No items found.</p>
          <p v-if="'__all__' !== stateFilter">
            State: <code>{{ stateFilter }}</code>
          </p>
          <p v-if="filter">
            Displayed-results filter: <code>{{ filter }}</code>
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
      <UCard v-for="item in filteredItems" :key="item.id" class="h-full shadow-sm">
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <UTooltip :text="item.event">
                <button
                  type="button"
                  class="block max-w-full truncate text-left text-base font-semibold text-highlighted hover:text-primary"
                  @click="() => void openItem(item)"
                >
                  {{ item.event }}
                </button>
              </UTooltip>
            </div>
            <UBadge :color="stateColor(item.state)" variant="soft">
              <span class="inline-flex items-center gap-1">
                <UIcon :name="stateIcon(item.state)" class="size-3.5" />
                <span class="capitalize">{{ item.state }}</span>
              </span>
            </UBadge>
          </div>
        </template>

        <div class="grid gap-2.5 sm:grid-cols-2">
          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-fingerprint" class="size-4" />
              <span>Envelope ID</span>
            </div>
            <UTooltip :text="item.id">
              <p class="mt-2 truncate font-mono text-xs text-default">{{ shortId(item.id) }}</p>
            </UTooltip>
          </div>
          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-calendar" class="size-4" />
              <span>Created</span>
            </div>
            <UTooltip :text="formatDate(item.created_at)">
              <p class="mt-2 text-sm text-default">{{ fromNow(item.created_at) }}</p>
            </UTooltip>
          </div>
        </div>
      </UCard>
    </div>

    <div v-if="total && last_page > 1" class="flex flex-wrap items-center justify-between gap-3">
      <Pager :page="page" :last_page="last_page" :is-loading="isLoading" @navigate="navigatePage" />
      <div class="text-xs text-toned">Page {{ page }} of {{ last_page }}</div>
    </div>

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
          description="Loading transport data and options."
          :ui="{ icon: 'animate-spin' }"
        />
        <UAlert
          v-else-if="detailError"
          color="error"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Unable to load envelope"
        >
          {{ detailError }}
        </UAlert>
        <TransportQueueView v-else-if="selectedItem" :item="selectedItem" />
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import TransportQueueView from '~/components/TransportQueueView.vue';
import type { TransportQueueDetail, TransportQueueItem } from '~/types';
import {
  awaitElement,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

type StateFilter = '__all__' | 'pending' | 'processing' | 'failed';

const pageShell = requireTopLevelPageShell('transport-queue');
const route = useRoute();
const router = useRouter();
const routeState = String(route.query.state ?? '__all__');
const routePage = Number(route.query.page ?? 1);
const routePerpage = Number(route.query.perpage ?? 25);
const items = ref<Array<TransportQueueItem>>([]);
const isLoading = ref<boolean>(false);
const error = ref<string>('');
const filter = ref<string>(String(route.query.filter ?? ''));
const showFilter = ref<boolean>('' !== filter.value);
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
let detailRequest = 0;
const detailOpen = ref<boolean>(false);
const page = ref<number>(Number.isInteger(routePage) && routePage > 0 ? routePage : 1);
const perpage = ref<number>(Number.isInteger(routePerpage) && routePerpage > 0 ? routePerpage : 25);
const total = ref<number>(0);

const stateItems = computed<Array<{ label: string; value: StateFilter }>>(() => [
  { label: 'All states', value: '__all__' },
  ...availableStates.value.map((state) => ({
    label: state.charAt(0).toUpperCase() + state.slice(1),
    value: state,
  })),
]);

const perPageItems = [25, 50, 100].map((value) => ({
  label: `${value} per page`,
  value,
}));
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));
const filteredItems = computed<Array<TransportQueueItem>>(() => {
  if (!filter.value) {
    return items.value;
  }

  const query = filter.value.toLowerCase();
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
const formatDate = (value: string): string => moment(value).format(TOOLTIP_DATE_FORMAT.value);
const fromNow = (value: string): string => moment(value).fromNow();

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value) {
    filter.value = '';
    return;
  }

  awaitElement('#transport-filter', (_, element) => (element as HTMLInputElement).focus());
};

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

    if (requestId !== detailRequest) {
      return;
    }

    if ('error' in json) {
      detailError.value = `${json.error.code}: ${json.error.message}`;
      return;
    }

    selectedItem.value = json;
  } catch (caughtError: unknown) {
    if (requestId === detailRequest) {
      detailError.value = caughtError instanceof Error ? caughtError.message : String(caughtError);
    }
  } finally {
    if (requestId === detailRequest) {
      detailLoading.value = false;
    }
  }
};

const navigatePage = (pageNumber: number): void => {
  void loadContent(pageNumber);
};

const loadContent = async (pageNumber: number, fromPopState: boolean = false): Promise<void> => {
  pageNumber = parseInt(pageNumber.toString());
  if (Number.isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1;
  }

  error.value = '';
  isLoading.value = true;
  items.value = [];
  try {
    const query = new URLSearchParams({
      page: String(pageNumber),
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

    if ('error' in json) {
      error.value = json.error.message;
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    if ('transport-queue' !== route.name) {
      return;
    }

    items.value = json.items ?? [];
    page.value = json.paging.page;
    total.value = json.paging.total;
    perpage.value = json.paging.perpage;
    availableStates.value = json.states ?? [];
    if (!fromPopState) {
      await router.push({
        path: '/transport/queue',
        query: {
          page: page.value,
          perpage: perpage.value,
          state: '__all__' === stateFilter.value ? undefined : stateFilter.value,
          filter: '' === filter.value ? undefined : filter.value,
        },
      });
    }
  } catch (caughtError: unknown) {
    const message = caughtError instanceof Error ? caughtError.message : String(caughtError);
    error.value = message;
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

watch(filter, (value: string) => {
  const nextFilter = value.trim();
  const currentFilter = String(route.query.filter ?? '');

  if (currentFilter === nextFilter) {
    return;
  }

  void router.push({
    path: '/transport/queue',
    query: {
      ...route.query,
      filter: nextFilter || undefined,
    },
  });
});

watch(
  () => route.fullPath,
  async () => {
    if ('transport-queue' !== route.name) {
      return;
    }

    const nextPage = parseInt(route.query.page as string) || 1;
    const nextPerPage = parseInt(route.query.perpage as string) || 25;
    const rawState = String(route.query.state ?? '__all__');
    const nextState: StateFilter = ['pending', 'processing', 'failed'].includes(rawState)
      ? (rawState as StateFilter)
      : '__all__';
    const nextFilter = String(route.query.filter ?? '');
    const shouldReload =
      nextPage !== page.value || nextPerPage !== perpage.value || nextState !== stateFilter.value;

    page.value = nextPage;
    perpage.value = nextPerPage;
    stateFilter.value = nextState;
    filter.value = nextFilter;
    showFilter.value = Boolean(nextFilter);

    if (!shouldReload) {
      return;
    }

    await loadContent(nextPage, true);
  },
);

onMounted(() => void loadContent(page.value));
</script>
