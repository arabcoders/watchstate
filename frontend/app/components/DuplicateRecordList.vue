<template>
  <div class="w-100 max-w-[90vw] p-4">
    <div v-if="isLoading" class="flex items-center gap-2 text-sm font-medium text-default">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin text-info" />
      <span>Loading records...</span>
    </div>

    <UAlert
      v-else-if="errorMessage"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      :description="errorMessage"
    />

    <UEmpty
      v-else-if="0 === records.length"
      size="sm"
      variant="naked"
      icon="i-lucide-info"
      title="No records found"
    />

    <div v-else class="space-y-3">
      <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
        <UIcon name="i-lucide-files" class="size-4" />
        <span>{{ 1 === records.length ? 'History record' : 'Related records' }}</span>
      </div>

      <ul class="divide-y divide-default">
        <li v-for="record in records" :key="record.id" class="space-y-2 py-3 first:pt-0 last:pb-0">
          <NuxtLink
            :to="`/history/${record.id}`"
            class="block wrap-break-word text-sm font-medium text-default hover:text-primary"
          >
            {{ record.full_title || makeName(record as unknown as JsonObject) }}
          </NuxtLink>

          <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-toned">
            <NuxtLink
              :to="`/backend/${record.via}`"
              class="inline-flex min-w-0 items-center gap-1.5 hover:text-highlighted"
            >
              <UIcon name="i-lucide-server" class="size-3.5 shrink-0" />
              <span class="wrap-break-word">{{ record.via }}</span>
            </NuxtLink>

            <div
              v-if="record.updated"
              class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap"
            >
              <UIcon name="i-lucide-clock-3" class="size-3.5" />
              <span>{{ moment.unix(record.updated).fromNow() }}</span>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import moment from 'moment';
import { NuxtLink, UAlert, UEmpty, UIcon } from '#components';
import { makeName, parse_api_response, request } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { HistoryItem, JsonObject } from '~/types';

const props = defineProps<{ ids: Array<number>; user: string }>();

const records = ref<Array<HistoryItem>>([]);
const isLoading = ref<boolean>(true);
const errorMessage = ref<string>('');
const cache = useSessionCache(`history-records:${props.user}`);
const controller = new AbortController();

onMounted(async () => {
  const unique = new Set<number>();
  for (const value of props.ids ?? []) {
    if (!Number.isFinite(value)) {
      continue;
    }
    unique.add(Number(value));
  }

  const ids = Array.from(unique);

  if (0 === ids.length) {
    isLoading.value = false;
    return;
  }

  try {
    const items = await Promise.all(
      ids.map(async (id) => {
        const cacheKey = `history_${id}`;
        const cachedItem = cache.get<HistoryItem>(cacheKey);
        if (null !== cachedItem) {
          return cachedItem;
        }

        const response = await request(`/history/${id}`, {
          headers: { 'X-User': props.user },
          signal: controller.signal,
        });
        const record = await parse_api_response<HistoryItem>(response);
        if ('error' in record) {
          throw new Error(record.error.message || `Unable to load record ${id}`);
        }
        if (!response.ok) {
          throw new Error(`Unable to load record ${id} (${response.status})`);
        }

        cache.set(cacheKey, record, 300);
        return record;
      }),
    );

    if (!controller.signal.aborted) {
      records.value = items;
    }
  } catch (error) {
    if (!controller.signal.aborted) {
      errorMessage.value = error instanceof Error ? error.message : 'Failed to load records.';
    }
  } finally {
    isLoading.value = false;
  }
});

onBeforeUnmount(() => controller.abort());
</script>
