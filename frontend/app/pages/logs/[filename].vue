<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0 space-y-2">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" :class="['size-4', stream ? 'animate-spin' : '']" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <NuxtLink to="/logs" class="text-toned hover:text-primary">{{
            pageShell.pageLabel
          }}</NuxtLink>
          <span>/</span>
          <span class="truncate text-highlighted normal-case tracking-normal">{{ filename }}</span>
        </div>
      </div>

      <div v-if="!error" class="flex flex-wrap items-center justify-end gap-2">
        <UTooltip v-if="'log' === contentType && !autoScroll" text="Go to bottom">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-chevron-down"
            @click="scrollToBottom"
          >
            <span class="hidden sm:inline">Bottom</span>
          </UButton>
        </UTooltip>

        <UInput
          v-if="'json' !== contentType && (toggleFilter || query)"
          id="filter"
          v-model="query"
          type="search"
          placeholder="Filter"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <UTooltip v-if="'json' !== contentType" text="Filter log lines.">
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

        <UTooltip text="Delete logfile.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            @click="deleteFile"
          >
            <span class="hidden sm:inline">Delete</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Download file.">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-download"
            :loading="isDownloading"
            @click="downloadFile"
          >
            <span class="hidden sm:inline">Download</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Toggle wrap line">
          <UButton
            color="neutral"
            :variant="wrapLines ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-wrap-text"
            @click="wrapLines = !wrapLines"
          >
            <span class="hidden sm:inline">Wrap</span>
          </UButton>
        </UTooltip>

        <UTooltip text="Copy text">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            @click="copyData"
          >
            <span class="hidden sm:inline">Copy</span>
          </UButton>
        </UTooltip>
      </div>
    </div>

    <UAlert
      v-if="error"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="API Error"
      close
      @update:open="(open) => (!open ? router.push('/logs') : undefined)"
    >
      <template #description>
        <p class="text-sm text-default">{{ error }}</p>
      </template>
    </UAlert>

    <UAlert
      v-else-if="isLoading && 0 === data.length"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-else-if="!error">
      <UAlert
        v-if="'log' === contentType && reachedEnd && !query"
        color="info"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="End of file"
        description="No more logs available for this file."
      />

      <UAlert
        v-if="'log' === contentType && 0 === filterItems.length"
        :color="query ? 'warning' : 'info'"
        variant="soft"
        :icon="query ? 'i-lucide-filter' : 'i-lucide-triangle-alert'"
        :title="query ? 'No matching logs' : 'No logs available'"
      >
        <template #description>
          <p class="text-sm text-default">
            <template v-if="query"
              >No logs match this query: <u>{{ query }}</u></template
            >
            <template v-else>No logs available.</template>
          </p>
        </template>
      </UAlert>

      <UCard
        v-if="'json' === contentType || 0 < filterItems.length"
        class="overflow-hidden border border-default/70 shadow-sm"
        :ui="viewerCardUi"
      >
        <div v-if="'json' === contentType" ref="logContainer" class="logbox">
          <code
            id="logView"
            class="logline block"
            :class="wrapLines ? 'whitespace-pre-wrap ws-wrap-anywhere' : 'whitespace-pre'"
          >
            {{ renderJson(data) }}
          </code>
        </div>

        <div v-else ref="logContainer" class="logbox" @scroll.passive="handleScroll">
          <code id="logView" class="logline block">
            <span
              v-for="item in filterItems"
              :key="item.id"
              :class="['log-entry block', wrapLines ? '' : 'whitespace-nowrap']"
              ><span
                v-if="item.date || hasLinks(item)"
                class="mr-[1ch] inline-flex items-baseline whitespace-normal"
              >
                <template v-if="item.date"
                  >[<span class="cursor-help" :title="item.date">{{ formatDate(item.date) }}</span
                  >]</template
                >
                <span v-if="hasLinks(item)" :class="item.date ? 'ml-[1ch]' : ''">
                  <LogLineLinks :item="item" :open-event="openEvent" />
                </span>
              </span>
              <span
                :class="wrapLines ? 'whitespace-pre-wrap ws-wrap-anywhere' : 'whitespace-pre'"
                >{{ String(item.text).trimStart() }}</span
              ></span
            >
          </code>
        </div>
      </UCard>

      <UModal v-model:open="eventViewOpen" :title="eventViewTitle" :ui="eventViewModalUi">
        <template #body>
          <EventView v-if="selectedEventId" :id="selectedEventId" />
        </template>
      </UModal>
    </template>
  </main>
</template>

<style scoped>
#logView {
  min-height: 72vh;
  min-width: inherit;
  max-width: 100%;
}

.log-entry:nth-child(even) {
  color: #ffc9d4;
}

.log-entry:nth-child(odd) {
  color: #e3c981;
}

code {
  background-color: unset;
}

.logbox {
  background-color: #1f2229;
  min-width: 100%;
  max-height: 73vh;
  overflow-y: auto;
  overflow-x: auto;
}

div.logbox pre {
  background-color: rgb(31, 34, 41);
}

.logline {
  word-break: break-all;
  line-height: 2.3em;
  padding: 1em;
  color: #fff1b8;
}
</style>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, onUnmounted, ref, watch } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import { fetchEventSource } from '@microsoft/fetch-event-source';
import moment from 'moment';
import EventView from '~/components/EventView.vue';
import LogLineLinks from '~/components/LogLineLinks.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type { GenericResponse, LogEntry } from '~/types';
import {
  copyText,
  disableOpacity,
  enableOpacity,
  makeEventName,
  notification,
  parse_api_response,
  request,
} from '~/utils';

const router = useRouter();
const route = useRoute();
const filenameParam = Array.isArray(route.params.filename)
  ? route.params.filename[0]
  : route.params.filename;
const filename = filenameParam ?? '';

const pageShell = requireTopLevelPageShell('logs');

useHead({ title: `Logs : ${filename}` });

const query = ref<string>('');
const data = ref<Array<LogEntry>>([]);
const error = ref<string>('');
const wrapLines = useStorage('logs_wrap_lines', false);
const isDownloading = ref<boolean>(false);
const isLoading = ref<boolean>(false);
const toggleFilter = ref<boolean>(false);
const autoScroll = ref<boolean>(true);
const reachedEnd = ref<boolean>(false);
const offset = ref<number>(0);
const contentType = ref<'log' | 'json'>('log');
const stream = ref<boolean>(false);
const logContainer = ref<HTMLElement | null>(null);
const streamController = ref<AbortController | null>(null);
const selectedEventId = ref<string | null>(null);
const isTodayLog = computed<boolean>(() => filename.includes(moment().format('YYYYMMDD')));

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

let scrollTimeout: ReturnType<typeof setTimeout> | null = null;

const token = useStorage('token', '');
const dialog = useDialog();

type FilePickerOptions = {
  suggestedName?: string;
};

type FilePickerHandle = {
  createWritable: () => Promise<WritableStream>;
};

const viewerCardUi = {
  body: 'p-0',
};

const scrollLogContainerToBottom = (behavior: ScrollBehavior = 'auto'): void => {
  if (!logContainer.value) {
    return;
  }

  logContainer.value.scrollTo({ top: logContainer.value.scrollHeight, behavior });
};

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

const filterItems = computed<Array<LogEntry>>(() => {
  if (!query.value) {
    return data.value;
  }

  return data.value.filter((item) => item.text.toLowerCase().includes(query.value.toLowerCase()));
});

const hasLinks = (item: LogEntry): boolean => {
  return Boolean(item.item_id || item.event_id || item.backend);
};

const openEvent = (id: string): void => {
  selectedEventId.value = id;
};

const loadContent = async (): Promise<void> => {
  try {
    isLoading.value = true;
    const response = await request(`/log/${filename}?offset=${offset.value}`);
    const json = await parse_api_response<{
      filename: string;
      offset: number;
      next: number | null;
      max: number;
      type: 'log' | 'json';
      lines: Array<LogEntry>;
    }>(response);

    if (200 !== response.status) {
      if ('error' in json) {
        error.value = `${json.error.code}: ${json.error.message}`;
      }
      return;
    }

    if ('logs-filename' !== route.name) {
      return;
    }

    if ('error' in json) {
      error.value = `${json.error.code}: ${json.error.message}`;
      return;
    }

    contentType.value = json.type ?? 'log';

    if (0 < json.lines.length) {
      data.value.unshift(...json.lines);
    }

    offset.value = json.next ?? offset.value;
    if (null === json.next) {
      reachedEnd.value = true;
    }

    await nextTick(() => {
      if (autoScroll.value) {
        scrollLogContainerToBottom('auto');
      }
    });

    watchLog();
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Unexpected error';
  } finally {
    isLoading.value = false;
  }
};

const handleScroll = (): void => {
  if (!logContainer.value || query.value) {
    return;
  }

  const container = logContainer.value;
  const nearBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50;
  const nearTop = container.scrollTop < 50;
  autoScroll.value = nearBottom;

  if (nearTop && !isLoading.value && !scrollTimeout && !reachedEnd.value) {
    scrollTimeout = setTimeout(async () => {
      const previousHeight = container.scrollHeight;
      await loadContent();
      await nextTick(() => {
        const newHeight = container.scrollHeight;
        container.scrollTop += newHeight - previousHeight;
      });
      scrollTimeout = null;
    }, 300);
  }
};

const scrollToBottom = (): void => {
  autoScroll.value = true;
  nextTick(() => {
    scrollLogContainerToBottom('smooth');
  });
};

onMounted(async () => {
  await loadContent();
  await nextTick(() => disableOpacity());
});

onBeforeUnmount(() => closeStream());

onUnmounted(async () => {
  closeStream();
  if (scrollTimeout) {
    clearTimeout(scrollTimeout);
    scrollTimeout = null;
  }
  await nextTick(() => enableOpacity());
});

const watchLog = (): void => {
  if (!isTodayLog.value || 'log' !== contentType.value || stream.value) {
    return;
  }

  const controller = new AbortController();
  streamController.value = controller;
  stream.value = true;

  void fetchEventSource(`/v1/api/log/${filename}?stream=1`, {
    onmessage: async (evt) => {
      if ('data' !== evt.event) {
        return;
      }

      const lines = evt.data.split(/\n/g);

      for (let index = 0; index < lines.length; index++) {
        try {
          const line = String(lines[index]);
          if (!line.trim()) {
            continue;
          }

          data.value.push(JSON.parse(line) as LogEntry);

          await nextTick(() => {
            if (autoScroll.value) {
              scrollLogContainerToBottom('smooth');
            }
          });
        } catch (streamError) {
          console.error(streamError);
        }
      }
    },
    onclose: () => {
      stream.value = false;
      streamController.value = null;
    },
    headers: {
      Authorization: `Token ${token.value}`,
    },
    signal: controller.signal,
  }).catch((streamError) => {
    if (controller.signal.aborted) {
      return;
    }

    console.error(streamError);
    stream.value = false;
    streamController.value = null;
  });
};

const closeStream = (): void => {
  streamController.value?.abort();
  streamController.value = null;
  stream.value = false;
};

const downloadFile = async (): Promise<void> => {
  isDownloading.value = true;

  try {
    const response = await request(`/log/${filename}?download=1`);
    const pickerWindow = window as Window & {
      showSaveFilePicker?: (options: FilePickerOptions) => Promise<FilePickerHandle>;
    };
    const showSaveFilePicker = pickerWindow.showSaveFilePicker;

    if (showSaveFilePicker) {
      if (!response.body) {
        notification('error', 'Error', 'No data returned from download request.');
        return;
      }

      const handle = await showSaveFilePicker({
        suggestedName: `${filename}`,
      });
      await response.body.pipeTo(await handle.createWritable());
      return;
    }

    const blob = await response.blob();
    const fileURL = URL.createObjectURL(blob);
    const fileLink = document.createElement('a');
    fileLink.href = fileURL;
    fileLink.download = `${filename}`;
    fileLink.click();
    URL.revokeObjectURL(fileURL);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    notification('error', 'Error', `Failed to download the file. ${message}`);
  } finally {
    isDownloading.value = false;
  }
};

const deleteFile = async (): Promise<void> => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Are you sure you want to delete the log file '${filename}'? This action cannot be undone.`,
    confirmText: 'Delete',
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    closeStream();

    const response = await request(`/log/${filename}`, { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);

    if (response.ok) {
      notification('success', 'Information', `Logfile '${filename}' has been deleted.`);
      await router.push('/logs');
      return;
    }

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `Request to delete logfile failed. (${json.error.code}: ${json.error.message}).`,
      );
      return;
    }

    notification('error', 'Error', 'Request to delete logfile failed.');
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    notification('error', 'Error', `Failed to request to delete a logfile. ${message}.`);
  }
};

const formatDate = (dt: string): string => moment(dt).format('DD/MM HH:mm:ss');

const renderJson = (lines: Array<LogEntry>): string =>
  JSON.stringify(JSON.parse(lines.map((entry) => entry.text).join('')), null, 4);

const copyData = (): void => {
  if ('json' === contentType.value) {
    copyText(renderJson(data.value));
    return;
  }

  copyText(filterItems.value.map((item) => item.text).join('\n'));
};
</script>
