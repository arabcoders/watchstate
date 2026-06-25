<template>
  <div class="w-[320px] max-w-[90vw] p-3">
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

    <UAlert
      v-else-if="0 === records.length"
      color="info"
      variant="soft"
      icon="i-lucide-info"
      description="No other records reference this file."
    />

    <div v-else class="space-y-3">
      <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
        <UIcon name="i-lucide-copy" class="size-4" />
        <span>Duplicate file references</span>
      </div>

      <div class="space-y-2">
        <div
          v-for="(record, index) in records"
          :key="record.id"
          class="space-y-2 rounded-md border border-default bg-elevated/40 p-3"
        >
          <div class="flex items-start justify-between gap-3">
            <NuxtLink
              :to="`/backend/${record.via}`"
              class="inline-flex items-center gap-1 rounded-md border border-info/30 bg-info/10 px-2.5 py-1 text-xs font-medium text-info"
            >
              <UIcon name="i-lucide-server" class="size-3.5" />
              <span>{{ record.via }}</span>
            </NuxtLink>

            <div v-if="record.updated" class="inline-flex items-center gap-1 text-xs text-toned">
              <UIcon name="i-lucide-clock-3" class="size-3.5" />
              <span>{{ moment.unix(record.updated).fromNow() }}</span>
            </div>
          </div>

          <NuxtLink
            :to="`/history/${record.id}`"
            class="block text-sm font-medium text-default hover:text-primary"
          >
            {{ record.full_title || makeName(record as unknown as JsonObject) }}
          </NuxtLink>

          <USeparator v-if="index < records.length - 1" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import moment from 'moment';
import { NuxtLink } from '#components';
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
