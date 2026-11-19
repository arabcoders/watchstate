<template>
  <ul class="flex snap-x snap-proximity gap-4 overflow-x-auto pb-3" tabindex="0">
    <li
      v-for="item in items"
      :key="`${user}:${item.history_id}`"
      class="group w-44 shrink-0 snap-start space-y-2 sm:w-48"
    >
      <NuxtLink
        :to="`/history/${item.history_id}`"
        class="block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
        :aria-label="
          'episode' === item.type && null !== item.season && null !== item.episode
            ? `View ${item.title}, season ${item.season}, episode ${item.episode}`
            : `View ${item.title}`
        "
      >
        <div v-if="posterEnable" class="relative overflow-hidden rounded-lg">
          <StatisticsPoster
            :history-id="item.history_id"
            :title="item.title"
            :user="user"
            :class="{ 'mask-it': maskable }"
          />
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
          class="mt-3 line-clamp-2 min-h-10 text-sm font-semibold leading-5 text-highlighted transition-colors group-hover:text-primary"
          :class="{ 'mask-it': maskable }"
          :title="item.title"
        >
          {{ item.title }}
        </p>
      </NuxtLink>
      <div class="flex items-center gap-2 text-xs text-toned">
        <span v-if="'movie' === item.type">Movie</span>
        <span v-else-if="null !== item.season && null !== item.episode" class="font-mono">
          S{{ String(item.season).padStart(2, '0') }} · E{{ String(item.episode).padStart(2, '0') }}
        </span>
        <span v-else>Episode</span>
        <span v-if="item.year">{{ item.year }}</span>
      </div>
      <p
        v-if="showState && undefined !== item.watched"
        class="flex items-center gap-1.5 text-xs text-default"
      >
        <UIcon
          :name="item.watched ? 'i-lucide-circle-check' : 'i-lucide-circle'"
          :class="['size-3.5', item.watched ? 'text-success' : 'text-dimmed']"
        />
        {{ item.watched ? 'Watched' : 'Unwatched' }}
      </p>
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
        <p class="truncate text-xs text-toned">
          {{ timestampLabel }} {{ moment.unix(item.timestamp).fromNow() }}
        </p>
      </UTooltip>
      <slot name="details" :item="item">
        <p
          v-if="attribution[item.history_id]"
          class="line-clamp-2 text-[11px] leading-4 text-dimmed"
          :class="{ 'mask-it': maskable }"
          :title="`Via ${attribution[item.history_id]}`"
        >
          Via {{ attribution[item.history_id] }}
        </p>
      </slot>
    </li>
  </ul>
</template>

<script setup lang="ts">
import moment from 'moment';
import { NuxtLink, UBadge, UIcon, UTooltip } from '#components';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import type { RecentHistoryCardsProps } from '~/types';
import { formatDuration } from '~/utils';

withDefaults(defineProps<RecentHistoryCardsProps>(), {
  timestampLabel: '',
  showState: false,
  attribution: () => ({}),
  maskable: false,
});
</script>
