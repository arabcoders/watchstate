<template>
  <div class="w-full min-w-0 max-w-full space-y-8">
    <section class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #kicker>
          <span>{{ pageShell.pageLabel }}</span>
          <span v-if="api_user">/</span>
          <span v-if="api_user">{{ ucFirst(api_user) }}</span>
        </template>

        <template #actions>
          <UButton
            color="primary"
            variant="solid"
            icon="i-lucide-plus"
            :disabled="isLoading"
            @click="toggleForm = true"
          >
            Add Backend
          </UButton>

          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            @click="loadContent"
          >
            Refresh
          </UButton>
        </template>
      </PageHeader>

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
            :actions-target="`#${backendAddFooterId}`"
            @backupData="(e) => handleEvents('backupData', e)"
            @forceExport="(e) => handleEvents('forceExport', e)"
            @addBackend="(e) => handleEvents('addBackend', e)"
            @forceImport="(e) => handleEvents('forceImport', e)"
            @dirty-change="(dirty) => (backendAddDirty = dirty)"
          />
        </template>
        <template #footer>
          <div :id="backendAddFooterId" class="w-full" />
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
            :actions-target="`#${backendEditFooterId}`"
            @saved="void handleBackendEdited()"
            @dirty-change="(dirty) => (backendEditDirty = dirty)"
          />
        </template>
        <template #footer>
          <div :id="backendEditFooterId" class="w-full" />
        </template>
      </UModal>

      <UModal
        :open="null !== webhookBackend"
        title="Webhook Management"
        @update:open="handleWebhookModalClose"
      >
        <template #body>
          <div v-if="null !== webhookBackend" class="space-y-3">
            <p class="text-sm text-toned">
              Send watch changes to WatchState as they happen. Webhook setup requires:
            </p>
            <ul class="list-disc pl-5 text-sm text-toned">
              <li>A reachable backend and a token with permission to manage webhooks.</li>
              <li>The webhook plugin for Jellyfin.</li>
              <li>Plex Pass for Plex, or Emby Premiere for Emby.</li>
            </ul>
            <UInput
              :model-value="webhookFullUrl"
              readonly
              icon="i-lucide-link"
              size="sm"
              class="w-full"
            />
          </div>
        </template>
        <template #footer>
          <div v-if="null !== webhookBackend" class="flex w-full items-center justify-end gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-copy"
              @click="copyUrl(webhookBackend)"
            >
              Copy URL
            </UButton>
            <UButton
              color="primary"
              variant="solid"
              size="sm"
              icon="i-lucide-webhook"
              :loading="webhookLoading"
              @click="addWebhook(webhookBackend)"
            >
              Add/Update
            </UButton>
          </div>
        </template>
      </UModal>
    </section>

    <section aria-label="Backend connections" :aria-busy="isLoading" class="space-y-4">
      <div v-if="0 < backends.length" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-toned">
          {{ backends.length }} {{ 1 === backends.length ? 'backend' : 'backends' }}
          <span class="text-muted">for {{ api_user }}</span>
        </p>
        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Find a backend…"
          aria-label="Find a backend by name or server type"
          class="w-full sm:w-72"
        />
      </div>

      <UAlert
        v-if="loadError"
        role="alert"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="
          0 < backends.length ? 'Backends could not be refreshed' : 'Backends could not be loaded'
        "
      >
        <template #description>
          <p class="wrap-break-word">{{ loadError }}</p>
          <p v-if="0 < backends.length" class="mt-1 text-xs">These settings may be out of date.</p>
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            @click="loadContent"
          >
            Retry
          </UButton>
        </template>
      </UAlert>

      <div
        v-if="isLoading && 0 === backends.length"
        role="status"
        aria-label="Loading backends"
        class="space-y-4"
      >
        <div v-for="index in 2" :key="index" class="ws-card-padded-lg space-y-5">
          <USkeleton class="h-6 w-40" />
          <div class="grid gap-4 sm:grid-cols-2">
            <USkeleton class="h-20" />
            <USkeleton class="h-20" />
          </div>
        </div>
      </div>

      <UEmpty
        v-else-if="0 === backends.length && !loadError"
        icon="i-lucide-server"
        title="No backends yet"
        :description="`Add a Plex, Jellyfin, or Emby connection to start syncing watch history for ${api_user}.`"
      >
        <template #actions>
          <UButton color="primary" variant="solid" icon="i-lucide-plus" @click="toggleForm = true">
            Add Backend
          </UButton>
        </template>
      </UEmpty>

      <UEmpty
        v-else-if="0 < backends.length && 0 === filteredBackends.length"
        icon="i-lucide-search"
        title="No matching backends"
        description="Try another name or server type."
      >
        <template #actions>
          <UButton color="neutral" variant="outline" icon="i-lucide-x" @click="search = ''">
            Clear search
          </UButton>
        </template>
      </UEmpty>

      <article
        v-for="backend in filteredBackends"
        :key="backend.name"
        :aria-labelledby="`backend-${backend.name}`"
        class="ws-card overflow-hidden"
      >
        <div class="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-5">
          <div class="flex min-w-0 items-start gap-3">
            <span class="ws-section-icon">
              <UIcon name="i-lucide-server" class="size-5" />
            </span>
            <div class="min-w-0 space-y-1">
              <h2 :id="`backend-${backend.name}`" class="text-lg font-semibold text-highlighted">
                <NuxtLink
                  :to="`/backend/${backend.name}`"
                  class="wrap-anywhere hover:text-primary focus-visible:outline-primary"
                  >{{ backend.name }}</NuxtLink
                >
              </h2>
              <p class="text-sm text-muted">{{ ucFirst(backend.type) }}</p>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-pencil"
              @click="openBackendEdit(backend.name)"
              >Edit</UButton
            >
            <UButton
              v-if="'main' === api_user && backend.urls?.webhook"
              color="neutral"
              variant="outline"
              icon="i-lucide-webhook"
              @click="webhookBackend = backend"
              >Add Webhook</UButton
            >
            <UButton
              :to="`/backend/${backend.name}/delete?redirect=/backends`"
              color="neutral"
              variant="outline"
              icon="i-lucide-trash-2"
              :aria-label="`Delete ${backend.name}`"
            >
              Delete
            </UButton>
          </div>
        </div>

        <div class="grid gap-6 px-4 pb-5 sm:px-5 lg:grid-cols-2 lg:gap-10">
          <div class="min-w-0 space-y-3">
            <USwitch
              :model-value="backend.import.enabled"
              :color="backend.import.enabled ? 'success' : 'warning'"
              :disabled="isLoading || !!loadError || updatingBackends.includes(backend.name)"
              label="Enable Import"
              :aria-label="`Enable Import for ${backend.name}`"
              :description="
                backend.import.enabled
                  ? 'Bring watch state, progress, playlists & metadata into WatchState.'
                  : 'Metadata only. Watch state, progress & playlists stay unchanged.'
              "
              @update:model-value="
                (value) => void updateValue(backend, 'import.enabled', Boolean(value))
              "
            />
            <p class="flex flex-wrap items-center gap-2 text-xs text-muted">
              <span>Last Import ({{ backend.import.enabled ? 'Full' : 'Basic' }})</span>
              <UTooltip
                v-if="backend.import.lastSync"
                :text="moment(backend.import.lastSync).format(TOOLTIP_DATE_FORMAT)"
              >
                <span class="cursor-help text-toned">{{
                  moment(backend.import.lastSync).fromNow()
                }}</span>
              </UTooltip>
              <span v-else>Never</span>
            </p>
          </div>

          <div class="min-w-0 space-y-3">
            <USwitch
              :model-value="backend.export.enabled"
              :color="backend.export.enabled ? 'success' : 'neutral'"
              :disabled="isLoading || !!loadError || updatingBackends.includes(backend.name)"
              label="Enable Export"
              :aria-label="`Enable Export for ${backend.name}`"
              description="Send WatchState's watch state & progress to this backend."
              @update:model-value="
                (value) => void updateValue(backend, 'export.enabled', Boolean(value))
              "
            />
            <p class="flex flex-wrap items-center gap-2 text-xs text-muted">
              <span>Last Export</span>
              <UTooltip
                v-if="backend.export.lastSync"
                :text="moment(backend.export.lastSync).format(TOOLTIP_DATE_FORMAT)"
              >
                <span class="cursor-help text-toned">{{
                  moment(backend.export.lastSync).fromNow()
                }}</span>
              </UTooltip>
              <span v-else>Never</span>
              <UBadge v-if="!backend.export.enabled" color="neutral" variant="soft" size="sm">
                Disabled
              </UBadge>
            </p>
          </div>
        </div>

        <div
          v-if="backendReportSummary(backend.name)"
          class="border-t border-default bg-elevated/20 px-4 py-3 sm:px-5"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-xs font-medium text-toned">Local records</h3>
            <UTooltip
              v-if="backendReport?.report?.completed_at"
              :text="moment.unix(backendReport.report.completed_at).format(TOOLTIP_DATE_FORMAT)"
            >
              <span class="cursor-help text-xs text-muted">
                Report {{ moment.unix(backendReport.report.completed_at).fromNow() }}
              </span>
            </UTooltip>
          </div>
          <dl class="mt-3 grid grid-cols-3 gap-x-4 gap-y-3 sm:grid-cols-6">
            <div v-for="stat in backendReportCards(backend.name)" :key="stat.label">
              <dt class="text-xs text-muted">{{ stat.label }}</dt>
              <dd :class="['mt-1 text-sm font-semibold tabular-nums', stat.color]">
                {{ stat.value }}
              </dd>
            </div>
          </dl>
        </div>

        <div
          class="flex flex-wrap items-center justify-between gap-3 border-t border-default px-4 py-3 sm:px-5"
        >
          <UButton
            :to="`/backend/${backend.name}/libraries`"
            color="neutral"
            variant="ghost"
            icon="i-lucide-library-big"
            >Libraries</UButton
          >
          <USelect
            v-model="selectedCommand"
            :items="getUsefulCommandItems(backend)"
            value-key="value"
            placeholder="Quick operations"
            :aria-label="`Quick operations for ${backend.name}`"
            icon="i-lucide-terminal"
            :disabled="isLoading || !!loadError || updatingBackends.includes(backend.name)"
            class="w-full sm:w-96"
            @update:model-value="() => void forwardCommand(backend)"
          />
        </div>
      </article>

      <UAlert
        v-if="reportError && 0 < backends.length"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        title="Local record counts are unavailable"
        :description="reportError"
      >
        <template #actions>
          <UButton
            to="/backend_report"
            color="neutral"
            variant="ghost"
            trailing-icon="i-lucide-arrow-right"
          >
            Backend Report
          </UButton>
        </template>
      </UAlert>
      <p
        v-else-if="!isLoading && 0 < backends.length && !backendReport?.report?.summary"
        class="text-sm text-toned"
      >
        {{
          backendReport?.queued
            ? 'Local record counts are being prepared.'
            : 'No local record counts yet.'
        }}
        <NuxtLink to="/backend_report" class="font-medium text-primary"
          >Open Backend Report</NuxtLink
        >
      </p>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, computed, useId, watch } from 'vue';
import { navigateTo, useHead } from '#app';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UIcon,
  UInput,
  UModal,
  USelect,
  USkeleton,
  USwitch,
  UTooltip,
} from '#components';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import BackendAdd from '~/components/BackendAdd.vue';
import BackendEditForm from '~/components/BackendEditForm.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  request,
  ag,
  copyText,
  makeConsoleCommand,
  notification,
  parse_api_response,
  queue_event,
  r,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';
import type {
  Backend,
  BackendReportBackendSummary,
  BackendReportResponse,
  JsonObject,
  JsonValue,
  UtilityCommand,
} from '~/types';

type UsefulCommand = UtilityCommand;

const pageShell = requireTopLevelPageShell('backends');
const backendAddFooterId = useId();
const backendEditFooterId = useId();

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

const backends = ref<Array<Backend>>([]);
const backendReport = ref<BackendReportResponse | null>(null);
const toggleForm = ref<boolean>(false);
const backendAddDirty = ref<boolean>(false);
const editBackendOpen = ref<boolean>(false);
const backendEditDirty = ref<boolean>(false);
const editBackendName = ref<string>('');
const api_user = useStorage('api_user', 'main');
const isLoading = ref<boolean>(true);
const selectedCommand = ref<string>('');
const search = ref('');
const loadError = ref('');
const reportError = ref('');
const updatingBackends = ref<Array<string>>([]);
let loadVersion = 0;

useHead(() => ({ title: `${ucFirst(api_user.value)} @ Backends` }));

const filteredBackends = computed(() => {
  const query = search.value.trim().toLowerCase();
  return backends.value.filter((backend) =>
    `${backend.name} ${backend.type}`.toLowerCase().includes(query),
  );
});

const formatReportNumber = (value: number): string => new Intl.NumberFormat().format(value);

const backendReportSummary = (backend: string): BackendReportBackendSummary | null =>
  backendReport.value?.report?.summary?.backends[backend] ?? null;

const backendReportCards = (
  backend: string,
): Array<{ label: string; value: string; color: string }> => {
  const summary = backendReportSummary(backend);
  if (null === summary) {
    return [];
  }

  return [
    {
      label: 'Total',
      value: formatReportNumber(summary.total),
      color: 'text-highlighted',
    },
    {
      label: 'Movies',
      value: formatReportNumber(summary.types.movie.total),
      color: 'text-highlighted',
    },
    {
      label: 'Episodes',
      value: formatReportNumber(summary.types.episode.total),
      color: 'text-highlighted',
    },
    {
      label: 'Watched',
      value: formatReportNumber(summary.watched),
      color: 'text-success',
    },
    {
      label: 'Unwatched',
      value: formatReportNumber(summary.unwatched),
      color: 'text-highlighted',
    },
    {
      label: 'In progress',
      value: formatReportNumber(summary.in_progress),
      color: 'text-info',
    },
  ];
};

const webhookBackend = ref<Backend | null>(null);
const webhookLoading = ref<boolean>(false);

const webhookFullUrl = computed((): string => {
  const urls = webhookBackend.value?.urls;
  if (undefined === urls?.webhook) {
    return '';
  }
  return window.origin + urls.webhook;
});

const backendAddModalUi = {
  content: 'max-w-5xl',
};

const backendEditModalUi = {
  content: 'max-w-6xl',
};

const { handleOpenChange: handleBackendAddOpenChange } = useDirtyCloseGuard(toggleForm, {
  dirty: backendAddDirty,
  onDiscard: async () => {
    backendAddDirty.value = false;
  },
});

const { handleOpenChange: handleBackendEditOpenChange } = useDirtyCloseGuard(editBackendOpen, {
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
    title: 'Force export play and progress state',
    command: 'state:export -fi --with-progress -v -u {user} -s {name}',
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
  const version = ++loadVersion;
  const user = api_user.value;
  isLoading.value = true;
  loadError.value = '';
  reportError.value = '';

  try {
    const [backendsResult, reportResult] = await Promise.allSettled([
      request('/backends', { headers: { 'X-User': user } }).then((response) =>
        parse_api_response<Array<Backend>>(response),
      ),
      request('/state/backend-report', { headers: { 'X-User': user } }).then((response) =>
        parse_api_response<BackendReportResponse>(response),
      ),
    ]);

    if (version !== loadVersion || user !== api_user.value) {
      return;
    }

    if ('rejected' === backendsResult.status) {
      throw backendsResult.reason;
    }

    const json = backendsResult.value;
    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }
    backends.value = json;

    if ('rejected' === reportResult.status) {
      backendReport.value = null;
      reportError.value =
        reportResult.reason instanceof Error
          ? reportResult.reason.message
          : 'Could not load Backend Report.';
    } else if ('error' in reportResult.value) {
      backendReport.value = null;
      reportError.value = reportResult.value.error.message;
    } else {
      backendReport.value = reportResult.value;
    }
  } catch (e) {
    if (version !== loadVersion || user !== api_user.value) {
      return;
    }

    loadError.value = e instanceof Error ? e.message : 'Could not load backends.';
    notification('error', 'Backends could not be loaded', loadError.value);
  } finally {
    if (version === loadVersion) {
      isLoading.value = false;
    }
  }
};

onMounted((): void => {
  void loadContent();
});

watch(
  api_user,
  () => {
    backends.value = [];
    backendReport.value = null;
    search.value = '';
    webhookBackend.value = null;
    toggleForm.value = false;
    editBackendOpen.value = false;
    editBackendName.value = '';
    backendAddDirty.value = false;
    backendEditDirty.value = false;
    void loadContent();
  },
  { flush: 'sync' },
);

onBeforeUnmount(() => {
  ++loadVersion;
});

const copyUrl = (b: Backend): void => {
  if (b.urls?.webhook) {
    copyText(window.origin + b.urls.webhook);
  }

  webhookBackend.value = null;
};

const handleWebhookModalClose = (): void => {
  webhookBackend.value = null;
};

const addWebhook = async (b: Backend): Promise<void> => {
  if (undefined === b.urls?.webhook) {
    return;
  }

  const webhook = b.urls.webhook;

  webhookLoading.value = true;
  try {
    const webhookUrl = window.origin + webhook;
    const response = await request(`/backend/${b.name}/webhook`, {
      method: 'POST',
      body: JSON.stringify({ webhook_url: webhookUrl }),
    });

    const json = await parse_api_response<{ message: string }>(response);
    if ('error' in json) {
      notification('error', 'Webhook could not be configured', json.error.message);
      return;
    }

    notification('success', 'Webhook configured', `Configured for '${b.name}'.`);
    webhookBackend.value = null;
  } catch (e) {
    const error = e as Error;
    notification('error', 'Webhook could not be configured', error.message);
  } finally {
    webhookLoading.value = false;
  }
};

const openBackendEdit = (backendName: string): void => {
  editBackendName.value = backendName;
  backendEditDirty.value = false;
  editBackendOpen.value = true;
};

const handleBackendEdited = async (): Promise<void> => {
  backendEditDirty.value = false;
  editBackendOpen.value = false;
  editBackendName.value = '';

  await loadContent();
};

const updateValue = async (
  backend: Backend,
  key: 'import.enabled' | 'export.enabled',
  newValue: boolean,
): Promise<void> => {
  if (updatingBackends.value.includes(backend.name)) {
    return;
  }

  const user = api_user.value;
  updatingBackends.value.push(backend.name);

  try {
    const response = await request(`/backend/${backend.name}`, {
      method: 'PATCH',
      headers: { 'X-User': user },
      body: JSON.stringify([{ key, value: newValue }]),
    });
    const updatedBackend = await parse_api_response<Backend>(response);
    if ('error' in updatedBackend) {
      throw new Error(updatedBackend.error.message);
    }

    if (user !== api_user.value) {
      return;
    }

    const index = backends.value.findIndex((current) => current.name === backend.name);
    if (-1 !== index) {
      backends.value[index] = updatedBackend;
    }
  } catch (e) {
    notification(
      'error',
      `Could not save ${backend.name}`,
      e instanceof Error ? e.message : 'Try changing the setting again.',
    );
  } finally {
    updatingBackends.value = updatingBackends.value.filter((name) => name !== backend.name);
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
        notification('info', 'Backup queued', `Queued for '${backend.name}'.`, 5000);
      } catch (e) {
        const error = e as Error;
        notification('error', 'Backup not queued', error.message);
      }
      break;
    case 'forceExport':
      try {
        await queue_event(
          'run_console',
          {
            command: 'state:export',
            args: [
              '-fi',
              '--with-progress',
              '-v',
              '--user',
              api_user.value,
              '--select-backend',
              backend.name,
            ],
          },
          300,
        );

        notification('info', 'Force export queued', `Queued for '${backend.name}'.`, 5000);
      } catch (e) {
        const error = e as Error;
        notification('error', 'Force export not queued', error.message);
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

        notification('info', 'Force import queued', `Queued for '${backend.name}'.`, 5000);
      } catch (e) {
        const error = e as Error;
        notification('error', 'Force import not queued', error.message);
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
