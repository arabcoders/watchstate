<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UInput
          v-if="showFilter || filter"
          id="filter"
          v-model.lazy="filter"
          type="search"
          placeholder="Filter displayed results."
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

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="loadContent"
        >
          <span class="hidden sm:inline">Reload</span>
        </UButton>
      </template>
    </PageHeader>

    <UAlert
      v-if="items.length < 1 || filteredItems.length < 1"
      :color="isLoading ? 'info' : 'warning'"
      variant="soft"
      :icon="isLoading ? 'i-lucide-loader-circle' : 'i-lucide-triangle-alert'"
      :title="isLoading ? 'Loading' : 'Warning'"
      :description="
        isLoading
          ? 'Loading data. Please wait...'
          : `No items found${filter ? ` for query: ${filter}` : '.'}`
      "
      :ui="isLoading ? { icon: 'animate-spin' } : undefined"
    />

    <UCard v-else :ui="tableCardUi" class="shadow-sm">
      <UTable
        :data="filteredItems"
        :columns="columns"
        :loading="isLoading"
        sticky="header"
        :ui="tableUi"
      >
        <template #action-cell="{ row }">
          <div class="text-center">
            <UTooltip text="Stop process">
              <UButton
                :color="'Z' === row.original.stat ? 'neutral' : 'error'"
                :variant="'Z' === row.original.stat ? 'outline' : 'soft'"
                size="xs"
                :icon="'Z' === row.original.stat ? 'i-lucide-x' : 'i-lucide-x-circle'"
                :disabled="'Z' === row.original.stat"
                @click="killProcess(row.original)"
              />
            </UTooltip>
          </div>
        </template>
      </UTable>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHead, useRoute } from '#app';
import type { TableColumn } from '@nuxt/ui';
import { UButton, UTooltip } from '#components';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { awaitElement, notification, parse_api_response, request } from '~/utils';

useHead({ title: 'Processes' });

const pageShell = requireTopLevelPageShell('processes');

interface ProcessItem {
  user: string;
  pid: number;
  cpu: string;
  mem: string;
  vsz: string;
  rss: string;
  tty: string;
  stat: string;
  start: string;
  time: string;
  command: string;
}

const route = useRoute();

const items = ref<Array<ProcessItem>>([]);
const isLoading = ref<boolean>(false);
const filter = ref<string>(String(route.query.filter ?? ''));
const showFilter = ref<boolean>(!!filter.value);

const tableCardUi = {
  body: 'p-0',
};

const tableUi = {
  root: 'max-h-[70vh] overflow-auto',
  th: 'px-3 py-2 text-sm text-left font-medium text-toned',
  td: 'px-3 py-2 text-sm text-default align-top',
};

const normalizeProcessValue = (value: unknown): string => {
  if (typeof value === 'number') {
    return value.toString();
  }

  return typeof value === 'string' ? value.toLowerCase() : '';
};

const filterItem = (item: ProcessItem): boolean => {
  const search = filter.value.trim().toLowerCase();

  if (!search) {
    return true;
  }

  return Object.values(item).some((value) => normalizeProcessValue(value).includes(search));
};

const filteredItems = computed<Array<ProcessItem>>(() =>
  items.value.filter((item) => filterItem(item)),
);

const columns: Array<TableColumn<ProcessItem>> = [
  {
    id: 'action',
    header: 'Action',
    meta: {
      class: {
        th: 'w-20 text-center',
        td: 'w-20 text-center',
      },
    },
  },
  {
    accessorKey: 'pid',
    header: 'PID',
    meta: {
      class: {
        td: 'font-mono text-xs text-default',
      },
    },
  },
  {
    accessorKey: 'mem',
    header: 'Memory',
  },
  {
    accessorKey: 'cpu',
    header: 'CPU',
  },
  {
    accessorKey: 'time',
    header: 'Time',
  },
  {
    accessorKey: 'command',
    header: 'Command',
    meta: {
      class: {
        td: 'font-mono text-xs text-default whitespace-normal break-all',
      },
    },
  },
];

const loadContent = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }

  isLoading.value = true;

  try {
    const response = await request('/system/processes');
    const json = await parse_api_response<{ processes: Array<ProcessItem> }>(response);

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    if (!('processes' in json)) {
      notification('error', 'Error', 'Invalid response from the server.');
      return;
    }

    items.value = json.processes;
  } finally {
    isLoading.value = false;
  }
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;

  if (!showFilter.value) {
    filter.value = '';
    return;
  }

  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const killProcess = async (item: ProcessItem): Promise<void> => {
  if (!item.pid) {
    return;
  }

  const { status } = await useDialog().confirmDialog({
    title: 'Kill process',
    message:
      'Killing a process without knowing what it does can cause system instability. Are you sure you want to proceed?' +
      `\n\nPID: ${item.pid}: ${item.user}` +
      `\nProcess: ${item.command}`,
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  isLoading.value = true;

  try {
    const response = await request(`/system/processes/${item.pid}`, { method: 'DELETE' });
    const json = await parse_api_response<Record<string, never>>(response);

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Success', `Successfully killed #${item.pid}.`);
    items.value = items.value.filter((entry) => entry.pid !== item.pid);
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());
</script>
