<template>
  <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" aria-label="History records">
    <li
      v-for="item in items"
      :key="`${user}:${item.id}`"
      class="ws-card flex min-w-0 items-start gap-3 overflow-hidden p-3"
    >
      <NuxtLink
        v-if="posterEnable"
        :to="`/history/${item.id}`"
        :aria-label="`View ${item.title}`"
        class="group block w-16 shrink-0"
      >
        <HistoryPoster
          :history-id="item.id"
          :title="item.title"
          :user="user"
          class="h-24 w-16 [&>div]:p-1"
        />
      </NuxtLink>

      <div class="min-w-0 flex-1 space-y-1.5">
        <NuxtLink
          :to="`/history/${item.id}`"
          :title="item.title || item.full_title"
          class="line-clamp-2 wrap-break-word text-sm font-semibold leading-5 text-highlighted hover:text-primary"
        >
          {{ item.title || item.full_title }}
        </NuxtLink>
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
            size="sm"
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
          <UBadge
            v-if="0 < Number(item.progress)"
            color="neutral"
            variant="soft"
            size="sm"
            :aria-label="`Playback position ${formatDuration(Number(item.progress))}`"
          >
            <span class="inline-flex items-center gap-1 tabular-nums">
              <UIcon name="i-lucide-gauge" class="size-3.5" />
              <span>{{ formatDuration(Number(item.progress)) }}</span>
            </span>
          </UBadge>
        </div>
      </div>

      <UCheckbox
        :model-value="selectedIds.includes(item.id)"
        :aria-label="`Select ${item.title}`"
        color="primary"
        class="mt-0.5 shrink-0"
        @update:model-value="emit('select', item.id, $event)"
      />
    </li>
  </ul>
</template>

<script setup lang="ts">
import { NuxtLink, UBadge, UCheckbox, UIcon } from '#components';
import HistoryPoster from '~/components/StatisticsPoster.vue';
import type { HistoryViewProps } from '~/types';
import { formatDuration } from '~/utils';

defineProps<HistoryViewProps>();
const emit = defineEmits<{ select: [id: number, value: boolean | 'indeterminate'] }>();
</script>
