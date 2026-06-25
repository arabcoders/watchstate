<template>
  <div class="ws-page-header">
    <div class="ws-page-heading" :class="headingClass">
      <span class="ws-page-icon">
        <slot name="icon">
          <UIcon :name="icon" class="size-5" />
        </slot>
      </span>

      <div class="min-w-0" :class="contentClass">
        <div class="ws-page-kicker">
          <slot name="kicker">
            <span>{{ sectionLabel }}</span>
            <span>/</span>
            <span>{{ pageLabel }}</span>
          </slot>
        </div>

        <p v-if="description" class="max-w-3xl text-sm text-toned">{{ description }}</p>
      </div>
    </div>

    <div
      v-if="$slots.actions"
      class="flex w-full flex-wrap items-center gap-2 xl:w-auto xl:justify-end"
    >
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { WatchStatePageHeaderProps } from '~/types';

withDefaults(defineProps<WatchStatePageHeaderProps>(), {
  contentClass: 'space-y-2',
  description: '',
  headingClass: '',
  icon: 'i-lucide-layout-dashboard',
});
</script>
