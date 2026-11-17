<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || null !== pendingTask"
          aria-label="Refresh tasks"
          @click="() => void loadContent()"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section
      v-if="0 < queued.length"
      id="queued_tasks"
      aria-labelledby="queued-tasks-title"
      class="ws-panel-padded scroll-mt-24 space-y-3"
    >
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2
          id="queued-tasks-title"
          class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted"
        >
          <UIcon name="i-lucide-clock-3" class="size-4 text-toned" />
          Queued tasks
          <UBadge color="neutral" variant="soft" size="xs">{{ queued.length }}</UBadge>
        </h2>
      </div>
      <div class="flex flex-wrap gap-2">
        <UButton
          v-for="name in queued"
          :key="name"
          :to="`#${name}`"
          color="neutral"
          variant="ghost"
          size="xs"
        >
          {{ formatTaskName(name) }}
        </UButton>
      </div>
    </section>

    <section aria-label="Scheduled tasks" :aria-busy="isLoading" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3" aria-live="polite">
        <p v-if="hasResults" class="text-sm text-toned">
          <strong class="font-medium text-highlighted">{{ tasks.length }}</strong>
          {{ 1 === tasks.length ? 'task' : 'tasks' }}
          <span class="text-muted">· {{ enabledCount }} enabled · {{ queued.length }} queued</span>
        </p>
        <p
          v-if="isLoading"
          role="status"
          class="inline-flex items-center gap-1.5 text-xs text-muted"
        >
          <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />
          {{ hasResults ? 'Refreshing tasks…' : 'Loading tasks…' }}
        </p>
      </div>

      <UAlert
        v-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="hasResults ? 'Tasks could not be refreshed' : 'Tasks could not be loaded'"
      >
        <template #description>
          <div class="space-y-2">
            <p class="wrap-break-word">{{ loadError }}</p>
            <p v-if="hasResults">Showing the last loaded tasks. Their status may be out of date.</p>
          </div>
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :disabled="isLoading || null !== pendingTask"
            @click="() => void loadContent()"
          >
            Retry
          </UButton>
        </template>
      </UAlert>

      <UEmpty
        v-if="hasResults && !isLoading && !loadError && 0 === tasks.length"
        icon="i-lucide-list-checks"
        title="No scheduled tasks"
        description="Check your task configuration, then refresh."
      />

      <div v-if="0 < tasks.length" class="ws-card divide-y divide-default">
        <article
          v-for="task in tasks"
          :id="task.name"
          :key="task.name"
          :aria-labelledby="`task-${task.name}`"
          class="grid scroll-mt-24 grid-cols-2 gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1.4fr)_minmax(0,0.8fr)_minmax(0,0.8fr)_auto]"
        >
          <div class="col-span-2 min-w-0 space-y-2 xl:col-span-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2
                :id="`task-${task.name}`"
                class="wrap-anywhere text-base font-semibold text-highlighted"
              >
                {{ formatTaskName(task.name) }}
              </h2>
              <UBadge v-if="task.queued" color="neutral" variant="soft" size="xs">Queued</UBadge>
            </div>
            <p v-if="task.description" class="text-sm text-toned">{{ task.description }}</p>
            <USwitch
              v-if="task.allow_disable"
              :model-value="task.enabled"
              :color="task.enabled ? 'success' : 'neutral'"
              :disabled="isLoading || !!loadError || null !== pendingTask"
              size="sm"
              label="Enabled"
              :aria-label="`Enable automatic scheduling for ${formatTaskName(task.name)}`"
              @update:model-value="() => void toggleTask(task)"
            />
            <span v-else class="inline-flex items-center gap-1.5 text-xs text-muted">
              <UIcon name="i-lucide-lock-keyhole" class="size-3.5" />
              {{ task.enabled ? 'Enabled' : 'Disabled' }}
            </span>
          </div>

          <dl
            class="col-span-2 flex min-w-0 flex-wrap content-start items-baseline gap-x-4 gap-y-2 text-sm xl:col-span-1"
          >
            <div class="w-full space-y-1">
              <dt class="text-xs text-muted">Schedule</dt>
              <dd>
                <NuxtLink
                  :to="cronHelpUrl + task.timer.replaceAll(' ', '_')"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-start gap-1 text-primary hover:underline underline-offset-2"
                  :aria-label="`${cronstrue.toString(task.timer)}. Explain cron schedule for ${formatTaskName(task.name)} (opens in a new tab)`"
                >
                  {{ cronstrue.toString(task.timer) }}
                  <UIcon name="i-lucide-arrow-up-right" class="mt-0.5 size-3.5 shrink-0" />
                </NuxtLink>
              </dd>
            </div>
            <div class="flex min-w-0 items-baseline gap-2">
              <dt class="shrink-0 text-xs text-muted">Timer</dt>
              <dd class="min-w-0">
                <NuxtLink
                  :to="makeEnvLink(`WS_CRON_${task.name.toUpperCase()}_AT`, task.timer)"
                  class="wrap-anywhere font-mono text-xs text-primary hover:underline underline-offset-2"
                  :aria-label="`Edit ${formatTaskName(task.name)} timer in Environment`"
                  >{{ task.timer }}</NuxtLink
                >
              </dd>
            </div>
            <div class="flex min-w-0 items-baseline gap-2">
              <dt class="shrink-0 text-xs text-muted">Arguments</dt>
              <dd class="min-w-0">
                <NuxtLink
                  :to="makeEnvLink(`WS_CRON_${task.name.toUpperCase()}_ARGS`, task.args)"
                  class="wrap-anywhere font-mono text-xs text-primary hover:underline underline-offset-2"
                  :aria-label="`Edit ${formatTaskName(task.name)} arguments in Environment`"
                  >{{ task.args || 'None' }}</NuxtLink
                >
              </dd>
            </div>
          </dl>

          <dl class="min-w-0 space-y-1 text-sm">
            <dt class="text-xs text-muted">Last run</dt>
            <dd>
              <UTooltip
                v-if="task.prev_run"
                :text="moment(task.prev_run).format(TOOLTIP_DATE_FORMAT)"
              >
                <button
                  v-if="task.prev_run_event_id"
                  type="button"
                  class="text-left text-primary hover:underline underline-offset-2"
                  :aria-label="`Inspect last run of ${formatTaskName(task.name)}`"
                  @click="selectedEventId = task.prev_run_event_id"
                >
                  <time :datetime="task.prev_run">{{ moment(task.prev_run).fromNow() }}</time>
                </button>
                <time v-else :datetime="task.prev_run">{{ moment(task.prev_run).fromNow() }}</time>
              </UTooltip>
              <span v-else class="text-toned">No recorded run</span>
            </dd>
          </dl>

          <dl class="min-w-0 space-y-1 text-sm">
            <dt class="text-xs text-muted">Next run</dt>
            <dd>
              <span v-if="!task.enabled" class="text-toned">Disabled</span>
              <UTooltip
                v-else-if="task.next_run"
                :text="moment(task.next_run).format(TOOLTIP_DATE_FORMAT)"
              >
                <time :datetime="task.next_run">{{ moment(task.next_run).fromNow() }}</time>
              </UTooltip>
              <span v-else class="text-toned">Not scheduled</span>
            </dd>
          </dl>

          <div
            class="col-span-2 flex flex-wrap items-start gap-2 xl:col-span-1 xl:flex-col xl:items-end"
          >
            <UButton
              color="primary"
              variant="solid"
              size="sm"
              icon="i-lucide-play"
              :aria-label="`Run ${formatTaskName(task.name)}`"
              :disabled="isLoading || !!loadError || null !== pendingTask"
              @click="runTaskName = task.name"
            >
              Run
            </UButton>
            <UButton
              v-if="task.queued"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-x"
              :disabled="isLoading || !!loadError || null !== pendingTask"
              @click="() => void setTaskQueued(task, false)"
            >
              Cancel queued task
            </UButton>
          </div>
        </article>
      </div>
    </section>

    <details class="ws-panel-padded text-sm">
      <summary class="cursor-pointer font-medium text-highlighted">Task options</summary>
      <div class="mt-3 space-y-2 text-toned">
        <p>
          Queue long imports &amp; exports for background execution. Disabling a schedule does not
          cancel queued work.
        </p>
      </div>
    </details>

    <UModal
      v-model:open="runTaskOpen"
      :title="runTask ? `Run ${formatTaskName(runTask.name)}` : 'Run task'"
      :description="runTask?.description || undefined"
      :dismissible="null === pendingTask"
      :close="null === pendingTask"
    >
      <template v-if="runTask?.queued" #body>
        <UAlert
          color="warning"
          variant="soft"
          icon="i-lucide-info"
          title="Already queued"
          description="Running in CLI does not cancel the queued task."
        />
      </template>
      <template #footer>
        <div v-if="runTask" class="flex w-full flex-wrap justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-terminal"
            :loading="isOpeningConsole"
            :disabled="isLoading || !!loadError || null !== pendingTask"
            @click="() => void openConsole(runTask)"
          >
            Run in CLI
          </UButton>
          <UButton
            color="primary"
            variant="solid"
            icon="i-lucide-clock-3"
            :loading="runTask.name === pendingTask && !isOpeningConsole"
            :disabled="runTask.queued || isLoading || !!loadError || null !== pendingTask"
            @click="() => void setTaskQueued(runTask, true)"
          >
            Queue task
          </UButton>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
      <template #body>
        <EventView
          v-if="selectedEventId"
          :id="selectedEventId"
          :actions-target="`#${eventActionsId}`"
          :key="selectedEventId"
          @deleted="() => void closeEventView()"
          @open-event="(id) => (selectedEventId = id)"
        />
      </template>
      <template #footer>
        <div :id="eventActionsId" class="w-full" />
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { navigateTo, useHead } from '#app';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UIcon,
  UModal,
  USwitch,
  UTooltip,
} from '#components';
import cronstrue from 'cronstrue';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import type { GenericResponse, TaskItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  makeEventName,
  makeConsoleCommand,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';

useHead({ title: 'Tasks' });

const pageShell = requireTopLevelPageShell('tasks');
const cronHelpUrl = 'https://crontab.guru/#';
const dialog = useDialog();
const tasks = ref<Array<TaskItem>>([]);
const isLoading = ref(true);
const hasResults = ref(false);
const loadError = ref('');
const pendingTask = ref<string | null>(null);
const isOpeningConsole = ref(false);
const runTaskName = ref<string | null>(null);
const runTask = computed(() => tasks.value.find((task) => runTaskName.value === task.name) ?? null);
const runTaskOpen = computed({
  get: () => null !== runTask.value,
  set: (value: boolean) => {
    if (false === value && null === pendingTask.value) {
      runTaskName.value = null;
    }
  },
});
const selectedEventId = ref<string | null>(null);
const queued = computed(() => tasks.value.filter((task) => task.queued).map((task) => task.name));
const enabledCount = computed(() => tasks.value.filter((task) => task.enabled).length);
const formatTaskName = (name: string): string => name.split('_').map(ucFirst).join(' ');
let loadController: AbortController | null = null;
let loadId = 0;
let disposed = false;

const eventActionsId = useId();
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
  if (disposed) {
    return;
  }

  loadController?.abort();
  const controller = new AbortController();
  loadController = controller;
  const id = ++loadId;
  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/tasks', { signal: controller.signal });
    const json = await parse_api_response<{ tasks: Array<TaskItem>; queued: Array<string> }>(
      response,
    );

    if (disposed || id !== loadId) {
      return;
    }

    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }
    if (!response.ok) {
      throw new Error(`Tasks request failed (${response.status}).`);
    }

    tasks.value = json.tasks;
    hasResults.value = true;
  } catch (error: unknown) {
    if (disposed || id !== loadId || controller.signal.aborted) {
      return;
    }

    loadError.value = error instanceof Error ? error.message : 'Unexpected request error';
    notification('error', 'Tasks unavailable', loadError.value);
  } finally {
    if (!disposed && id === loadId) {
      isLoading.value = false;
    }
  }
};

const toggleTask = async (task: TaskItem): Promise<void> => {
  if (
    disposed ||
    isLoading.value ||
    loadError.value ||
    null !== pendingTask.value ||
    !task.allow_disable ||
    !tasks.value.includes(task)
  ) {
    return;
  }

  pendingTask.value = task.name;

  try {
    const response = await request(`/system/env/WS_CRON_${task.name.toUpperCase()}`, {
      method: 'POST',
      body: JSON.stringify({ value: !task.enabled }),
    });
    const json = await parse_api_response<GenericResponse>(response);

    if (disposed) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (!response.ok) {
      throw new Error(`Schedule update failed (${response.status}).`);
    }

    task.enabled = !task.enabled;
    notification(
      'success',
      'Schedule updated',
      `'${formatTaskName(task.name)}' scheduling is ${task.enabled ? 'enabled' : 'disabled'}.`,
    );
    await loadContent();
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Schedule not updated', `'${formatTaskName(task.name)}': ${message}`);
    }
  } finally {
    pendingTask.value = null;
  }
};

const setTaskQueued = async (task: TaskItem | null, queued: boolean): Promise<void> => {
  if (
    null === task ||
    disposed ||
    isLoading.value ||
    loadError.value ||
    null !== pendingTask.value ||
    !tasks.value.includes(task) ||
    queued === Boolean(task.queued)
  ) {
    return;
  }

  const isQueued = Boolean(task.queued);
  pendingTask.value = task.name;

  try {
    if (isQueued) {
      const { status } = await dialog.confirmDialog({
        title: 'Cancel queued task',
        confirmText: 'Cancel queued task',
        confirmColor: 'neutral',
        message: `Remove '${formatTaskName(task.name)}' from the queue?`,
      });

      if (true !== status) {
        return;
      }
    }

    if (
      disposed ||
      isLoading.value ||
      loadError.value ||
      !tasks.value.includes(task) ||
      isQueued !== Boolean(task.queued)
    ) {
      return;
    }

    const response = await request(`/tasks/${task.name}/queue`, {
      method: isQueued ? 'DELETE' : 'POST',
    });

    if (disposed) {
      return;
    }
    if (!response.ok) {
      const json = await parse_api_response<GenericResponse>(response);
      throw new Error(
        'error' in json ? json.error.message : `Queue update failed (${response.status}).`,
      );
    }

    task.queued = queued;
    if (queued) {
      runTaskName.value = null;
    }
    notification(
      'success',
      isQueued ? 'Queued task cancelled' : 'Task queued',
      `'${formatTaskName(task.name)}' ${isQueued ? 'was removed from the queue' : 'is waiting for background execution'}.`,
    );
    await loadContent();

    if (!isQueued && !disposed) {
      await nextTick();
      if (!disposed) {
        document
          .getElementById('queued_tasks')
          ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Queue not updated', `'${formatTaskName(task.name)}': ${message}`);
    }
  } finally {
    pendingTask.value = null;
  }
};

const makeEnvLink = (key: string, value?: string): string => {
  const search = new URLSearchParams({ callback: '/tasks', edit: key });

  if (value) {
    search.set('value', value);
  }

  return `/env?${search.toString()}`;
};

const openConsole = async (task: TaskItem | null): Promise<void> => {
  if (
    null === task ||
    disposed ||
    isLoading.value ||
    loadError.value ||
    null !== pendingTask.value ||
    !tasks.value.includes(task)
  ) {
    return;
  }

  pendingTask.value = task.name;
  isOpeningConsole.value = true;

  try {
    const command = `${task.command} ${task.args || ''}`.trim();
    await navigateTo(makeConsoleCommand(command));
  } catch (error: unknown) {
    if (!disposed) {
      const message =
        error instanceof Error ? error.message : 'Unexpected command preparation error';
      notification(
        'error',
        'Console could not be opened',
        `'${formatTaskName(task.name)}': ${message}`,
      );
    }
  } finally {
    isOpeningConsole.value = false;
    pendingTask.value = null;
  }
};

const closeEventView = async (): Promise<void> => {
  selectedEventId.value = null;
  await loadContent();
};

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  disposed = true;
  loadId++;
  loadController?.abort();
});
</script>
