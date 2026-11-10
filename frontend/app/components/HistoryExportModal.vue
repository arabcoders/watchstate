<template>
  <UModal :open="open" title="Export History" @update:open="emit('update:open', $event)">
    <template #body>
      <div class="space-y-4">
        <UFormField label="Scope">
          <USelect v-model="scope" :items="scopeItems" class="w-full" :disabled="exporting" />
        </UFormField>
        <p v-if="'matching' === scope" class="text-sm text-toned">
          All matching records across all pages, using applied filters.
        </p>
        <p v-if="pageFilterActive" class="text-sm text-toned">
          Clear the displayed-results filter to export all matching records, or export a selection.
        </p>
        <p v-if="blocked" class="text-sm text-toned">
          Apply valid filters &amp; wait for results before exporting.
        </p>
        <UFormField label="Group">
          <USelect v-model="group" :items="groupItems" class="w-full" :disabled="exporting" />
        </UFormField>
        <UFormField label="Format">
          <USelect v-model="format" :items="formatItems" class="w-full" :disabled="exporting" />
        </UFormField>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="outline" @click="emit('update:open', false)"
          >Cancel</UButton
        >
        <UButton
          icon="i-lucide-download"
          :loading="exporting"
          :disabled="!canExport || exporting"
          @click="emit('export', { scope, group, format })"
          >Download</UButton
        >
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { UButton, UFormField, UModal, USelect } from '#components';
import type { HistoryExportChoice, HistoryExportModalProps } from '~/types';

const props = defineProps<HistoryExportModalProps>();
const emit = defineEmits<{
  'update:open': [value: boolean];
  export: [choice: HistoryExportChoice];
}>();

const scope = ref<HistoryExportChoice['scope']>('matching');
const group = ref<HistoryExportChoice['group']>('titles');
const format = ref<HistoryExportChoice['format']>('markdown');
const scopeItems = computed(() => [
  {
    label: `Matching records (${props.matchingCount})`,
    value: 'matching',
    disabled: !props.matchingEnabled,
  },
  {
    label: `Selected records (${props.selectedCount})`,
    value: 'selected',
    disabled: !props.selectedEnabled,
  },
]);
const groupItems = [
  { label: 'Titles (movies & shows)', value: 'titles' },
  { label: 'Records (movies & episodes)', value: 'records' },
];
const formatItems = [
  { label: 'Markdown', value: 'markdown' },
  { label: 'JSON', value: 'json' },
];
const canExport = computed(() =>
  'selected' === scope.value ? props.selectedEnabled : props.matchingEnabled,
);

watch(
  () => props.open,
  (open) => {
    if (open) {
      scope.value = 0 < props.selectedCount ? 'selected' : 'matching';
      group.value = 'titles';
      format.value = 'markdown';
    }
  },
);
</script>
