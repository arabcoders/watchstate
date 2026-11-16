<template>
  <ul
    class="grid grid-cols-2 gap-x-4 gap-y-6 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6"
    aria-label="History records"
  >
    <li v-for="item in items" :key="`${user}:${item.id}`" class="group min-w-0 space-y-3">
      <NuxtLink
        v-if="posterEnable"
        :to="`/history/${item.id}`"
        :aria-label="`View ${item.title}`"
        class="block rounded-lg outline-offset-4"
      >
        <HistoryPoster
          :history-id="item.id"
          :title="item.title"
          :user="user"
          class="w-full rounded-lg transition-shadow group-hover:ring-primary/50"
        />
      </NuxtLink>

      <div class="min-w-0 space-y-2">
        <div class="flex items-start gap-2">
          <NuxtLink
            :to="`/history/${item.id}`"
            :title="item.title || item.full_title"
            class="line-clamp-2 min-w-0 flex-1 wrap-break-word text-sm font-semibold leading-5 text-highlighted hover:text-primary"
          >
            {{ item.title || item.full_title }}
          </NuxtLink>
          <UCheckbox
            :model-value="selectedIds.includes(item.id)"
            :disabled="selectionDisabled"
            :aria-label="`Select ${item.full_title || item.title}`"
            color="primary"
            class="mt-0.5 shrink-0"
            @update:model-value="emit('select', item.id, $event)"
          />
        </div>
        <p
          v-if="item.content_title?.trim() && item.title.trim() !== item.content_title.trim()"
          :title="item.content_title"
          class="line-clamp-1 wrap-break-word text-xs text-toned"
        >
          {{ item.content_title }}
        </p>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-toned">
          <UIcon
            :name="'movie' === item.type ? 'i-lucide-film' : 'i-lucide-tv'"
            class="size-3.5 shrink-0"
          />
          <span v-if="'movie' === item.type">Movie</span>
          <template v-else>
            <span v-if="null != item.season && null != item.episode" class="font-mono">
              {{ item.season }}x{{ String(item.episode).padStart(2, '0') }}
            </span>
            <span v-else>{{ 'episode' === item.type ? 'Episode' : 'Show' }}</span>
          </template>
          <span v-if="item.year">{{ item.year }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
          <UBadge
            :color="item.watched ? 'success' : 0 < Number(item.progress) ? 'info' : 'neutral'"
            variant="soft"
            size="xs"
          >
            <span class="inline-flex items-center gap-1">
              <UIcon
                :name="
                  item.watched
                    ? 'i-lucide-circle-check'
                    : 0 < Number(item.progress)
                      ? 'i-lucide-circle-play'
                      : 'i-lucide-circle'
                "
                class="size-3.5"
              />
              <span>{{
                item.watched ? 'Watched' : 0 < Number(item.progress) ? 'In progress' : 'Unwatched'
              }}</span>
            </span>
          </UBadge>
          <span
            v-if="0 < Number(item.progress)"
            class="text-xs tabular-nums text-toned"
            :aria-label="`Playback position ${formatDuration(Number(item.progress))}`"
          >
            <span class="inline-flex items-center gap-1 tabular-nums">
              <span>{{ formatDuration(Number(item.progress)) }}</span>
            </span>
          </span>
        </div>
      </div>
    </li>
  </ul>
</template>

<script setup lang="ts">
import { NuxtLink, UBadge, UCheckbox, UIcon } from '#components';
import HistoryPoster from '~/components/StatisticsPoster.vue';
import type { HistoryViewProps } from '~/types';
import { formatDuration } from '~/utils';

withDefaults(defineProps<HistoryViewProps & { selectionDisabled?: boolean }>(), {
  selectionDisabled: false,
});
const emit = defineEmits<{ select: [id: number, value: boolean | 'indeterminate'] }>();
</script>
