<script setup lang="ts">
import { computed, ref } from 'vue';
import { UButton, UPopover } from '#components';
import DuplicateRecordList from '~/components/DuplicateRecordList.vue';
import type { HistoryRecordLinkProps } from '~/types';

const props = defineProps<HistoryRecordLinkProps>();

const isCurrent = computed(() => props.currentStateId === props.stateId);
const isOpen = ref(false);
</script>

<template>
  <UPopover
    v-model:open="isOpen"
    :content="{ side: 'bottom', align: 'start' }"
    :portal="false"
    :ui="{ content: 'z-50' }"
  >
    <UButton
      type="button"
      :color="isCurrent ? 'primary' : 'neutral'"
      :variant="isCurrent ? 'soft' : 'outline'"
      size="xs"
      icon="i-lucide-file-clock"
      :label="`#${stateId}`"
      :aria-label="`Preview history record #${stateId}`"
      :ui="{ leadingIcon: 'size-3.5' }"
    />
    <template #content>
      <DuplicateRecordList
        v-if="isOpen"
        :key="`${user}:${stateId}`"
        :ids="[stateId]"
        :user="user"
      />
    </template>
  </UPopover>
</template>
