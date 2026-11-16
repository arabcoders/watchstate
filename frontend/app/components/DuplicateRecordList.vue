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
      title="No other records reference this file"
    />

    <div v-else class="space-y-3">
      <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
        <UIcon name="i-lucide-copy" class="size-4" />
        <span>Duplicate file references</span>
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
import { onMounted, ref } from 'vue';
import moment from 'moment';
import { NuxtLink, UAlert, UEmpty, UIcon } from '#components';
import { makeName, parse_api_response, request } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { HistoryItem, JsonObject } from '~/types';

const props = defineProps<{ ids: Array<number> }>();

const records = ref<Array<HistoryItem>>([]);
const isLoading = ref<boolean>(true);
const errorMessage = ref<string>('');
const cache = useSessionCache();

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
    const items: Array<HistoryItem> = [];
    const missingIds: Array<number> = [];

    for (const id of ids) {
      const cacheKey = `history_${id}`;
      const cachedItem = cache.get<HistoryItem>(cacheKey) ?? null;
      if (null !== cachedItem) {
        items.push(cachedItem);
        continue;
      }
      missingIds.push(id);
    }

    await Promise.all(
      missingIds.map(async (id) => {
        const response = await request(`/history/${id}`);
        const record = await parse_api_response<HistoryItem>(response);
        if ('error' in record) {
          throw new Error(record.error.message || `Unable to load record ${id}`);
        }
        cache?.set(`history_${id}`, record, 300);
        items.push(record);
      }),
    );

    records.value = items;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Failed to load records.';
  } finally {
    isLoading.value = false;
  }
});
</script>
