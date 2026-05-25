<template>
  <div class="space-y-6">
    <section class="space-y-4">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-1">
          <div
            class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
          >
            <UIcon :name="pageShell.icon" class="size-4" />
            <span>{{ pageShell.pageLabel }}</span>
            <span v-if="api_user">/</span>
            <span v-if="api_user">{{ ucFirst(api_user) }}</span>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-plus"
            :disabled="isLoading"
            @click="toggleForm = !toggleForm"
          >
            Add Backend
          </UButton>

          <UTooltip text="Reload backends">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-refresh-cw"
              :loading="isLoading"
              :disabled="isLoading"
              aria-label="Reload backends"
              @click="loadContent"
            />
          </UTooltip>
        </div>
      </div>

      <UModal
        :open="toggleForm"
        title="Add Backend"
        :ui="backendAddModalUi"
        @update:open="handleBackendAddOpenChange"
      >
        <template #body>
          <BackendAdd
            v-if="toggleForm"
            :backends="backends"
            @backupData="(e) => handleEvents('backupData', e)"
            @forceExport="(e) => handleEvents('forceExport', e)"
            @addBackend="(e) => handleEvents('addBackend', e)"
            @forceImport="(e) => handleEvents('forceImport', e)"
            @close="() => void requestCloseBackendAdd()"
            @dirty-change="(dirty) => (backendAddDirty = dirty)"
          />
        </template>
      </UModal>

      <UModal
        :open="editBackendOpen"
        title="Edit Backend"
        :ui="backendEditModalUi"
        @update:open="handleBackendEditOpenChange"
      >
        <template #body>
          <BackendEditForm
            v-if="editBackendOpen && editBackendName"
            :backend-name="editBackendName"
            @close="() => void requestCloseBackendEdit()"
            @saved="(backend) => void handleBackendEdited(backend)"
            @dirty-change="(dirty) => (backendEditDirty = dirty)"
          />
        </template>
      </UModal>

      <UAlert
        v-if="0 === backends.length && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading backends. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="0 === backends.length"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="No backends found"
      >
        <template #description>
          <div class="space-y-2 text-sm text-default">
            <p>
              No backends found. Please add new backends to start using the tool. You can add a new
              backend by
              <button type="button" class="font-medium text-primary" @click="toggleForm = true">
                clicking here
              </button>
              or by using the add button above.
            </p>
          </div>
        </template>
      </UAlert>

      <div v-else class="grid gap-4 xl:grid-cols-2">
        <UCard
          v-for="backend in backends"
          :key="backend.name"
          class="h-full border border-default/70 shadow-sm"
          :ui="backendCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <UTooltip :text="String(backend.name)">
                  <NuxtLink
                    :to="`/backend/${backend.name}`"
                    class="block truncate text-base font-semibold text-highlighted hover:text-primary"
                  >
                    {{ backend.name }}
                  </NuxtLink>
                </UTooltip>
              </div>

              <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                <UTooltip v-if="backend.urls?.webhook" text="Copy webhook URL">
                  <UButton
                    color="neutral"
                    variant="outline"
                    size="sm"
                    square
                    icon="i-lucide-copy"
                    aria-label="Copy webhook URL"
                    @click.prevent="copyUrl(backend)"
                  >
                    <span class="hidden sm:inline">Copy Webhook URL</span>
                  </UButton>
                </UTooltip>

                <UTooltip text="Edit backend settings">
                  <UButton
                    color="neutral"
                    variant="outline"
                    size="sm"
                    icon="i-lucide-settings"
                    @click="openBackendEdit(backend.name)"
                  >
                    <span class="hidden sm:inline">Edit</span>
                  </UButton>
                </UTooltip>

                <UTooltip text="Delete backend">
                  <UButton
                    :to="`/backend/${backend.name}/delete?redirect=/backends`"
                    color="neutral"
                    variant="outline"
                    size="sm"
                    icon="i-lucide-trash-2"
                  >
                    <span class="hidden sm:inline">Delete</span>
                  </UButton>
                </UTooltip>
              </div>
            </div>
          </template>

          <div class="space-y-4">
            <div class="grid gap-3 lg:grid-cols-2">
              <div class="rounded-md border border-default bg-elevated/30 p-4">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-highlighted">Enable Export</p>
                    <p class="mt-1 text-sm text-toned">Send state updates to this backend.</p>
                  </div>

                  <USwitch
                    :model-value="backend.export.enabled"
                    :color="backend.export.enabled ? 'success' : 'neutral'"
                    @update:model-value="
                      (value) => updateValue(backend, 'export.enabled', Boolean(value))
                    "
                  />
                </div>
              </div>

              <div class="rounded-md border border-default bg-elevated/30 p-4">
                <div class="flex items-start justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-sm font-medium text-highlighted">Enable Import</p>
                    <p class="mt-1 text-sm">
                      Get
                      <template v-if="backend.import.enabled">
                        <UTooltip text="Watched status, playlists, progress and metadata">
                          <span class="text-success underline cursor-help">everything</span>
                        </UTooltip>
                      </template>
                      <template v-else>
                        <UTooltip
                          text="Import only metadata no watched status, playlists or progress will be imported"
                        >
                          <span class="text-error underline cursor-help">metadata</span>
                        </UTooltip>
                      </template>
                      from this backend.
                    </p>
                  </div>

                  <USwitch
                    :model-value="backend.import.enabled"
                    :color="backend.import.enabled ? 'success' : 'warning'"
                    @update:model-value="
                      (value) => updateValue(backend, 'import.enabled', Boolean(value))
                    "
                  />
                </div>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div class="rounded-md border border-default bg-elevated/40 p-4 text-sm text-default">
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-upload" class="size-3.5 shrink-0" />
                    <span>Last Export</span>
                  </div>

                  <div class="min-w-0 sm:ml-auto sm:text-right">
                    <template v-if="backend.export.enabled">
                      <UTooltip
                        v-if="backend.export.lastSync"
                        :text="moment(backend.export.lastSync).format(TOOLTIP_DATE_FORMAT)"
                      >
                        <span class="cursor-help font-medium text-highlighted">{{
                          moment(backend.export.lastSync).fromNow()
                        }}</span>
                      </UTooltip>
                      <span v-else class="font-medium text-highlighted">Never</span>
                    </template>

                    <UTooltip v-else text="Local database is not being sync to this backend.">
                      <UBadge color="error" variant="soft">Disabled</UBadge>
                    </UTooltip>
                  </div>
                </div>
              </div>

              <div class="rounded-md border border-default bg-elevated/40 p-4 text-sm text-default">
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-download" class="size-3.5 shrink-0" />
                    <span>Last Import ({{ backend.import.enabled ? 'Full' : 'Basic' }})</span>
                  </div>

                  <div class="min-w-0 sm:ml-auto sm:text-right">
                    <template v-if="backend.import">
                      <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <UTooltip
                          v-if="backend.import.lastSync"
                          :text="moment(backend.import.lastSync).format(TOOLTIP_DATE_FORMAT)"
                        >
                          <span class="cursor-help font-medium text-highlighted">{{
                            moment(backend.import.lastSync).fromNow()
                          }}</span>
                        </UTooltip>
                        <span v-else class="font-medium text-highlighted">Never</span>
                      </div>
                    </template>

                    <UTooltip v-else text="All data import from this backend is disabled.">
                      <UBadge color="error" variant="soft">Disabled</UBadge>
                    </UTooltip>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <template #footer>
            <div>
              <USelect
                v-model="selectedCommand"
                :items="getUsefulCommandItems(backend)"
                value-key="value"
                placeholder="Quick operations"
                icon="i-lucide-terminal"
                class="w-full"
                @update:model-value="() => void forwardCommand(backend)"
              />
            </div>
          </template>
        </UCard>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import BackendAdd from '~/components/BackendAdd.vue';
import BackendEditForm from '~/components/BackendEditForm.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  request,
  ag,
  copyText,
  makeConsoleCommand,
  notification,
  queue_event,
  r,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';
import type { Backend, JsonObject, JsonValue, UtilityCommand } from '~/types';

type UsefulCommand = UtilityCommand;

const pageShell = requireTopLevelPageShell('backends');

type UsefulCommands = Record<string, UsefulCommand>;

type CommandUtility = {
  /** Current date in YYYYMMDD format */
  date: string;
  /** API user name */
  user: string;
  /** Backend name (merged from backend) */
  name?: string;
  [key: string]: JsonValue | undefined;
};

type SelectItem = {
  label: string;
  value: string;
  disabled?: boolean;
};

useHead({ title: 'Backends' });

const backends = ref<Array<Backend>>([]);
const toggleForm = ref<boolean>(false);
const backendAddDirty = ref<boolean>(false);
const editBackendOpen = ref<boolean>(false);
const backendEditDirty = ref<boolean>(false);
const editBackendName = ref<string>('');
const api_user = useStorage('api_user', 'main');
const isLoading = ref<boolean>(false);
const selectedCommand = ref<string>('');

const backendCardUi = {
  header: 'p-5',
  body: 'p-5',
  footer: 'p-5 border-t border-default',
};

const backendAddModalUi = {
  content: 'max-w-5xl',
  body: 'p-4 sm:p-5',
};

const backendEditModalUi = {
  content: 'max-w-6xl',
  body: 'p-4 sm:p-5',
};

const { handleOpenChange: handleBackendAddOpenChange, requestClose: requestCloseBackendAdd } =
  useDirtyCloseGuard(toggleForm, {
    dirty: backendAddDirty,
    onDiscard: async () => {
      backendAddDirty.value = false;
    },
  });

const { handleOpenChange: handleBackendEditOpenChange, requestClose: requestCloseBackendEdit } =
  useDirtyCloseGuard(editBackendOpen, {
    dirty: backendEditDirty,
    onDiscard: async () => {
      backendEditDirty.value = false;
      editBackendName.value = '';
    },
  });

const usefulCommands: UsefulCommands = {
  export_now: {
    id: 1,
    title: 'Run normal export.',
    command: 'state:export -v -u {user} -s {name}',
  },
  import_now: {
    id: 2,
    title: 'Run normal import.',
    command: 'state:import -v -u {user} -s {name}',
  },
  force_export: {
    id: 3,
    title: 'Force export local play state to this backend.',
    command: 'state:export -fi -v -u {user} -s {name}',
  },
  backup_now: {
    id: 4,
    title: 'Backup this backend play state.',
    command: "state:backup -v -u {user} -s {name} --file '{date}.manual_{name}.json'",
  },
  metadata_only: {
    id: 5,
    title: 'Run metadata-only import from this backend.',
    command: 'state:import -v --metadata-only -u {user} -s {name}',
  },
  import_debug: {
    id: 6,
    title: 'Run import and save debug log.',
    command:
      "state:import -v --debug -u {user} -s {name} --logfile '/config/{user}@{name}.import.txt'",
  },
  export_debug: {
    id: 7,
    title: 'Run export and save debug log.',
    command:
      "state:export -v --debug -u {user} -s {name} --logfile '/config/{user}@{name}.export.txt'",
  },
  force_import: {
    id: 8,
    title: 'Force import local play state from this backend.',
    command: 'state:import -f -v -u {user} -s {name}',
  },
  force_metadata: {
    id: 9,
    title: 'Force metadata-only import from this backend.',
    command: 'state:import -f -v --metadata-only -u {user} -s {name}',
  },
};

const getUsefulCommandItems = (backend: Backend): Array<SelectItem> =>
  Object.entries(usefulCommands).map(([key, command]) => ({
    label: `${command.id}. ${command.title}`,
    value: key,
    disabled: !check_state(backend, command),
  }));

const forwardCommand = async (backend: Backend): Promise<void> => {
  if ('' === selectedCommand.value) {
    return;
  }

  const index = selectedCommand.value as keyof UsefulCommands;
  selectedCommand.value = '';

  const command = usefulCommands[index];
  if (!command) {
    return;
  }

  const util: CommandUtility = {
    date: moment().format('YYYYMMDD'),
    user: api_user.value,
  };

  await navigateTo(
    makeConsoleCommand(r(command.command, { ...backend, ...util } as unknown as JsonObject)),
  );
};

const loadContent = async (): Promise<void> => {
  backends.value = [];
  isLoading.value = true;
  try {
    const response = await request('/backends');
    const json = await response.json();
    if ('backends' !== useRoute().name) {
      return;
    }
    backends.value = json;
    useHead({ title: `${ucFirst(api_user.value)} @ Backends` });
  } catch (e) {
    const error = e as Error;
    notification('error', 'Error', `Failed to load backends. ${error.message}`);
  } finally {
    isLoading.value = false;
  }
};

onMounted((): void => {
  loadContent();
});

const copyUrl = (b: Backend): void => {
  if (b.urls?.webhook) {
    copyText(window.origin + b.urls.webhook);
  }
};

const openBackendEdit = (backendName: string): void => {
  editBackendName.value = backendName;
  backendEditDirty.value = false;
  editBackendOpen.value = true;
};

const handleBackendEdited = async (backend: Backend): Promise<void> => {
  backendEditDirty.value = false;
  editBackendOpen.value = false;
  editBackendName.value = '';

  const index = backends.value.findIndex((current) => current.name === backend.name);
  if (-1 !== index) {
    await loadContent();
    return;
  }

  await loadContent();
};

const updateValue = async (backend: Backend, key: string, newValue: boolean): Promise<void> => {
  const response = await request(`/backend/${backend.name}`, {
    method: 'PATCH',
    body: JSON.stringify([
      {
        key: key,
        value: newValue,
      },
    ]),
  });

  const updatedBackend = (await response.json()) as Backend;
  const index = backends.value.findIndex((b) => b.name === backend.name);
  if (-1 !== index) {
    backends.value[index] = updatedBackend;
  }
};

const handleEvents = async (event: string, backend: Backend): Promise<void> => {
  switch (event) {
    case 'backupData':
      try {
        await queue_event('run_console', {
          command: 'state:backup',
          args: [
            '-v',
            '--user',
            api_user.value,
            '--select-backend',
            backend.name,
            '--file',
            '{user}.{backend}.{date}.initial_backup.json',
          ],
        });
        notification(
          'info',
          'Info',
          `We are going to initiate a backup for '${backend.name}' in little bit.`,
          5000,
        );
      } catch (e) {
        const error = e as Error;
        notification('error', 'Error', `Failed to queue backup request. ${error.message}`);
      }
      break;
    case 'forceExport':
      try {
        await queue_event(
          'run_console',
          {
            command: 'state:export',
            args: ['-fi', '-v', '--user', api_user.value, '--select-backend', backend.name],
          },
          300,
        );

        notification(
          'info',
          'Info',
          `Soon we are going to force export the local data to '${backend.name}'.`,
          5000,
        );
      } catch (e) {
        const error = e as Error;
        notification('error', 'Error', `Failed to queue force export request. ${error.message}`);
      }
      break;
    case 'forceImport':
      try {
        await queue_event(
          'run_console',
          {
            command: 'state:import',
            args: ['-f', '-v', '--user', api_user.value, '--select-backend', backend.name],
          },
          300,
        );

        notification('info', 'Info', `Soon we will import data from '${backend.name}'.`, 5000);
      } catch (e) {
        const error = e as Error;
        notification('error', 'Error', `Failed to queue force export request. ${error.message}`);
      }
      break;
    case 'addBackend':
      backendAddDirty.value = false;
      toggleForm.value = false;
      await loadContent();
      break;
  }
};

const check_state = (backend: Backend, command: { state_key?: string }): boolean => {
  if (!command?.state_key) {
    return true;
  }

  const state = ag(backend as unknown as JsonObject, command.state_key, false);
  return Boolean(state);
};
</script>
