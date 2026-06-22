<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UTooltip text="Reload pruners">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            aria-label="Reload pruners"
            @click="loadContent"
          >
            <span class="hidden sm:inline">Reload</span>
          </UButton>
        </UTooltip>
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="pruners.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No pruners found"
      description="There are no configured prune handlers to display."
    />

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="pruner in pruners"
        :key="pruner.name"
        class="h-full shadow-sm"
        :class="pruner.enabled ? '' : 'opacity-85'"
        :ui="prunerCardUi"
      >
        <template #header>
          <div class="space-y-2">
            <div class="flex min-w-0 items-start gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-base font-semibold text-highlighted"
                  >
                    <UIcon name="i-lucide-scissors" class="size-4 text-toned" />
                    <UTooltip :text="pruner.name">
                      <span class="block min-w-0 truncate">{{ pruner.display_name }}</span>
                    </UTooltip>
                  </div>

                  <UBadge v-if="!pruner.enabled" color="neutral" variant="soft"> Disabled </UBadge>
                </div>
              </div>
            </div>

            <p v-if="pruner.description" class="text-sm leading-6 text-default">
              {{ pruner.description }}
            </p>
          </div>
        </template>

        <div class="grid grid-cols-2 gap-3">
          <div
            class="col-span-2 rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
          >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
              <div
                class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-calendar-range" class="size-4 shrink-0" />
                <span>Runs</span>
              </div>

              <div class="min-w-0 sm:ml-auto sm:text-right">
                <template v-if="pruner.cron">
                  <NuxtLink
                    class="block font-medium text-primary underline underline-offset-2"
                    target="_blank"
                    :to="`https://crontab.guru/#${pruner.cron.replace(/ /g, '_')}`"
                  >
                    {{ cronstrue.toString(pruner.cron) }}
                  </NuxtLink>
                </template>
                <span v-else class="text-toned">Every run</span>
              </div>
            </div>
          </div>

          <div
            class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
          >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
              <div
                class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-clock-3" class="size-4 shrink-0" />
                <span>Timer</span>
              </div>

              <div class="min-w-0 sm:ml-auto sm:text-right">
                <span class="block break-all font-mono">
                  {{ pruner.cron ?? 'Always' }}
                </span>
              </div>
            </div>
          </div>

          <div
            class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
          >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
              <div
                class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-forward" class="size-4 shrink-0" />
                <span>Next Run</span>
              </div>

              <div class="min-w-0 sm:ml-auto sm:text-right">
                <template v-if="pruner.enabled">
                  <UTooltip
                    v-if="pruner.next_run"
                    :text="`Next run will be at: ${moment(pruner.next_run).format(TOOLTIP_DATE_FORMAT)}`"
                  >
                    <span class="cursor-help">{{ moment(pruner.next_run).fromNow() }}</span>
                  </UTooltip>
                  <span v-else>Every run</span>
                </template>
                <span v-else class="text-toned">Disabled</span>
              </div>
            </div>
          </div>
        </div>

        <template #footer>
          <div class="flex flex-wrap items-center justify-end gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-terminal"
              @click="toConsoleCmd(pruner, false)"
            >
              Dry Run
            </UButton>

            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-terminal"
              @click="toConsoleCmd(pruner, true)"
            >
              Execute
            </UButton>
          </div>
        </template>
      </UCard>
    </div>

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </span>

          <span class="inline-flex items-center gap-1 text-xs font-medium text-toned">
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4"
            />
            <span>{{ show_page_tips ? 'Hide' : 'Show' }}</span>
          </span>
        </button>
      </template>

      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li><strong>Dry Run</strong> shows what would be removed without making any changes.</li>
        <li><strong>Execute</strong> performs the actual pruning operation, if applicable.</li>
        <li>
          Pruners run automatically based on their cron schedule. Use the button to trigger them
          manually.
        </li>
      </ul>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
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

const route = useRoute();
const pruners = ref<Array<PrunerItem>>([]);
const isLoading = ref<boolean>(false);
const show_page_tips = useStorage<boolean>('show_prune_page_tips', true);

const prunerCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default/70 px-4 py-4',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  pruners.value = [];

  try {
    const response = await request('/prune');
    const json = await parse_api_response<{ pruners: Array<PrunerItem> }>(response);

    if ('prune' !== route.name) {
      return;
    }

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Pruners request error. ${json.error.code}: ${json.error.message}`,
      );
      return;
    }

    pruners.value = json.pruners;
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`);
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());

const toConsoleCmd = async (pruner: PrunerItem, execute: boolean): Promise<void> => {
  const base = `system:prune --run --prune "${pruner.name}"`;
  const cmd = execute ? `${base} --execute` : `${base} -vvv`;
  await navigateTo(makeConsoleCommand(cmd));
};
</script>
