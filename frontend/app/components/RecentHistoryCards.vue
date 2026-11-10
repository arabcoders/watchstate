<template>
  <ul class="flex gap-3 overflow-x-auto pb-3" tabindex="0">
    <li
      v-for="item in items"
      :key="`${user}:${item.history_id}`"
      class="group ws-card w-36 shrink-0 space-y-2 p-2 sm:w-40"
      :class="item.watched ? 'ring-1 ring-success/20' : ''"
    >
      <NuxtLink
        :to="`/history/${item.history_id}`"
        class="block"
        :aria-label="
          'episode' === item.type && null !== item.season && null !== item.episode
            ? `View ${item.title}, season ${item.season}, episode ${item.episode}`
            : `View ${item.title}`
        "
      >
        <div v-if="posterEnable" class="relative">
          <StatisticsPoster :history-id="item.history_id" :title="item.title" :user="user" />
          <UBadge
            v-if="0 < (item.progress ?? 0)"
            color="neutral"
            variant="solid"
            size="sm"
            class="absolute bottom-2 right-2 shadow-sm"
            :aria-label="`Playback position ${formatDuration(item.progress ?? 0)}`"
          >
            <span class="inline-flex items-center gap-1 tabular-nums">
              <UIcon name="i-lucide-gauge" class="size-3.5" />
              <span>{{ formatDuration(item.progress ?? 0) }}</span>
            </span>
          </UBadge>
        </div>
        <p
          class="mt-2 line-clamp-2 min-h-10 text-sm font-medium leading-5 text-highlighted hover:text-primary"
          :title="item.title"
        >
          {{ item.title }}
        </p>
      </NuxtLink>
      <div class="flex items-center justify-between gap-2 text-xs text-toned">
        <span v-if="'movie' === item.type">Movie</span>
        <span v-else-if="null !== item.season && null !== item.episode" class="font-mono">
          {{ item.season }}x{{ String(item.episode).padStart(2, '0') }}
        </span>
        <span v-else>Episode</span>
        <span v-if="item.year">{{ item.year }}</span>
      </div>
      <UBadge
        v-if="!posterEnable && 0 < (item.progress ?? 0)"
        color="neutral"
        variant="soft"
        size="sm"
        :aria-label="`Playback position ${formatDuration(item.progress ?? 0)}`"
      >
        <span class="inline-flex items-center gap-1 tabular-nums">
          <UIcon name="i-lucide-gauge" class="size-3.5" />
          <span>{{ formatDuration(item.progress ?? 0) }}</span>
        </span>
      </UBadge>
      <UTooltip v-if="null !== item.timestamp" :text="item.timestampTooltip">
        <p class="truncate pb-1 text-xs text-dimmed">
          {{ moment.unix(item.timestamp).fromNow() }}
        </p>
      </UTooltip>
    </li>
  </ul>
</template>

<script setup lang="ts">
import moment from 'moment';
import { NuxtLink, UBadge, UIcon, UTooltip } from '#components';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import type { RecentHistoryCardsProps } from '~/types';
import { formatDuration } from '~/utils';

defineProps<RecentHistoryCardsProps>();
</script>
