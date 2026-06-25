<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UTooltip text="Reload tasks">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            aria-label="Reload tasks"
            @click="loadContent"
          >
            <span class="hidden sm:inline">Reload</span>
          </UButton>
        </UTooltip>
      </template>
    </PageHeader>

    <UCard v-if="queued.length > 0" id="queued_tasks" class="shadow-sm" :ui="queuedCardUi">
      <template #header>
        <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
          <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin text-toned" />
          <span>Queued Tasks</span>
        </div>
      </template>

      <div class="space-y-3">
        <p class="text-sm text-default">
          The following tasks are queued to run in background soon.
        </p>

        <div class="flex flex-wrap gap-2">
          <NuxtLink
            v-for="task in queued"
            :key="`queued-${task}`"
            :to="`#${task}`"
            class="inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default hover:bg-elevated/60"
          >
            <UIcon name="i-lucide-clock-3" class="size-3.5" />
            <span class="capitalize">{{ task }}</span>
          </NuxtLink>
        </div>
      </div>
    </UCard>

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
      v-else-if="tasks.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No tasks found"
      description="There are no configured tasks to display right now."
    />

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="task in tasks"
        :id="task.name"
        :key="task.name"
        class="h-full shadow-sm"
        :class="task.enabled ? '' : 'opacity-85'"
        :ui="taskCardUi"
      >
        <template #header>
          <div class="space-y-2">
            <div class="flex min-w-0 items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-base font-semibold capitalize text-highlighted"
                  >
                    <UIcon name="i-lucide-list-checks" class="size-4 text-toned" />
                    <UTooltip :text="String(task.name)">
                      <span class="block min-w-0 truncate">{{ task.name }}</span>
                    </UTooltip>
                  </div>

                  <UBadge v-if="task.queued" color="neutral" variant="soft">Queued</UBadge>
                </div>
              </div>

              <div v-if="task.allow_disable" class="flex shrink-0 items-center justify-end">
                <USwitch
                  :model-value="task.enabled"
                  :color="task.enabled ? 'success' : 'neutral'"
                  label="Enabled"
                  @update:model-value="() => void toggleTask(task)"
                />
              </div>
            </div>

            <p v-if="task.description" class="text-sm leading-6 text-default">
              {{ task.description }}
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
                <NuxtLink
                  class="block font-medium text-primary underline underline-offset-2"
                  target="_blank"
                  :to="`https://crontab.guru/#${task.timer.replace(/ /g, '_')}`"
                >
                  {{ cronstrue.toString(task.timer) }}
                </NuxtLink>
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
                <UTooltip text="Edit cron timer.">
                  <NuxtLink
                    class="block break-all font-mono text-primary underline underline-offset-2"
                    :to="makeEnvLink(`WS_CRON_${task.name.toUpperCase()}_AT`, task.timer)"
                  >
                    {{ task.timer }}
                  </NuxtLink>
                </UTooltip>
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
                <UIcon name="i-lucide-settings-2" class="size-4 shrink-0" />
                <span>Args</span>
              </div>

              <template v-if="task.args">
                <div class="min-w-0 sm:ml-auto sm:text-right">
                  <UTooltip text="Edit task arguments.">
                    <NuxtLink
                      class="block break-all font-mono text-primary underline underline-offset-2"
                      :to="makeEnvLink(`WS_CRON_${task.name.toUpperCase()}_ARGS`, task.args)"
                    >
                      {{ task.args }}
                    </NuxtLink>
                  </UTooltip>
                </div>
              </template>
              <div v-else class="min-w-0 text-toned sm:ml-auto sm:text-right">None</div>
            </div>
          </div>

          <div
            class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
          >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
              <div
                class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
              >
                <UIcon name="i-lucide-history" class="size-4 shrink-0" />
                <span>Prev Run</span>
              </div>

              <div class="min-w-0 sm:ml-auto sm:text-right">
                <UTooltip
                  v-if="task.prev_run"
                  :text="`Last run was at: ${moment(task.prev_run).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  <button
                    v-if="task.prev_run_event_id"
                    type="button"
                    class="cursor-help text-primary underline underline-offset-2"
                    @click="selectedEventId = task.prev_run_event_id"
                  >
                    {{ moment(task.prev_run).fromNow() }}
                  </button>
                  <span v-else class="cursor-help">{{ moment(task.prev_run).fromNow() }}</span>
                </UTooltip>
                <span v-else>-</span>
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
                <template v-if="task.enabled">
                  <UTooltip
                    v-if="task.next_run"
                    :text="`Next run will be at: ${moment(task.next_run).format(TOOLTIP_DATE_FORMAT)}`"
                  >
                    <span class="cursor-help">{{ moment(task.next_run).fromNow() }}</span>
                  </UTooltip>
                  <span v-else>Never</span>
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
              :variant="task.queued ? 'soft' : 'outline'"
              size="sm"
              icon="i-lucide-clock-3"
              :ui="task.queued ? { leadingIcon: 'animate-spin' } : undefined"
              @click="queueTask(task)"
            >
              {{ task.queued ? 'Cancel Task' : 'Queue Task' }}
            </UButton>

            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-terminal"
              @click="toConsoleCmd(task)"
            >
              <span class="hidden sm:inline">Run via console</span>
              <span class="sm:hidden">Run now</span>
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
        <li>
          For long running tasks like <strong>Import</strong> and <strong>Export</strong>, you
          should queue the task to run in background. Running them via web console will take longer
          if you have many backends or large libraries.
        </li>
        <li>Use the switch next to the task to enable or disable automatic scheduling.</li>
        <li>
          Clicking on <strong>Runs</strong> opens an external page for cron syntax help. Clicking on
          <strong>Timer</strong> or <strong>Args</strong> opens the
          <span><UIcon name="i-lucide-settings-2" class="inline size-4 align-text-bottom" /></span>
          <strong>Env</strong> page to edit the related environment variable.
        </li>
        <li>
          Clicking on <strong>Prev Run</strong> time will open the event details if the previous run
          is available.
        </li>
      </ul>
    </UCard>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :id="selectedEventId"
          @delete="() => void closeEventView()"
          @open-event="(id) => (selectedEventId = id)"
        />
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import cronstrue from 'cronstrue';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import type { GenericError, GenericResponse, TaskItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  awaitElement,
  makeEventName,
  makeConsoleCommand,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';

useHead({ title: 'Tasks' });

const pageShell = requireTopLevelPageShell('tasks');

const route = useRoute();
const dialog = useDialog();
const tasks = ref<Array<TaskItem>>([]);
const queued = ref<Array<string>>([]);
const isLoading = ref<boolean>(false);
const selectedEventId = ref<string | null>(null);
const show_page_tips = useStorage<boolean>('show_page_tips', true);

const taskCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default/70 px-4 py-4',
};

const queuedCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const eventViewModalUi = {
  content: 'max-w-5xl',
  body: 'p-4 sm:p-5',
};

const eventViewOpen = computed({
  get: () => null !== selectedEventId.value,
  set: (value: boolean) => {
    if (false === value) {
      selectedEventId.value = null;
    }
  },
});

const eventViewTitle = computed(() =>
  null === selectedEventId.value ? 'Event' : `#${makeEventName(selectedEventId.value)}`,
);

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  tasks.value = [];

  try {
    const response = await request('/tasks');
    const json = await parse_api_response<{ tasks: Array<TaskItem>; queued: Array<string> }>(
      response,
    );

    if ('tasks' !== route.name) {
      return;
    }

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Tasks request error. ${json.error.code}: ${json.error.message}`,
      );
      return;
    }

    tasks.value = json.tasks;
    queued.value = json.queued;
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`);
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());

const toggleTask = async (task: TaskItem): Promise<void> => {
  try {
    const keyName = `WS_CRON_${task.name.toUpperCase()}`;
    const oldState = task.enabled;
    const update = await request(`/system/env/${keyName}`, {
      method: 'POST',
      body: JSON.stringify({ value: !task.enabled }),
    });

    if (200 !== update.status) {
      const json = await parse_api_response<GenericResponse>(update);
      if ('error' in json) {
        const errorJson = json as GenericError;
        notification(
          'error',
          'Error',
          `Failed to toggle task '${task.name}' status. ${errorJson.error.message}`,
        );
      } else {
        notification('error', 'Error', `Failed to toggle task '${task.name}' status.`);
      }

      const idx = tasks.value.findIndex((b) => b.name === task.name);
      if (-1 !== idx) {
        tasks.value[idx]!.enabled = oldState;
      }

      return;
    }

    const response = await request(`/tasks/${task.name}`);
    const updatedTask: TaskItem = await response.json();
    const idx = tasks.value.findIndex((b) => b.name === task.name);
    if (-1 !== idx) {
      tasks.value[idx] = updatedTask;
    }
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`);
  }
};

const queueTask = async (task: TaskItem): Promise<void> => {
  const is_queued = Boolean(task.queued);

  const { status: confirmStatus } = await dialog.confirmDialog({
    title: is_queued ? 'Cancel Task' : 'Queue Task',
    message: is_queued
      ? `Remove '${task.name}' from the queue?`
      : `Queue '${task.name}' to run in background?`,
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request(`/tasks/${task.name}/queue`, {
      method: is_queued ? 'DELETE' : 'POST',
    });

    if (response.ok) {
      notification(
        'success',
        'Success',
        `Task '${task.name}' has been ${is_queued ? 'cancelled' : 'queued'}.`,
      );
      task.queued = !is_queued;

      if (task.queued) {
        queued.value.push(task.name);
      } else {
        queued.value = queued.value.filter((t) => t !== task.name);
      }

      if (true === task.queued) {
        awaitElement('#queued_tasks', (_, e) =>
          e.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
            inline: 'nearest',
          }),
        );
      }
    }
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`);
  }
};

const makeEnvLink = (key: string, val: string | null = null): string => {
  const search = new URLSearchParams();
  search.set('callback', '/tasks');
  search.set('edit', key);

  if (val) {
    search.set('value', val);
  }

  return `/env?${search.toString()}`;
};

const toConsoleCmd = async (task: TaskItem): Promise<void> => {
  await navigateTo(makeConsoleCommand(`${task.command} ${task.args || ''}`));
};

const closeEventView = async (): Promise<void> => {
  selectedEventId.value = null;
  await loadContent();
};
</script>
