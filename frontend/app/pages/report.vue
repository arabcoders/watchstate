<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0 space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <span>{{ pageShell.pageLabel }}</span>
        </div>
      </div>

      <div v-if="data.length > 0" class="flex flex-wrap items-center justify-end gap-2">
        <UTooltip text="Scroll to top">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            leading-icon="i-lucide-chevron-up"
            aria-label="Scroll report to top"
            @click="scrollToTop"
          >
            <span class="hidden sm:inline">Scroll Up</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Scroll to bottom">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            leading-icon="i-lucide-chevron-down"
            aria-label="Scroll report to bottom"
            @click="scrollToBottom"
          >
            <span class="hidden sm:inline">Scroll Down</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Copy report">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            leading-icon="i-lucide-copy"
            aria-label="Copy report"
            @click="copyReport"
          >
            <span class="hidden sm:inline">Copy Report</span>
          </UButton>
        </UTooltip>
      </div>
    </div>

    <UAlert
      v-if="data.length < 1"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Generating the report. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UCard v-else class="border border-default/70 shadow-sm" :ui="{ body: 'p-0' }">
      <pre
        ref="reportViewport"
        class="max-h-[70vh] min-h-[60vh] overflow-y-auto bg-elevated p-4 text-sm text-default wrap-pre whitespace-pre-wrap wrap-break-word"
      ><code><span v-for="(item, index) in data" :key="index" class="block">{{ item }}</span></code></pre>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useHead, useRoute } from '#app';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { copyText, parse_api_response, request } from '~/utils';

useHead({ title: 'System Report' });

const pageShell = requireTopLevelPageShell('report');

const route = useRoute();
const dialog = useDialog();

const data = ref<Array<string>>([]);
const reportViewport = ref<HTMLElement | null>(null);

const loadReport = async (): Promise<void> => {
  const response = await request(`/system/report`);
  const json = await parse_api_response<Array<string>>(response);
  if ('error' in json) {
    return;
  }

  if (route.name !== 'report') {
    return;
  }

  data.value = json;
};

const copyReport = async (): Promise<void> => {
  if (data.value.length < 1) {
    return;
  }

  const { status } = await dialog.confirmDialog({
    title: 'Copy Report',
    confirmText: 'Copy Report',
    confirmColor: 'warning',
    message:
      'While we try to make sure no sensitive information is leaked via the report, it is possible that something might be missed. Please review the report before posting it. If you notice any sensitive information, please report it to the developers so we can fix it.',
  });

  if (true !== status) {
    return;
  }

  copyText(data.value.join('\n'));
};

const scrollToTop = () => {
  if (reportViewport.value) {
    reportViewport.value.scrollTo({ top: 0, behavior: 'smooth' });
  }
};

const scrollToBottom = () => {
  if (reportViewport.value) {
    reportViewport.value.scrollTo({ top: reportViewport.value.scrollHeight, behavior: 'smooth' });
  }
};

onMounted(async () => await loadReport());
</script>
