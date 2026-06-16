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
          v-if="toggleFilter || query"
          id="filter"
          v-model.lazy="query"
          type="search"
          placeholder="Filter displayed content"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <UTooltip text="Filter files.">
          <UButton
            color="neutral"
            :variant="toggleFilter ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-filter"
            @click="toggleFilter = !toggleFilter"
          >
            <span class="hidden sm:inline">Filter</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Reload logs">
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
        </UTooltip>
      </div>
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
      v-else-if="filterItems.length < 1"
      :title="query ? 'No results' : 'No logs found'"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p v-if="query">
            No results found for <strong>{{ query }}</strong
            >.
          </p>
          <p v-else>No logs found.</p>
        </div>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <UCard
        v-for="item in filterItems"
        :key="item.filename"
        class="h-full border border-default/70 shadow-sm"
        :ui="logCardUi"
      >
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <UTooltip :text="String(item.filename ?? item.date)">
                <NuxtLink
                  :to="`/logs/${item.filename}`"
                  class="block truncate text-base font-semibold text-highlighted hover:text-primary"
                >
                  {{ item.filename ?? item.date }}
                </NuxtLink>
              </UTooltip>
            </div>

            <UBadge color="neutral" variant="soft">
              <span class="inline-flex items-center gap-1">
                <UIcon :name="getLogTypeIcon(item.type)" class="size-3.5" />
                <span class="capitalize">{{ item.type }}</span>
              </span>
            </UBadge>
          </div>
        </template>

        <template #footer>
          <div class="grid grid-cols-2 gap-2.5">
            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
              <UTooltip :text="`Last Update: ${moment(item.modified).format(TOOLTIP_DATE_FORMAT)}`">
                <span class="cursor-help">{{ moment(item.modified).fromNow() }}</span>
              </UTooltip>
            </div>

            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-hard-drive" class="size-4 shrink-0 text-toned" />
              <span>{{ humanFileSize(item.size) }}</span>
            </div>
          </div>
        </template>
      </UCard>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHead, useRoute } from '#app';
import moment from 'moment';
import type { LogItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  humanFileSize,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';

useHead({ title: 'Logs' });

const pageShell = requireTopLevelPageShell('logs');

const route = useRoute();
const query = ref<string>('');
const logs = ref<Array<LogItem>>([]);
const isLoading = ref<boolean>(false);
const toggleFilter = ref<boolean>(false);

const logCardUi = {
  header: 'p-4',
  footer: 'px-4 pb-4 pt-0',
};

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

const filterItems = computed<Array<LogItem>>(() => {
  if (!query.value) {
    return logs.value;
  }

  return logs.value.filter((item) =>
    item.filename.toLowerCase().includes(query.value.toLowerCase()),
  );
});

const getLogTypeIcon = (type: string): string => {
  switch (type) {
    case 'access':
      return 'i-lucide-key-round';
    case 'task':
      return 'i-lucide-list-checks';
    case 'app':
      return 'i-lucide-bug';
    case 'request':
      return 'i-lucide-globe';
    default:
      return 'i-lucide-file-text';
  }
};

const loadContent = async (): Promise<void> => {
  logs.value = [];
  isLoading.value = true;

  try {
    const response = await request('/logs');
    const data = await parse_api_response<Array<LogItem>>(response);

    if ('logs' !== route.name) {
      return;
    }

    if ('error' in data) {
      notification('error', 'Error', data.error.message);
      return;
    }

    data.sort((a, b) => new Date(b.modified).getTime() - new Date(a.modified).getTime());
    logs.value = data;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());
</script>
