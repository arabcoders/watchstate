<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-sd-card"></i></span>
          Backups
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button
                class="button is-primary"
                @click="queueTask"
                :disabled="isLoading"
                :class="{ 'is-loading': isLoading, 'is-primary': !queued, 'is-danger': queued }"
              >
                <span class="icon"><i class="fas fa-sd-card"></i></span>
                <span>{{ !queued ? 'Queue backup' : 'Remove from queue' }}</span>
              </button>
            </p>

            <div class="control has-icons-left" v-if="toggleFilter || query">
              <input
                type="search"
                v-model.lazy="query"
                class="input"
                id="filter"
                placeholder="Filter displayed content"
              />
              <span class="icon is-left"><i class="fas fa-filter" /></span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter = !toggleFilter">
                <span class="icon"><i class="fas fa-filter" /></span>
              </button>
            </div>

            <p class="control">
              <button
                class="button is-info"
                @click="loadContent"
                :disabled="isLoading"
                :class="{ 'is-loading': isLoading }"
              >
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page contains all of your manually generated and automatic backups.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="filteredItems.length < 1 || isLoading">
        <Message
          v-if="isLoading"
          message_class="is-background-info-90 has-text-dark"
          icon="fas fa-spinner fa-spin"
          title="Loading"
          message="Loading data. Please wait..."
        />
        <Message
          v-else
          :title="query ? 'Search results' : 'Warning'"
          message_class="is-background-warning-80 has-text-dark"
          icon="fas fa-exclamation-triangle"
        >
          <span v-if="query"
            >No results found for <strong>{{ query }}</strong></span
          >
          <span v-else>No backups found.</span>
        </Message>
      </div>

      <div
        class="column is-6-tablet"
        v-for="(item, index) in filteredItems"
        :key="'backup-' + index"
      >
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-text-overflow pr-1">
              <span class="icon"
                ><i
                  class="fas fa-download"
                  :class="{ 'fa-spin': item?.isDownloading }"
                />&nbsp;</span
              >
              <span>
                <NuxtLink @click="downloadFile(item)">{{ item.filename }}</NuxtLink>
              </span>
            </p>
            <span class="card-header-icon">
              <NuxtLink
                @click="deleteFile(item)"
                class="has-text-danger"
                v-tooltip="'Delete this backup file.'"
              >
                <span class="icon"><i class="fas fa-trash"></i></span>
              </NuxtLink>
            </span>
          </header>
          <div class="card-content">
            <div class="field is-grouped">
              <div class="control is-expanded">
                <div class="select is-fullwidth">
                  <select v-model="item.selected" class="is-capitalized" required>
                    <option value="" selected disabled>Restore To...</option>
                    <template v-for="user in users" :key="user.user">
                      <optgroup :label="`User: ${user.user}`">
                        <option
                          v-for="backend in user.backends"
                          :key="`${user.user}@${backend}`"
                          :value="`${user.user}@${backend}`"
                        >
                          {{ backend }}
                        </option>
                      </optgroup>
                    </template>
                  </select>
                </div>
              </div>
              <div class="control">
                <button
                  class="button is-primary"
                  :disabled="'' === item.selected"
                  @click="generateCommand(item)"
                >
                  Go
                </button>
              </div>
            </div>
          </div>
          <div class="card-footer">
            <div class="card-footer-item">
              <div class="is-ellipsis">
                <span class="icon"><i class="fas fa-calendar" /></span>
                <span
                  class="has-tooltip"
                  v-tooltip="`Last Update: ${moment(item.date).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  {{ moment(item.date).fromNow() }}
                </span>
              </div>
            </div>
            <div class="card-footer-item">
              <span class="icon"><i class="fas fa-hdd"></i>&nbsp;</span>
              <span>{{ humanFileSize(item.size) }}</span>
            </div>
            <div class="card-footer-item">
              <span class="icon"><i class="fas fa-tag"></i>&nbsp;</span>
              <span class="is-capitalized">{{ item.type }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <Message
          message_class="has-background-info-90 has-text-dark"
          :toggle="show_page_tips"
          @toggle="show_page_tips = !show_page_tips"
          :use-toggle="true"
          title="Tips"
          icon="fas fa-info-circle"
        >
          <ul>
            <li>
              Backups that are tagged <code>Automatic</code> are subject to auto deletion after
              <code>90</code> days from the date of creation.
            </li>
            <li>
              You can trigger a backup task to run in the background by clicking the
              <code
                ><span class="icon"><i class="fas fa-sd-card"></i></span> Queue backup</code
              >
              button. on top right. Those backups will be tagged as <code>Automatic</code>.
            </li>
            <li>
              To generate a manual backup, go to the
              <NuxtLink to="/backends"
                ><span class="icon"><i class="fas fa-server"></i></span> Backends</NuxtLink
              >
              page and from the drop down menu select the 4th option
              <code>Backup this backend play state</code>, or via cli using
              <code>state:backup</code> command from the console. or by
              <span class="icon"><i class="fas fa-terminal" /></span>
              <NuxtLink
                :to="makeConsoleCommand('state:backup -s [backend] --file /config/backup/[file]')"
              >
                Web Console
              </NuxtLink>
              page.
            </li>
            <li>
              The restore process will take you to
              <span class="icon"><i class="fas fa-terminal" /></span>
              <NuxtLink to="/console">Web Console</NuxtLink>
              and pre-fill the command for you to run.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import {
  humanFileSize,
  makeConsoleCommand,
  notification,
  parse_api_response,
  request,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import { useDialog } from '~/composables/useDialog';
import Message from '~/components/Message.vue';
import type { BackupItem, GenericResponse, UILoadingState, UserBackends } from '~/types';

type BackItemWithUI = BackupItem &
  UILoadingState & {
    /** Currently selected restore target in format 'user@backend' */
    selected: string;
    /** Whether the file is currently being downloaded */
    isDownloading?: boolean;
  };

const route = useRoute();

useHead({ title: 'Backups' });

const items = ref<Array<BackItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const queued = ref<boolean>(true);
const show_page_tips = useStorage('show_page_tips', true);
const users = ref<Array<UserBackends>>([]);
const query = ref<string>((route.query.filter as string) ?? '');
const toggleFilter = ref<boolean>(false);

type FilePickerOptions = {
  suggestedName?: string;
};

type FilePickerHandle = {
  createWritable: () => Promise<WritableStream>;
};

watch(toggleFilter, (): void => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

const filteredItems = computed((): Array<BackItemWithUI> => {
  if (!query.value) {
    return items.value;
  }
  return items.value.filter((item: BackItemWithUI): boolean =>
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

    json.forEach((element) => items.value.push({ ...element, selected: '', isDownloading: false }));

    if ('backup' !== useRoute().name) {
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
  if (true === item?.isDownloading) {
    return;
  }
  const filename: string = item.filename;
  item.isDownloading = true;

  const response = request(`/system/backup/${filename}`);
  const pickerWindow = window as Window & {
    showSaveFilePicker?: (options: FilePickerOptions) => Promise<FilePickerHandle>;
  };
  const showSaveFilePicker = pickerWindow.showSaveFilePicker;

  if (showSaveFilePicker) {
    response.then(async (res): Promise<void> => {
      item.isDownloading = false;

      if (!res.body) {
        notification('error', 'Error', 'No data returned from backup download request.');
        return;
      }

      const handle = await showSaveFilePicker({
        suggestedName: `${filename}`,
      });
      await res.body.pipeTo(await handle.createWritable());
    });
  } else {
    response
      .then((res): Promise<Blob> => res.blob())
      .then((blob): void => {
        const fileURL: string = URL.createObjectURL(blob);
        const fileLink: HTMLAnchorElement = document.createElement('a');
        fileLink.href = fileURL;
        fileLink.download = `${filename}`;
        fileLink.click();
        item.isDownloading = false;
      });
  }
};

const queueTask = async (): Promise<void> => {
  const is_queued: boolean = await isQueued();
  const message: string = is_queued
    ? 'Remove backup task from queue?'
    : 'Queue backup task to run in background?';

  const { status } = await useDialog().confirmDialog({
    title: 'Confirm',
    message,
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/tasks/backup/queue`, {
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
  const { status } = await useDialog().confirmDialog({
    title: 'Delete backup',
    message: `Delete backup file '${item.filename}'?`,
    confirmColor: 'is-danger',
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/system/backup/${item.filename}`, { method: 'DELETE' });

    if (200 === response.status) {
      notification('success', 'Success', `Backup file '${item.filename}' has been deleted.`);
      items.value = items.value.filter((i: BackupItem): boolean => i.filename !== item.filename);
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
  const response = await request('/system/users');
  const usersData = await parse_api_response<{ users: Array<UserBackends> }>(response);
  if ('error' in usersData) {
    notification('error', 'Error', `Failed to load users. ${usersData.error.message}`);
  } else {
    users.value = usersData.users;
  }
  await loadContent();
});

const generateCommand = async (item: BackItemWithUI): Promise<void> => {
  const selected: Array<string> = item.selected.split('@');
  const user: string = selected[0] || '';
  const backend: string = selected[1] || '';
  const file: string = item.filename;

  const { status } = await useDialog().confirmDialog({
    title: 'Confirm restore',
    message: `Are you sure you want to restore '${user}@${backend}' using '${file}'?`,
    confirmColor: 'is-danger',
  });

  if (true !== status) {
    return;
  }

  await navigateTo(
    makeConsoleCommand(
      `backend:restore --assume-yes --execute -v --user '${user}' --select-backend '${backend}' -- '${file}'`,
    ),
  );
};
</script>
