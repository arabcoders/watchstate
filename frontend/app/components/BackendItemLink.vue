<script setup lang="ts">
import { UButton } from '#components';
import type { BackendItemLinkProps } from '~/types';

const props = withDefaults(defineProps<BackendItemLinkProps>(), {
  icon: 'i-lucide-server',
  url: null,
  wide: false,
});
</script>

<template>
  <UButton
    :to="url ?? `/backend/${backend}`"
    :target="url ? '_blank' : undefined"
    :color="props.color"
    :variant="'neutral' === props.color ? 'ghost' : 'soft'"
    size="xs"
    :icon="icon"
    :label="backend"
    :class="wide ? 'w-full min-w-0' : 'max-w-full'"
    :ui="{ leadingIcon: 'size-3.5' }"
  />
</template>
