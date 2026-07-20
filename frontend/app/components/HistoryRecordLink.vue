<script setup lang="ts">
import { computed } from 'vue';
import { NuxtLink } from '#components';

const props = defineProps<{ stateId: number; currentStateId: number }>();

const linkClass = computed<string>(() =>
  props.stateId === props.currentStateId
    ? 'inline-flex items-center gap-1.5 rounded-md border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary'
    : 'inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default hover:text-primary',
);
</script>

<template>
  <NuxtLink :to="`/history/${stateId}`" :class="linkClass">
    <UIcon name="i-lucide-file-clock" class="size-3.5" />
    #{{ stateId }}
  </NuxtLink>
</template>
