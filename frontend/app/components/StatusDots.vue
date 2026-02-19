<template>
  <div v-if="shouldShow" class="events-status-dots" role="img">
    <span class="status-item">
      <span class="status-dot has-text-info" :class="{ 'is-empty': queued === 0 }" role="img" aria-hidden="false"></span>
      <span class="dot-number">{{ displayCount(queued) }}</span>
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { EventsStats } from '~/types'

const props = withDefaults(defineProps<{ stats: EventsStats, hideZero?: boolean }>(), {
  hideZero: false,
})

const queued = computed(() => props.stats?.pending ?? 0)
const shouldShow = computed(() => (props.hideZero ? queued.value > 0 : true))


const displayCount = (n: number): string => n > 99 ? '99+' : String(n)
</script>

<style scoped>
.events-status-dots {
  display: inline-flex;
  gap: 0.5rem;
  align-items: center;
}
.status-item {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
  background-color: currentColor; /* uses Bulma text color classes */
  box-shadow: inset 0 0 0 1px rgba(0,0,0,0.04);
}
.status-dot.is-empty {
  opacity: 0.35;
}
.dot-number {
  font-weight: 700;
  font-size: 0.85rem;
  line-height: 1;
  color: inherit;
}
</style>
