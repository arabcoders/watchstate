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
        <form
          v-if="toggleFilter || query"
          class="w-full sm:w-72"
          @submit.prevent="void loadContent(1)"
        >
          <UInput
            id="filter"
            v-model="query"
            type="search"
            placeholder="Search and filter"
            icon="i-lucide-filter"
            size="sm"
            class="w-full"
          />
        </form>

        <UButton
          color="neutral"
          :variant="toggleFilter ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-filter"
          @click="toggleFilter = !toggleFilter"
          label="Filter"
        />
        <UTooltip v-if="items.length > 0" text="Remove all non-pending events.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            :disabled="isLoading"
            @click="deleteAll"
            label="Delete All"
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
          <p v-if="query">
            Search for <strong>{{ query }}</strong> returned no results.
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

              <UButton
                v-if="Object.keys(item.event_data || {}).length > 0"
                color="neutral"
                variant="ghost"
                size="sm"
                square
                :icon="item._display ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                :aria-label="item._display ? 'Hide event data' : 'Show event data'"
                @click="item._display = !item._display"
              />
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
                      0 === item.status && !item.updated_at
                        ? 'i-lucide-loader-circle'
                        : 'i-lucide-calendar-days'
                    "
                    :class="
                      0 === item.status && !item.updated_at ? 'size-4 animate-spin' : 'size-4'
                    "
                  />
                  <span>Updated</span>
                </span>

                <template v-if="!item.updated_at">
                  <span class="min-w-0 text-default sm:ml-auto sm:text-right">
                    {{ 0 === item.status ? 'Pending' : 'None' }}
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

          <div
            v-if="item._display && Object.keys(item.event_data || {}).length > 0"
            class="relative overflow-hidden rounded-md border border-default bg-elevated/60"
          >
            <code class="ws-terminal ws-terminal-panel ws-terminal-panel-sm whitespace-pre-wrap">
              {{ JSON.stringify(item.event_data, null, 2) }}
            </code>

            <UTooltip text="Copy event data">
              <UButton
                color="neutral"
                variant="soft"
                size="sm"
                icon="i-lucide-copy"
                class="absolute right-6 top-3"
                @click="() => copyText(JSON.stringify(item.event_data, null, 2), false)"
              />
            </UTooltip>
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
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-rotate-ccw"
                @click="resetEvent(item, 0 === item.status ? 4 : 0)"
              >
                {{ 0 === item.status ? 'Stop' : 'Reset' }}
              </UButton>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                @click="deleteItem(item)"
              >
                Delete
              </UButton>
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
          <li>Resetting an event will return it to the queue to be dispatched again.</li>
          <li>Stopping an event will prevent it from being dispatched.</li>
          <li>
            Events with status of <UBadge color="warning" variant="soft" size="sm">Running</UBadge>
            cannot be cancelled or stopped.
          </li>
          <li>
            The <UIcon name="i-lucide-filter" class="inline size-4 align-text-bottom" /> filter
            button on top can be used for both filtering the displayed results and, on submit,
            searching the backend for the given event name.
          </li>
        </ul>
      </div>
    </UCard>

    <UModal v-model:open="quickViewOpen" :title="quickViewTitle" :ui="quickViewModalUi">
      <template #body>
        <EventView v-if="quick_view" :id="quick_view" @delete="(item) => deleteItem(item)" />
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
  copyText,
  makeEventName,
  notification,
  parse_api_response,
  request,
} from '~/utils';

type EventStatus = {
  code: number;
  name: string;
};

type EventsResponse = {
  items: Array<EventsItem>;
  statuses: Array<EventStatus>;
  paging: { page: number; perpage: number; total: number };
};

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

const total = ref<number>(0);
const page = ref<number>(toPositiveInt(getRouteQueryValue(route.query.page, '1'), 1));
const perpage = ref<number>(toPositiveInt(getRouteQueryValue(route.query.perpage, '26'), 26));
const isLoading = ref<boolean>(false);
const items = ref<Array<EventsItem>>([]);
const statuses = ref<Array<EventStatus>>([]);
const query = ref<string>(getRouteQueryValue(route.query.filter, ''));
const toggleFilter = ref<boolean>(false);
const quick_view = ref<string | null>(null);
const show_page_tips = useStorage<boolean>('show_page_tips', true);

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

const statusLookup = computed<Record<number, string>>(() =>
  statuses.value.reduce((map: Record<number, string>, item) => {
    map[item.code] = item.name;
    return map;
  }, {}),
);

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

const filteredRows = computed<Array<EventsItem>>(() => {
  if (!query.value) {
    return items.value;
  }

  const toLower = query.value.toLowerCase();

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

    const queryParams = new URLSearchParams();
    queryParams.append('page', pageNumber.toString());
    queryParams.append('perpage', requestedPerPage.toString());
    if (query.value) {
      queryParams.append('filter', query.value);
    }

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

    useHead({ title: `Events - Page #${pageNumber}` });

    if (true === updateHistory) {
      const history_query: Record<string, string | number> = {
        perpage: requestedPerPage,
        page: pageNumber,
      };

      if (query.value) {
        history_query.filter = query.value;
      }

      await router.push({ path: '/events', query: history_query });
    }

    page.value = json.paging.page;
    perpage.value = json.paging.perpage;
    total.value = json.paging.total;
    items.value = (json.items ?? []).map((item) => ({
      ...item,
      _display: item._display ?? false,
    }));
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

onMounted(async () => {
  await loadContent(page.value);
  window.addEventListener('popstate', handlePopState);
});

onUnmounted(() => window.removeEventListener('popstate', handlePopState));

const handlePopState = async (): Promise<void> => {
  const currentPage = toPositiveInt(getRouteQueryValue(route.query.page, '1'), 1);
  const currentPerPage = toPositiveInt(getRouteQueryValue(route.query.perpage, '26'), 26);

  page.value = currentPage;
  perpage.value = currentPerPage;
  query.value = getRouteQueryValue(route.query.filter, '');

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
  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: `Reset '${makeEventName(item.id)}'?`,
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

    items.value[index] = {
      ...json,
      _display: items.value[index]?._display ?? false,
    };
  } catch (e: unknown) {
    console.error(e);
    notification(
      'crit',
      'Error',
      `Events view patch Request failure. ${e instanceof Error ? e.message : String(e)}`,
    );
  }
};

const deleteAll = async (): Promise<void> => {
  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: 'Delete all non pending events?',
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request('/system/events/', { method: 'DELETE' });
    if (200 !== response.status) {
      const json = await parse_api_response<GenericResponse>(response);
      if ('error' in json) {
        const errorJson = json as GenericError;
        notification(
          'error',
          'Error',
          `Failed to delete events. ${errorJson.error.code}: ${errorJson.error.message}`,
        );
      } else {
        notification('error', 'Error', 'Failed to delete events.');
      }
      return;
    }

    quick_view.value = null;
    await loadContent(page.value);
  } catch (e: unknown) {
    console.error(e);
    notification(
      'crit',
      'Error',
      `Events view patch Request failure. ${e instanceof Error ? e.message : String(e)}`,
    );
  }
};

watch(query, (value: string) => {
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
