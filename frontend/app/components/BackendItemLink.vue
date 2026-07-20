<script setup lang="ts">
import { computed } from 'vue';
import { NuxtLink, UIcon } from '#components';

const props = withDefaults(
  defineProps<{
    backend: string;
    color: 'neutral' | 'warning' | 'error';
    icon?: string;
    url?: string | null;
    wide?: boolean;
  }>(),
  {
    icon: 'i-lucide-server',
    url: null,
    wide: false,
  },
);

const linkClass = computed<string>(() => {
  if ('warning' === props.color) {
    return 'inline-flex items-center gap-1.5 rounded-md border border-warning/30 bg-warning/10 px-2.5 py-1 text-xs font-medium text-warning hover:text-warning';
  }
  if ('error' === props.color) {
    return 'inline-flex items-center gap-1.5 rounded-md border border-error/30 bg-error/10 px-2.5 py-1 text-xs font-medium text-error hover:text-error';
  }

  return 'inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default hover:text-primary';
});
</script>

<template>
  <NuxtLink
    :to="url ?? `/backend/${backend}`"
    :target="url ? '_blank' : undefined"
    :class="[linkClass, wide ? 'w-full min-w-0' : 'max-w-full']"
  >
    <UIcon :name="icon" class="size-3.5 shrink-0" />
    <span class="truncate">{{ backend }}</span>
  </NuxtLink>
</template>
