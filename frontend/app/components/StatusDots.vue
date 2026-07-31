<template>
  <UBadge v-if="shouldShow" color="info" variant="soft" class="gap-1.5 px-2 py-1 font-semibold">
    <span
      class="size-2 rounded-full bg-current"
      :class="queued === 0 ? 'opacity-35' : ''"
      aria-hidden="true"
    />
    <span>{{ displayCount(queued) }}</span>
  </UBadge>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { PendingStats } from '~/types';

const props = withDefaults(defineProps<{ stats: PendingStats; hideZero?: boolean }>(), {
  hideZero: false,
});

const queued = computed(() => props.stats?.pending ?? 0);
const shouldShow = computed(() => (props.hideZero ? queued.value > 0 : true));

const displayCount = (n: number): string => (n > 99 ? '99+' : String(n));
</script>
