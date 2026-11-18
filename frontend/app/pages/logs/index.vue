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
          @click="loadContent"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Log file filters" class="space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <div class="w-full space-y-1.5 sm:w-44">
          <label for="log-type" class="text-xs font-medium text-toned">Type</label>
          <USelect
            id="log-type"
            v-model="selectedType"
            :items="typeItems"
            icon="i-lucide-filter"
            class="w-full"
          />
        </div>
        <div class="min-w-0 flex-1 basis-full space-y-1.5 sm:basis-64">
          <label for="log-filter" class="text-xs font-medium text-toned">Find files</label>
          <UInput
            id="log-filter"
            v-model="query"
            type="search"
            placeholder="Filename"
            icon="i-lucide-search"
            class="w-full"
          />
        </div>
      </div>

      <div
        v-if="query.trim() || '__all__' !== selectedType"
        class="flex flex-wrap items-center gap-2"
        aria-label="Active filters"
      >
        <span class="text-xs text-muted">Applied</span>
        <UButton
          v-if="'__all__' !== selectedType"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          aria-label="Remove type filter"
          @click="selectedType = '__all__'"
        >
          Type: {{ typeLabels[selectedType] ?? ucFirst(selectedType) }}
        </UButton>
        <UButton
          v-if="query.trim()"
          color="neutral"
          variant="soft"
          size="xs"
          trailing-icon="i-lucide-x"
          aria-label="Remove filename filter"
          class="max-w-full"
          @click="query = ''"
        >
          <span class="truncate">Filename: {{ query.trim() }}</span>
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-x"
          @click="clearFilters"
        >
          Clear all
        </UButton>
      </div>
    </section>

    <section aria-label="Log files" :aria-busy="isLoading" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3" aria-live="polite">
        <p v-if="hasResults" class="text-sm text-toned">
          <strong class="font-medium text-highlighted">{{ filteredLogs.length }}</strong>
          {{ filteredLogs.length === logs.length ? 'files' : `of ${logs.length} files` }}
        </p>
        <p v-if="isLoading && hasResults" role="status" class="text-xs text-muted">
          Refreshing log files…
        </p>
      </div>

      <UAlert
        v-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="hasResults ? 'Log files could not be refreshed' : 'Log files could not be loaded'"
      >
        <template #description>
          <p class="wrap-break-word">{{ loadError }}</p>
          <p v-if="hasResults" class="mt-2">These results may be out of date.</p>
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            @click="loadContent"
          >
            Retry
          </UButton>
        </template>
      </UAlert>

      <div
        v-if="isLoading && !hasResults"
        role="status"
        aria-label="Loading log files"
        class="ws-list-card"
      >
        <div v-for="index in 4" :key="index" class="flex items-center gap-4 px-4 py-4">
          <USkeleton class="size-9 shrink-0" />
          <USkeleton class="h-4 w-52 max-w-full" />
        </div>
      </div>

      <UEmpty
        v-if="!isLoading && !loadError && 0 === filteredLogs.length"
        :icon="
          query.trim() || '__all__' !== selectedType ? 'i-lucide-search' : 'i-lucide-scroll-text'
        "
        :title="query.trim() || '__all__' !== selectedType ? 'No matching files' : 'No log files'"
        :description="
          query.trim() || '__all__' !== selectedType
            ? 'Try another filename or log type.'
            : 'Check logging settings in Environment if expected files are missing.'
        "
      >
        <template v-if="query.trim() || '__all__' !== selectedType" #actions>
          <UButton color="neutral" variant="outline" icon="i-lucide-x" @click="clearFilters">
            Clear all
          </UButton>
        </template>
      </UEmpty>

      <ul v-if="0 < filteredLogs.length" class="ws-list-card overflow-hidden">
        <li
          v-for="item in filteredLogs"
          :key="item.filename"
          class="grid min-w-0 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center lg:grid-cols-[minmax(0,1fr)_7rem_7rem_11rem]"
        >
          <div class="flex min-w-0 items-center gap-3">
            <span class="ws-detail-icon">
              <UIcon :name="getLogTypeIcon(item.type)" class="size-4" />
            </span>
            <NuxtLink
              :to="`/logs/${encodeURIComponent(item.filename)}`"
              class="min-w-0 wrap-anywhere text-sm font-semibold text-highlighted hover:text-primary focus-visible:outline-primary"
            >
              {{ item.filename }}
            </NuxtLink>
          </div>
          <div>
            <UBadge color="neutral" variant="soft">
              {{ typeLabels[item.type] ?? ucFirst(item.type) }}
            </UBadge>
          </div>
          <p class="text-xs tabular-nums text-toned">
            <span class="sr-only">Size </span>{{ humanFileSize(item.size) }}
          </p>
          <div class="text-xs text-toned">
            <span class="text-muted">Updated </span>
            <UTooltip :text="moment(item.modified).format(TOOLTIP_DATE_FORMAT)">
              <time :datetime="moment(item.modified).toISOString()" class="cursor-help">
                {{ moment(item.modified).fromNow() }}
              </time>
            </UTooltip>
          </div>
        </li>
      </ul>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useHead } from '#app';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UIcon,
  UInput,
  USelect,
  USkeleton,
  UTooltip,
} from '#components';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import type { LogItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  humanFileSize,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';

useHead({ title: 'Logs' });

const pageShell = requireTopLevelPageShell('logs');
const query = ref('');
const selectedType = ref('__all__');
const logs = ref<Array<LogItem>>([]);
const isLoading = ref(true);
const hasResults = ref(false);
const loadError = ref('');
let loadVersion = 0;

const typeLabels: Record<string, string> = {
  app: 'Application',
  task: 'Task',
  access: 'Access',
  request: 'Request',
};

const typeItems = computed(() => [
  { label: 'All types', value: '__all__' },
  ...Array.from(
    new Set([
      ...logs.value.map((item) => item.type),
      ...('__all__' !== selectedType.value ? [selectedType.value] : []),
    ]),
  )
    .sort()
    .map((type) => ({
      label: typeLabels[type] ?? ucFirst(type),
      value: type,
    })),
]);

const filteredLogs = computed<Array<LogItem>>(() => {
  const search = query.value.trim().toLowerCase();
  return logs.value.filter(
    (item) =>
      ('__all__' === selectedType.value || selectedType.value === item.type) &&
      item.filename.toLowerCase().includes(search),
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

const clearFilters = (): void => {
  query.value = '';
  selectedType.value = '__all__';
};

const loadContent = async (): Promise<void> => {
  const version = ++loadVersion;
  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/logs');
    const data = await parse_api_response<Array<LogItem>>(response);
    if (version !== loadVersion) {
      return;
    }
    if ('error' in data) {
      throw new Error(data.error.message);
    }
    if (!response.ok) {
      throw new Error(`${response.status}: ${response.statusText}`);
    }

    logs.value = data.sort(
      (a, b) => new Date(b.modified).getTime() - new Date(a.modified).getTime(),
    );
    hasResults.value = true;
  } catch (error) {
    if (version === loadVersion) {
      loadError.value =
        error instanceof Error ? error.message : 'Try refreshing the log files again.';
      notification('error', 'Log files could not be loaded', loadError.value);
    }
  } finally {
    if (version === loadVersion) {
      isLoading.value = false;
    }
  }
};

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  ++loadVersion;
});
</script>
