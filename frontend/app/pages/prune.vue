<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="Clean up expired records, old reports, and generated files."
    >
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <span>Installation-wide</span>
      </template>
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || null !== openingMode"
          @click="() => void loadContent()"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Prune operations" :aria-busy="isLoading">
      <UAlert
        v-if="isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading pruners"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Unable to load pruners"
        :description="loadError"
      />

      <UEmpty v-else-if="0 === pruners.length" icon="i-lucide-scissors" title="No pruners found" />

      <div v-else class="ws-card divide-y divide-default">
        <article
          v-for="pruner in pruners"
          :id="pruner.name"
          :key="pruner.name"
          :aria-labelledby="`pruner-${pruner.name}`"
          class="grid scroll-mt-24 gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_auto]"
        >
          <div class="min-w-0 space-y-2">
            <h2
              :id="`pruner-${pruner.name}`"
              class="wrap-anywhere text-base font-semibold text-highlighted"
            >
              <UTooltip :text="pruner.name">
                <span>{{ pruner.display_name }}</span>
              </UTooltip>
            </h2>
            <p v-if="pruner.description" class="text-sm text-toned">
              {{ pruner.description }}
            </p>
          </div>

          <dl class="min-w-0 space-y-2 text-sm">
            <div class="space-y-1">
              <dt class="text-xs text-muted">Schedule</dt>
              <dd>
                <template v-if="null !== pruner.cron">
                  <NuxtLink
                    :to="cronHelpUrl + pruner.cron.replaceAll(' ', '_')"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-start gap-1 text-primary hover:underline underline-offset-2"
                    :aria-label="`Explain cron schedule for ${pruner.display_name} (opens in a new tab)`"
                  >
                    {{ describeSchedule(pruner.cron) }}
                    <UIcon name="i-lucide-arrow-up-right" class="mt-0.5 size-3.5 shrink-0" />
                  </NuxtLink>
                </template>
                <span v-else class="text-toned">Every run</span>
              </dd>
            </div>
            <div class="flex min-w-0 items-baseline gap-2">
              <dt class="shrink-0 text-xs text-muted">Timer</dt>
              <dd class="wrap-anywhere font-mono text-xs">{{ pruner.cron ?? 'Always' }}</dd>
            </div>
          </dl>

          <dl class="min-w-0 space-y-1 text-sm">
            <dt class="text-xs text-muted">Next run</dt>
            <dd>
              <span v-if="!pruner.enabled" class="text-toned">Disabled</span>
              <UTooltip
                v-else-if="pruner.next_run"
                :text="moment(pruner.next_run).format(TOOLTIP_DATE_FORMAT)"
              >
                <time :datetime="pruner.next_run" class="cursor-help">
                  {{ moment(pruner.next_run).fromNow() }}
                </time>
              </UTooltip>
              <span v-else class="text-toned">
                {{ null === pruner.cron ? 'Every run' : 'Unknown' }}
              </span>
            </dd>
          </dl>

          <div class="flex flex-wrap items-start gap-2 xl:flex-col xl:items-end">
            <UButton
              color="primary"
              variant="solid"
              size="sm"
              icon="i-lucide-play"
              :disabled="isLoading || !!loadError || null !== openingMode"
              :aria-label="`Run ${pruner.display_name}`"
              @click="runPrunerName = pruner.name"
            >
              Run
            </UButton>
          </div>
        </article>
      </div>
    </section>

    <section class="ws-panel-padded text-sm">
      <button
        type="button"
        class="flex w-full items-center justify-between gap-3 text-left font-medium text-highlighted"
        :aria-expanded="show_page_tips"
        aria-controls="prune-page-tips"
        @click="show_page_tips = !show_page_tips"
      >
        <span>Tips</span>
        <UIcon
          :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          class="size-4 text-toned"
        />
      </button>
      <div v-show="show_page_tips" id="prune-page-tips" class="mt-3 space-y-2 text-toned">
        <p>Disabled pruners can still be run manually.</p>
        <p>Pruners marked Always run with every scheduled pruning task.</p>
      </div>
    </section>

    <UModal
      v-model:open="runPrunerOpen"
      :title="runPruner ? `Run ${runPruner.display_name}` : 'Run pruner'"
      :description="runPruner?.description || undefined"
      :dismissible="null === openingMode"
      :close="null === openingMode"
    >
      <template #footer>
        <div v-if="runPruner" class="flex w-full flex-wrap justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-terminal"
            :loading="'dry-run' === openingMode"
            :disabled="isLoading || !!loadError || null !== openingMode"
            @click="() => void toConsoleCmd(runPruner, false)"
          >
            Dry run
          </UButton>
          <UButton
            color="primary"
            variant="solid"
            icon="i-lucide-terminal"
            :loading="'execute' === openingMode"
            :disabled="isLoading || !!loadError || null !== openingMode"
            @click="() => void toConsoleCmd(runPruner, true)"
          >
            Execute
          </UButton>
        </div>
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { navigateTo, useHead } from '#app';
import { NuxtLink, UAlert, UButton, UEmpty, UIcon, UModal, UTooltip } from '#components';
import { useStorage } from '@vueuse/core';
import cronstrue from 'cronstrue';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type { PrunerItem } from '~/types';
import {
  makeConsoleCommand,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';

useHead({ title: 'Prune' });

const pageShell = requireTopLevelPageShell('prune');
const cronHelpUrl = 'https://crontab.guru/#';
const pruners = ref<Array<PrunerItem>>([]);
const isLoading = ref(false);
const loadError = ref('');
const openingMode = ref<'dry-run' | 'execute' | null>(null);
const runPrunerName = ref<string | null>(null);
const runPruner = computed(
  () => pruners.value.find((pruner) => runPrunerName.value === pruner.name) ?? null,
);
const runPrunerOpen = computed({
  get: () => null !== runPruner.value,
  set: (open: boolean) => {
    if (false === open && null === openingMode.value) {
      runPrunerName.value = null;
    }
  },
});
const show_page_tips = useStorage<boolean>('show_prune_page_tips', true);
let disposed = false;

const describeSchedule = (cron: string): string => {
  try {
    return cronstrue.toString(cron);
  } catch {
    return 'Invalid cron schedule';
  }
};

const loadContent = async (): Promise<void> => {
  if (disposed || isLoading.value || null !== openingMode.value) {
    return;
  }

  isLoading.value = true;
  loadError.value = '';
  runPrunerName.value = null;
  pruners.value = [];

  try {
    const response = await request('/prune');
    const json = await parse_api_response<{ pruners: Array<PrunerItem> }>(response);

    if (disposed) {
      return;
    }
    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }
    if (!response.ok) {
      throw new Error(`Pruners request failed (${response.status}).`);
    }

    pruners.value = json.pruners;
  } catch (error: unknown) {
    if (!disposed) {
      loadError.value = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Pruners unavailable', loadError.value);
    }
  } finally {
    isLoading.value = false;
  }
};

const toConsoleCmd = async (pruner: PrunerItem | null, execute: boolean): Promise<void> => {
  if (
    null === pruner ||
    disposed ||
    isLoading.value ||
    loadError.value ||
    null !== openingMode.value ||
    !pruners.value.includes(pruner)
  ) {
    return;
  }

  openingMode.value = execute ? 'execute' : 'dry-run';

  try {
    const base = `system:prune --run --prune "${pruner.name}"`;
    const cmd = execute ? `${base} --execute` : `${base} -vvv`;
    await navigateTo(makeConsoleCommand(cmd));
  } catch (error: unknown) {
    if (!disposed) {
      const message =
        error instanceof Error ? error.message : 'Unexpected command preparation error';
      notification('error', 'Console could not be opened', `'${pruner.display_name}': ${message}`);
    }
  } finally {
    openingMode.value = null;
  }
};

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  disposed = true;
});
</script>
