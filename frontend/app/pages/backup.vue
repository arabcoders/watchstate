<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          :variant="queued ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-database-backup"
          :loading="isLoading"
          :disabled="isLoading"
          @click="queueTask"
        >
          {{ queued ? 'Remove from queue' : 'Queue backup' }}
        </UButton>

        <UInput
          v-if="toggleFilter || query"
          id="filter"
          v-model="query"
          type="search"
          placeholder="Filter displayed content"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <UTooltip text="Filter backups.">
          <UButton
            color="neutral"
            :variant="toggleFilter ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-filter"
            @click="toggleFilter = !toggleFilter"
          >
            <span class="hidden sm:inline">Filter</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Reload backups">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
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
      v-else-if="filteredItems.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      :title="query ? 'Search results' : 'No backups found'"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p v-if="query">
            No results found for <strong>{{ query }}</strong
            >.
          </p>
          <p v-else>No backups found.</p>
        </div>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="item in filteredItems"
        :key="item.filename"
        class="h-full shadow-sm"
        :ui="backupCardUi"
      >
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <button
                type="button"
                class="flex w-full min-w-0 items-center gap-2 text-left font-semibold text-primary"
                @click="downloadFile(item)"
              >
                <UIcon
                  name="i-lucide-download"
                  :class="['size-4 shrink-0 text-toned', item.isDownloading ? 'animate-spin' : '']"
                />
                <span class="block min-w-0 flex-1 truncate">{{ item.filename }}</span>
              </button>
            </div>

            <UTooltip text="Delete this backup file.">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                aria-label="Delete backup"
                @click="deleteFile(item)"
              >
                <span class="hidden sm:inline">Delete</span>
              </UButton>
            </UTooltip>
          </div>
        </template>

        <div class="space-y-4">
          <div class="flex gap-2 flex-row">
            <USelectMenu
              v-model="item.selected"
              :items="restoreTargetItems"
              value-key="value"
              placeholder="Restore to..."
              :ui="{ item: 'pl-6' }"
              class="flex-1"
            />

            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              class="justify-center"
              icon="i-lucide-cloud-upload"
              :disabled="'' === item.selected"
              @click="generateCommand(item)"
            >
              Restore
            </UButton>
          </div>
        </div>

        <template #footer>
          <div class="grid grid-cols-2 gap-2.5 xl:grid-cols-3">
            <div
              class="col-span-2 flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default xl:col-span-1"
            >
              <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
              <UTooltip :text="`Last Update: ${moment(item.date).format(TOOLTIP_DATE_FORMAT)}`">
                <span class="cursor-help">{{ moment(item.date).fromNow() }}</span>
              </UTooltip>
            </div>

            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-hard-drive" class="size-4 shrink-0 text-toned" />
              <span>{{ humanFileSize(item.size) }}</span>
            </div>

            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-tag" class="size-4 shrink-0 text-toned" />
              <span class="capitalize">{{ item.type }}</span>
            </div>
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
          Backups that are tagged <code>Automatic</code> are subject to auto deletion after
          <code>90</code> days from the date of creation.
        </li>
        <li>
          You can trigger a backup task to run in the background by clicking the
          <code>Queue backup</code> button on the top right. Those backups will be tagged as
          <code>Automatic</code>.
        </li>
        <li>
          To generate a manual backup, go to the
          <NuxtLink to="/backends" class="text-primary hover:underline">Backends</NuxtLink> page and
          from the drop-down menu select the 4th option <code>Backup this backend play state</code>,
          or via CLI using <code>state:backup</code> command from the console, or by
          <NuxtLink
            :to="makeConsoleCommand('state:backup -s [backend] --file /config/backup/[file]')"
            class="text-primary hover:underline"
            >Web Console</NuxtLink
          >
          page.
        </li>
        <li>
          The restore process will take you to
          <NuxtLink to="/console" class="text-primary hover:underline">Web Console</NuxtLink>
          and pre-fill the command for you to run.
        </li>
      </ul>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import type { BackupItem, GenericResponse, UILoadingState, IdentityBackends } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  humanFileSize,
  makeConsoleCommand,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';

type BackItemWithUI = BackupItem &
  UILoadingState & {
    selected: string;
    isDownloading?: boolean;
  };

type RestoreTargetItem = {
  label: string;
  value?: string;
  type?: 'label' | 'item';
  disabled?: boolean;
};

type FilePickerOptions = {
  suggestedName?: string;
};

type FilePickerHandle = {
  createWritable: () => Promise<WritableStream>;
};

const route = useRoute();

useHead({ title: 'Backups' });

const pageShell = requireTopLevelPageShell('backup');

const items = ref<Array<BackItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const queued = ref<boolean>(false);
const show_page_tips = useStorage('show_page_tips', true);
const identities = ref<Array<IdentityBackends>>([]);
const query = ref<string>((route.query.filter as string) ?? '');
const toggleFilter = ref<boolean>(false);
const dialog = useDialog();

const backupCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const restoreTargetItems = computed<Array<Array<RestoreTargetItem>>>(() =>
  identities.value.map((identity) => [
    { label: `Identity: ${identity.identity}`, type: 'label' },
    ...(identity.backends.length > 0
      ? identity.backends.map((backend) => ({
          label: backend,
          value: `${identity.identity}@${backend}`,
          type: 'item' as const,
        }))
      : [{ label: 'No backends', type: 'item' as const, disabled: true }]),
  ]),
);

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

const filteredItems = computed<Array<BackItemWithUI>>(() => {
  if (!query.value) {
    return items.value;
  }

  return items.value.filter((item) =>
    item.filename.toLowerCase().includes(query.value.toLowerCase()),
  );
});

const loadContent = async (): Promise<void> => {
  items.value = [];
  isLoading.value = true;

  try {
    const response = await request('/system/backup');
    const json = await parse_api_response<Array<BackupItem>>(response);

    if ('error' in json) {
      notification('error', 'Error', `API error. ${json.error.code}: ${json.error.message}`);
      return;
    }

    items.value = json.map((element) => ({
      ...element,
      selected: '',
      isDownloading: false,
    }));

    if ('backup' !== route.name) {
      return;
    }

    queued.value = await isQueued();
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

const downloadFile = async (item: BackItemWithUI): Promise<void> => {
  if (true === item.isDownloading) {
    return;
  }

  item.isDownloading = true;
  const pickerWindow = window as Window & {
    showSaveFilePicker?: (options: FilePickerOptions) => Promise<FilePickerHandle>;
  };

  try {
    const response = await request(`/system/backup/${item.filename}`);

    if (pickerWindow.showSaveFilePicker) {
      if (!response.body) {
        notification('error', 'Error', 'No data returned from backup download request.');
        return;
      }

      const handle = await pickerWindow.showSaveFilePicker({
        suggestedName: item.filename,
      });

      await response.body.pipeTo(await handle.createWritable());
      return;
    }

    const blob = await response.blob();
    const fileURL = URL.createObjectURL(blob);
    const fileLink = document.createElement('a');
    fileLink.href = fileURL;
    fileLink.download = item.filename;
    fileLink.click();
    URL.revokeObjectURL(fileURL);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Failed to download backup. ${message}`);
  } finally {
    item.isDownloading = false;
  }
};

const queueTask = async (): Promise<void> => {
  const is_queued = await isQueued();
  const message = is_queued
    ? 'Remove backup task from queue?'
    : 'Queue backup task to run in background?';

  const { status } = await dialog.confirmDialog({
    title: 'Confirm',
    message,
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request('/tasks/backup/queue', {
      method: is_queued ? 'DELETE' : 'POST',
    });

    if (response.ok) {
      notification(
        'success',
        'Success',
        `Task backup has been ${is_queued ? 'removed from the queue' : 'queued'}.`,
      );
      queued.value = !is_queued;
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`);
  }
};

const deleteFile = async (item: BackupItem): Promise<void> => {
  const { status } = await dialog.confirmDialog({
    title: 'Delete backup',
    message: `Delete backup file '${item.filename}'?`,
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/system/backup/${item.filename}`, { method: 'DELETE' });

    if (200 === response.status) {
      notification('success', 'Success', `Backup file '${item.filename}' has been deleted.`);
      items.value = items.value.filter((currentItem) => currentItem.filename !== item.filename);
      return;
    }

    const json = await parse_api_response<GenericResponse>(response);

    if ('error' in json) {
      notification('error', 'Error', `API error. ${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('error', 'Error', `API error. ${response.statusText}`);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`);
  }
};

const isQueued = async (): Promise<boolean> => {
  const response = await request('/tasks/backup');
  const json = await parse_api_response<{ queued: boolean }>(response);

  if ('error' in json) {
    return false;
  }

  return Boolean(json.queued);
};

onMounted(async (): Promise<void> => {
  const response = await request('/identities');
  const identitiesData = await parse_api_response<{ identities: Array<IdentityBackends> }>(
    response,
  );

  if ('error' in identitiesData) {
    notification('error', 'Error', `Failed to load identities. ${identitiesData.error.message}`);
  } else {
    identities.value = identitiesData.identities;
  }

  await loadContent();
});

const generateCommand = async (item: BackItemWithUI): Promise<void> => {
  const selected = item.selected.split('@');
  const identity = selected[0] || '';
  const backend = selected[1] || '';
  const file = item.filename;

  const { status } = await dialog.confirmDialog({
    title: 'Confirm restore',
    message: `Are you sure you want to restore '${identity}@${backend}' using '${file}'?`,
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  await navigateTo(
    makeConsoleCommand(
      `backend:restore --assume-yes --execute -v --user '${identity}' --select-backend '${backend}' -- '${file}'`,
    ),
  );
};
</script>
