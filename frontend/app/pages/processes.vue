<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          aria-label="Refresh processes"
          @click="loadContent"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Processes" :aria-busy="isLoading" class="space-y-4">
      <div class="w-full space-y-1.5 sm:max-w-md">
        <label for="process-filter" class="block text-xs font-medium text-toned">
          Filter processes
        </label>
        <UInput
          id="process-filter"
          v-model="filter"
          type="search"
          placeholder="PID, user or command"
          icon="i-lucide-search"
          size="sm"
          class="w-full"
        />
      </div>

      <UAlert
        v-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Couldn't load processes"
        :description="loadError"
      />

      <UAlert
        v-if="isLoading && 0 === filteredItems.length"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading processes"
        :ui="{ icon: 'animate-spin' }"
      />

      <UEmpty
        v-else-if="hasLoaded && !loadError && 0 === filteredItems.length"
        icon="i-lucide-list"
        :title="filter.trim() ? 'No matching processes' : 'No processes'"
      />

      <div
        v-else-if="0 < filteredItems.length"
        class="divide-y divide-default border-y border-default"
      >
        <Lazy v-for="item in filteredItems" :key="item.pid" :unrender="true" :min-height="96">
          <article
            :aria-labelledby="`process-${item.pid}`"
            class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-3 py-4 xl:grid-cols-[minmax(0,1fr)_15rem_5rem]"
          >
            <div class="min-w-0 space-y-2">
              <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2
                  :id="`process-${item.pid}`"
                  class="font-mono text-sm font-semibold text-highlighted"
                >
                  PID {{ item.pid }}
                </h2>
                <dl class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-toned">
                  <div class="flex gap-1">
                    <dt class="text-muted">User</dt>
                    <dd class="wrap-anywhere">{{ item.user }}</dd>
                  </div>
                  <div class="flex gap-1">
                    <dt class="text-muted">State</dt>
                    <dd class="font-mono">{{ item.stat }}</dd>
                  </div>
                </dl>
              </div>
              <p
                class="wrap-anywhere whitespace-pre-wrap font-mono text-xs leading-relaxed text-toned"
              >
                {{ item.command }}
              </p>
            </div>

            <dl
              class="col-span-2 grid grid-cols-3 gap-4 text-sm sm:max-w-sm xl:col-span-1 xl:col-start-2 xl:row-start-1"
            >
              <div class="space-y-1">
                <dt class="text-xs text-muted">Memory</dt>
                <dd class="font-mono tabular-nums">{{ item.mem }}%</dd>
              </div>
              <div class="space-y-1">
                <dt class="text-xs text-muted">CPU</dt>
                <dd class="font-mono tabular-nums">{{ item.cpu }}%</dd>
              </div>
              <div class="space-y-1">
                <dt class="text-xs text-muted">CPU time</dt>
                <dd class="font-mono tabular-nums">{{ item.time }}</dd>
              </div>
            </dl>

            <div class="col-start-2 row-start-1 xl:col-start-3">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-x-circle"
                :disabled="isLoading || 'Z' === item.stat"
                :aria-label="`Kill process ${item.pid}`"
                @click="killProcess(item)"
              >
                Kill
              </UButton>
            </div>
          </article>
        </Lazy>
      </div>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHead, useRoute } from '#app';
import { UAlert, UButton, UEmpty, UInput } from '#components';
import type { SystemProcess } from '~/types';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';

useHead({ title: 'Processes' });

const pageShell = requireTopLevelPageShell('processes');

const route = useRoute();

const items = ref<Array<SystemProcess>>([]);
const isLoading = ref<boolean>(false);
const loadError = ref('');
const hasLoaded = ref(false);
const filter = ref<string>(String(route.query.filter ?? ''));

const normalizeProcessValue = (value: unknown): string => {
  if ('number' === typeof value) {
    return value.toString();
  }

  return 'string' === typeof value ? value.toLowerCase() : '';
};

const filterItem = (item: SystemProcess): boolean => {
  const search = filter.value.trim().toLowerCase();

  if (!search) {
    return true;
  }

  return Object.values(item).some((value) => normalizeProcessValue(value).includes(search));
};

const filteredItems = computed<Array<SystemProcess>>(() =>
  items.value.filter((item) => filterItem(item)),
);

const loadContent = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }

  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/system/processes');
    const json = await parse_api_response<{ processes: Array<SystemProcess> }>(response);

    if ('error' in json) {
      loadError.value = `${json.error.code}: ${json.error.message}`;
      notification('error', 'Processes unavailable', `${json.error.code}: ${json.error.message}`);
      return;
    }

    if (!('processes' in json)) {
      loadError.value = 'Invalid response from the server.';
      notification('error', 'Processes unavailable', 'Invalid response from the server.');
      return;
    }

    items.value = json.processes;
    hasLoaded.value = true;
  } catch (e: unknown) {
    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Processes unavailable', loadError.value);
  } finally {
    isLoading.value = false;
  }
};

const killProcess = async (item: SystemProcess): Promise<void> => {
  if (isLoading.value || !item.pid || 'Z' === item.stat) {
    return;
  }

  const { status } = await useDialog().confirmDialog({
    title: 'Kill process?',
    confirmText: 'Kill',
    message:
      'Killing this process can interrupt active work or make WatchState unstable.' +
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
      notification('error', 'Process not stopped', `${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Process stopped', `Stopped #${item.pid}.`);
    items.value = items.value.filter((entry) => entry.pid !== item.pid);
  } catch (e: unknown) {
    notification(
      'error',
      'Process not stopped',
      e instanceof Error ? e.message : 'Unknown error occurred',
    );
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());
</script>
