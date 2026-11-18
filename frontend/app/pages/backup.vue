<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          :icon="queued ? 'i-lucide-x' : 'i-lucide-database-backup'"
          :loading="queueBusy"
          :disabled="isLoading || busy || null === queued"
          @click="queueTask"
        >
          {{ queued ? 'Cancel queued backup' : 'Queue backup' }}
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || busy"
          @click="loadContent"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <div class="flex flex-wrap items-end gap-3">
      <div class="w-full space-y-1.5 sm:w-44">
        <label for="backup-type" class="text-xs font-medium text-toned">Type</label>
        <USelect
          id="backup-type"
          v-model="selectedType"
          :items="typeItems"
          icon="i-lucide-filter"
          class="w-full"
        />
      </div>
      <div class="min-w-0 flex-1 basis-full space-y-1.5 sm:basis-64">
        <label for="backup-filter" class="text-xs font-medium text-toned">Find files</label>
        <UInput
          id="backup-filter"
          v-model="query"
          type="search"
          icon="i-lucide-search"
          placeholder="Filename"
          class="w-full"
        />
      </div>
    </div>
    <div
      v-if="query.trim() || '__all__' !== selectedType"
      class="flex flex-wrap items-center gap-2"
      aria-label="Active filters"
    >
      <span class="text-xs text-muted">Applied</span>
      <UButton
        v-if="'__all__' !== selectedType"
        color="neutral"
        variant="soft"
        size="xs"
        trailing-icon="i-lucide-x"
        aria-label="Remove type filter"
        @click="selectedType = '__all__'"
        >Type: {{ ucFirst(selectedType) }}</UButton
      >
      <UButton
        v-if="query.trim()"
        color="neutral"
        variant="soft"
        size="xs"
        trailing-icon="i-lucide-x"
        aria-label="Remove filename filter"
        class="max-w-full"
        @click="query = ''"
        ><span class="truncate">Filename: {{ query.trim() }}</span></UButton
      >
      <UButton color="neutral" variant="outline" size="xs" icon="i-lucide-x" @click="clearFilters">
        Clear all
      </UButton>
    </div>

    <section aria-label="Backup files" :aria-busy="isLoading" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3" aria-live="polite">
        <p v-if="hasResults" class="text-sm text-toned">
          <strong class="font-medium text-highlighted">{{ filteredItems.length }}</strong>
          {{ filteredItems.length === items.length ? '' : `of ${items.length} `
          }}{{ 1 === items.length ? 'file' : 'files' }}
        </p>
        <p v-if="isLoading && hasResults" role="status" class="text-xs text-muted">
          Refreshing backups…
        </p>
        <UBadge v-if="queued" color="neutral" variant="soft" icon="i-lucide-clock-3">
          Backup queued
        </UBadge>
      </div>

      <UAlert
        v-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="hasResults ? 'Backups could not be refreshed' : 'Backups could not be loaded'"
        :description="loadError"
      >
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            :disabled="isLoading || busy"
            @click="loadContent"
            >Retry</UButton
          >
        </template>
      </UAlert>
      <p v-if="loadError && hasResults" class="text-sm text-toned">
        These results may be out of date.
      </p>
      <UAlert
        v-if="queueError"
        color="warning"
        variant="soft"
        icon="i-lucide-clock-3"
        title="Backup queue status unavailable"
        :description="queueError"
      />

      <div
        v-if="isLoading && !hasResults"
        role="status"
        aria-label="Loading backups"
        class="ws-list-card"
      >
        <div v-for="index in 4" :key="index" class="flex items-center gap-4 px-4 py-4">
          <USkeleton class="size-9 shrink-0" />
          <USkeleton class="h-4 w-52 max-w-full" />
        </div>
      </div>
      <UEmpty
        v-if="!isLoading && !loadError && 0 === filteredItems.length"
        :icon="
          query.trim() || '__all__' !== selectedType
            ? 'i-lucide-search'
            : 'i-lucide-database-backup'
        "
        :title="
          query.trim() || '__all__' !== selectedType ? 'No matching backups' : 'No backups yet'
        "
        :description="
          query.trim() || '__all__' !== selectedType
            ? 'Try another filename or backup type.'
            : 'Queue a backup to save play state from your configured backends.'
        "
      >
        <template v-if="query.trim() || '__all__' !== selectedType" #actions>
          <UButton color="neutral" variant="outline" icon="i-lucide-x" @click="clearFilters">
            Clear all
          </UButton>
        </template>
      </UEmpty>

      <ul v-if="0 < filteredItems.length" class="ws-list-card overflow-hidden">
        <li
          v-for="item in filteredItems"
          :key="item.filename"
          class="grid min-w-0 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center xl:grid-cols-[minmax(0,1fr)_7rem_7rem_11rem_auto]"
        >
          <div class="flex min-w-0 items-center gap-3">
            <span class="ws-detail-icon"><UIcon name="i-lucide-download" class="size-4" /></span>
            <button
              type="button"
              class="min-w-0 wrap-anywhere text-left text-sm font-semibold text-highlighted hover:text-primary focus-visible:outline-primary disabled:opacity-50"
              :aria-label="`Download ${item.filename}`"
              :disabled="downloadingFiles.includes(item.filename) || item.filename === deletingFile"
              @click="downloadFile(item)"
            >
              {{ item.filename }}
              <UIcon
                v-if="downloadingFiles.includes(item.filename)"
                name="i-lucide-loader-circle"
                class="ml-1 inline size-3.5 animate-spin"
              />
            </button>
          </div>
          <div>
            <UBadge color="neutral" variant="soft">{{ ucFirst(item.type) }}</UBadge>
          </div>
          <p class="text-xs tabular-nums text-toned">
            <span class="sr-only">Size </span>{{ humanFileSize(item.size) }}
          </p>
          <div class="text-xs text-toned">
            <span class="text-muted">Updated </span>
            <UTooltip :text="moment(item.date).format(TOOLTIP_DATE_FORMAT)">
              <time :datetime="moment(item.date).toISOString()" class="cursor-help">{{
                moment(item.date).fromNow()
              }}</time>
            </UTooltip>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-cloud-upload"
              :disabled="isLoading || busy || !!loadError"
              @click="openRestore(item)"
              >Prepare restore</UButton
            >
            <UTooltip text="Delete backup file">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                :aria-label="`Delete ${item.filename}`"
                :disabled="
                  isLoading || busy || !!loadError || downloadingFiles.includes(item.filename)
                "
                @click="deleteFile(item)"
              >
                Delete
              </UButton>
            </UTooltip>
          </div>
        </li>
      </ul>
    </section>

    <details class="ws-panel-padded text-sm">
      <summary class="cursor-pointer font-medium text-highlighted">Backup options</summary>
      <div class="mt-3 space-y-2 text-toned">
        <p>Scheduled cleanup can remove automatic backups. Download a copy you want to keep.</p>
        <p>
          For a manual backup, choose operation 4 in
          <NuxtLink to="/backends" class="text-primary hover:underline">Backends</NuxtLink>. Manage
          automatic backups in
          <NuxtLink to="/tasks" class="text-primary hover:underline">Tasks</NuxtLink>.
        </p>
        <p>
          Restoring a file changes play state on the selected media server, not WatchState settings.
        </p>
      </div>
    </details>

    <UModal v-model:open="restoreOpen" title="Prepare restore" :ui="{ content: 'max-w-2xl' }">
      <template #body>
        <div v-if="restoreFile" class="space-y-5">
          <div class="space-y-1">
            <p class="text-xs text-muted">Backup file</p>
            <p class="wrap-anywhere text-sm font-semibold text-highlighted">
              {{ restoreFile.filename }}
            </p>
          </div>
          <UAlert
            color="warning"
            variant="soft"
            icon="i-lucide-triangle-alert"
            title="Restore to a media server"
            description="Running the prepared command changes play state on the selected backend."
          />
          <p class="text-sm text-toned">
            If Import is enabled, later syncs can send the restored state to other backends for that
            identity.
          </p>
          <UAlert
            v-if="targetsError"
            color="error"
            variant="soft"
            icon="i-lucide-circle-alert"
            title="Restore targets unavailable"
            :description="targetsError"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                icon="i-lucide-refresh-cw"
                :disabled="isLoading"
                @click="loadContent"
              >
                Retry
              </UButton>
            </template>
          </UAlert>
          <UEmpty
            v-if="!isLoading && !targetsError && !hasRestoreTargets"
            size="sm"
            variant="naked"
            icon="i-lucide-server"
            title="No restore targets"
            description="Add a backend to an identity before preparing a restore."
          />
          <UFormField label="Restore to" name="restore_target">
            <USelectMenu
              v-model="selectedTarget"
              :items="restoreTargetItems"
              value-key="value"
              placeholder="Choose identity & backend"
              :disabled="isLoading || !!targetsError || !hasRestoreTargets"
              class="w-full"
            />
          </UFormField>
          <p v-if="selectedTarget" class="text-sm text-toned">
            Target: <strong class="text-highlighted">{{ selectedTarget }}</strong>
          </p>
          <p class="text-sm text-toned">Nothing is restored until you run the command.</p>
          <p v-if="restoreError" role="alert" class="text-sm text-error">{{ restoreError }}</p>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="primary"
            variant="solid"
            icon="i-lucide-terminal"
            :disabled="
              !restoreFile || !selectedTarget || isLoading || !!targetsError || !!loadError
            "
            @click="prepareRestore"
            >Continue to Console</UButton
          >
        </div>
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  UModal,
  USelect,
  USelectMenu,
  USkeleton,
  UTooltip,
} from '#components';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import type {
  BackupItem,
  BackupRestoreTargetItem,
  GenericResponse,
  IdentityBackends,
} from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  humanFileSize,
  makeConsoleCommand,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';

useHead({ title: 'Backups' });

const pageShell = requireTopLevelPageShell('backup');
const route = useRoute();
const dialog = useDialog();
const items = ref<Array<BackupItem>>([]);
const identities = ref<Array<IdentityBackends>>([]);
const query = ref('string' === typeof route.query.filter ? route.query.filter : '');
const selectedType = ref('__all__');
const isLoading = ref(true);
const hasResults = ref(false);
const loadError = ref('');
const queueError = ref('');
const targetsError = ref('');
const queued = ref<boolean | null>(null);
const queueBusy = ref(false);
const deletingFile = ref<string | null>(null);
const downloadingFiles = ref<Array<string>>([]);
const restoreOpen = ref(false);
const restoreFilename = ref('');
const selectedTarget = ref('');
const restoreError = ref('');
const busy = computed(() => queueBusy.value || null !== deletingFile.value);
let loadVersion = 0;
let disposed = false;

const typeItems = computed(() => [
  { label: 'All types', value: '__all__' },
  ...Array.from(
    new Set([
      ...items.value.map((item) => item.type),
      ...('__all__' !== selectedType.value ? [selectedType.value] : []),
    ]),
  )
    .sort()
    .map((type) => ({ label: ucFirst(type), value: type })),
]);

const filteredItems = computed(() => {
  const search = query.value.trim().toLowerCase();
  return items.value.filter(
    (item) =>
      ('__all__' === selectedType.value || selectedType.value === item.type) &&
      item.filename.toLowerCase().includes(search),
  );
});

const restoreFile = computed(() =>
  items.value.find((item) => restoreFilename.value === item.filename),
);
const hasRestoreTargets = computed(() =>
  identities.value.some((identity) => 0 < identity.backends.length),
);
const restoreTargetItems = computed<Array<Array<BackupRestoreTargetItem>>>(() =>
  identities.value.map((identity) => [
    { label: `Identity: ${identity.identity}`, type: 'label' as const },
    ...identity.backends.map((backend) => ({
      label: backend,
      type: 'item' as const,
      value: `${identity.identity}@${backend}`,
    })),
  ]),
);

const clearFilters = (): void => {
  query.value = '';
  selectedType.value = '__all__';
};

const loadContent = async (): Promise<void> => {
  if (disposed || busy.value) {
    return;
  }

  const version = ++loadVersion;
  isLoading.value = true;
  loadError.value = '';
  queueError.value = '';
  targetsError.value = '';

  const results = await Promise.allSettled([
    request('/system/backup').then((response) => parse_api_response<Array<BackupItem>>(response)),
    request('/tasks/backup').then((response) => parse_api_response<{ queued: boolean }>(response)),
    request('/identities').then((response) =>
      parse_api_response<{ identities: Array<IdentityBackends> }>(response),
    ),
  ]);
  if (disposed || version !== loadVersion) {
    return;
  }

  const [files, task, targets] = results;
  if ('rejected' === files.status) {
    loadError.value =
      files.reason instanceof Error ? files.reason.message : 'Could not load backup files.';
  } else if ('error' in files.value) {
    loadError.value = files.value.error.message;
  } else {
    items.value = files.value;
    hasResults.value = true;
  }

  if ('rejected' === task.status || 'error' in task.value) {
    queued.value = null;
    queueError.value = 'Refresh to check whether a backup is queued.';
  } else {
    queued.value = task.value.queued;
  }

  if ('rejected' === targets.status || 'error' in targets.value) {
    identities.value = [];
    targetsError.value = 'Refresh to load the available identities & backends.';
  } else {
    identities.value = targets.value.identities;
  }

  isLoading.value = false;
};

const downloadFile = async (item: BackupItem): Promise<void> => {
  if (disposed || downloadingFiles.value.includes(item.filename)) {
    return;
  }

  downloadingFiles.value.push(item.filename);
  const pickerWindow = window as Window & {
    showSaveFilePicker?: (options: { suggestedName: string }) => Promise<FileSystemFileHandle>;
  };

  try {
    const handle = pickerWindow.showSaveFilePicker
      ? await pickerWindow.showSaveFilePicker({ suggestedName: item.filename })
      : null;
    if (disposed) {
      return;
    }

    const response = await request(`/system/backup/${encodeURIComponent(item.filename)}`);
    if (!response.ok) {
      const json = await parse_api_response<GenericResponse>(response);
      throw new Error(
        'error' in json ? json.error.message : `${response.status}: ${response.statusText}`,
      );
    }
    if (disposed) {
      return;
    }

    if (handle) {
      if (!response.body) {
        throw new Error('The backup download was empty.');
      }
      await response.body.pipeTo(await handle.createWritable());
      return;
    }

    const blob = await response.blob();
    if (disposed) {
      return;
    }

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = item.filename;
    link.click();
    URL.revokeObjectURL(url);
  } catch (error) {
    if (!disposed && !(error instanceof Error && 'AbortError' === error.name)) {
      notification(
        'error',
        'Backup could not be downloaded',
        error instanceof Error ? error.message : 'Try again.',
      );
    }
  } finally {
    downloadingFiles.value = downloadingFiles.value.filter(
      (filename) => filename !== item.filename,
    );
  }
};

const queueTask = async (): Promise<void> => {
  if (disposed || isLoading.value || busy.value || null === queued.value) {
    return;
  }

  const version = loadVersion;
  queueBusy.value = true;
  try {
    const response = await request('/tasks/backup');
    const task = await parse_api_response<{ queued: boolean }>(response);
    if ('error' in task) {
      throw new Error(task.error.message);
    }
    if (!response.ok) {
      throw new Error(`${response.status}: ${response.statusText}`);
    }
    if (disposed || version !== loadVersion) {
      return;
    }

    queued.value = task.queued;
    const wasQueued = task.queued;
    const { status } = await dialog.confirmDialog({
      title: wasQueued ? 'Cancel queued backup?' : 'Queue backup?',
      message: wasQueued
        ? 'Cancel the pending backup task? This does not stop a running backup.'
        : 'Queue a backup using the task settings for all identities?',
      confirmText: wasQueued ? 'Cancel queued backup' : 'Queue backup',
      confirmColor: 'neutral',
    });
    if (true !== status || disposed || version !== loadVersion) {
      return;
    }

    const result = await request('/tasks/backup/queue', { method: wasQueued ? 'DELETE' : 'POST' });
    if (!result.ok) {
      const json = await parse_api_response<GenericResponse>(result);
      throw new Error(
        'error' in json ? json.error.message : `${result.status}: ${result.statusText}`,
      );
    }
    if (!disposed) {
      queued.value = !wasQueued;
      notification(
        'success',
        wasQueued ? 'Queued backup cancelled' : 'Backup queued',
        'Check Tasks & Events for the backup outcome.',
      );
    }
  } catch (error) {
    if (!disposed) {
      notification(
        'error',
        'Backup queue could not be changed',
        error instanceof Error ? error.message : 'Refresh and try again.',
      );
    }
  } finally {
    queueBusy.value = false;
  }
};

const deleteFile = async (item: BackupItem): Promise<void> => {
  if (
    disposed ||
    isLoading.value ||
    busy.value ||
    loadError.value ||
    downloadingFiles.value.includes(item.filename)
  ) {
    return;
  }

  const version = loadVersion;
  deletingFile.value = item.filename;
  try {
    const { status } = await dialog.confirmDialog({
      title: 'Delete backup?',
      message: `Delete '${item.filename}'? This file cannot be recovered from WatchState after deletion.`,
      confirmText: 'Delete',
      confirmColor: 'error',
    });
    if (true !== status || disposed || version !== loadVersion || !items.value.includes(item)) {
      return;
    }

    const response = await request(`/system/backup/${encodeURIComponent(item.filename)}`, {
      method: 'DELETE',
    });
    if (!response.ok) {
      const json = await parse_api_response<GenericResponse>(response);
      throw new Error(
        'error' in json ? json.error.message : `${response.status}: ${response.statusText}`,
      );
    }
    if (!disposed) {
      items.value = items.value.filter((current) => current.filename !== item.filename);
      notification('success', 'Backup deleted', item.filename);
    }
  } catch (error) {
    if (!disposed) {
      notification(
        'error',
        'Backup could not be deleted',
        error instanceof Error ? error.message : 'Try again.',
      );
    }
  } finally {
    deletingFile.value = null;
  }
};

const openRestore = (item: BackupItem): void => {
  restoreFilename.value = item.filename;
  selectedTarget.value = '';
  restoreError.value = '';
  restoreOpen.value = true;
};

const prepareRestore = async (): Promise<void> => {
  if (
    disposed ||
    !restoreOpen.value ||
    !restoreFile.value ||
    isLoading.value ||
    loadError.value ||
    targetsError.value
  ) {
    return;
  }

  const [identity, backend] = selectedTarget.value.split('@');
  if (
    !identity ||
    !backend ||
    !identities.value.some((item) => identity === item.identity && item.backends.includes(backend))
  ) {
    return;
  }

  const quote = (value: string): string => `'${value.replaceAll("'", "'\\''")}'`;
  const command = `backend:restore --assume-yes --execute -v --user ${quote(identity)} --select-backend ${quote(backend)} -- ${quote(restoreFile.value.filename)}`;
  try {
    await navigateTo(makeConsoleCommand(command));
  } catch {
    restoreError.value =
      'The restore command could not be prepared. Use Console or the CLI to enter it directly.';
  }
};

watch(restoreOpen, (open) => {
  if (!open) {
    restoreFilename.value = '';
    selectedTarget.value = '';
    restoreError.value = '';
  }
});

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  disposed = true;
  ++loadVersion;
});
</script>
