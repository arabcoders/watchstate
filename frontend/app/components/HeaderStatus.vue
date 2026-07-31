<template>
  <div class="flex shrink-0 items-center gap-0.5 sm:gap-2">
    <UButton
      to="/events"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-calendar-days"
      aria-label="Events"
    >
      <span class="hidden xl:inline">Events</span>
      <StatusDots v-if="!loading" :stats="stats.events" />
      <UIcon v-else name="i-lucide-loader-circle" class="size-4 animate-spin" />
    </UButton>

    <UButton
      to="/transport/queue"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-inbox"
      aria-label="Transport Queue"
    >
      <span class="hidden xl:inline">Transport</span>
      <StatusDots v-if="!loading" :stats="stats.transport" />
      <UIcon v-else name="i-lucide-loader-circle" class="size-4 animate-spin" />
    </UButton>
  </div>
</template>

<script setup lang="ts">
import StatusDots from '~/components/StatusDots.vue';
import type { SystemStats } from '~/types';

defineProps<{ stats: SystemStats; loading: boolean }>();
</script>
