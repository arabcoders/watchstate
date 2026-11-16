<template>
  <div class="flex shrink-0 items-center gap-0.5 sm:gap-2">
    <UButton
      to="/events"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-calendar-days"
      :aria-label="error ? 'Pending events unavailable' : 'Pending events'"
      :title="error ? 'Pending counts unavailable' : 'Pending events'"
    >
      <span class="hidden xl:inline">Events</span>
      <UIcon v-if="loading" name="i-lucide-loader-circle" class="size-4 animate-spin" />
      <span v-else-if="error" class="text-muted">?</span>
      <StatusDots v-else :stats="stats.events" />
    </UButton>

    <UButton
      to="/transport/queue"
      color="neutral"
      variant="ghost"
      size="sm"
      icon="i-lucide-inbox"
      :aria-label="error ? 'Pending transport unavailable' : 'Pending transport'"
      :title="error ? 'Pending counts unavailable' : 'Pending transport'"
    >
      <span class="hidden xl:inline">Transport</span>
      <UIcon v-if="loading" name="i-lucide-loader-circle" class="size-4 animate-spin" />
      <span v-else-if="error" class="text-muted">?</span>
      <StatusDots v-else :stats="stats.transport" />
    </UButton>
  </div>
</template>

<script setup lang="ts">
import StatusDots from '~/components/StatusDots.vue';
import type { HeaderStatusProps } from '~/types';

withDefaults(defineProps<HeaderStatusProps>(), { error: '' });
</script>
