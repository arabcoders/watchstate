<template>
  <UModal v-model:open="modalOpen" title="Log details" :ui="detailsModalUi">
    <template #body>
      <div v-if="log" class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
          <div class="flex min-w-0 flex-wrap items-center gap-2">
            <UBadge
              :color="LOG_LEVEL_COLOR[selectedLogLevel]"
              variant="soft"
              size="sm"
              class="uppercase"
            >
              <UIcon :name="LOG_LEVEL_ICON[selectedLogLevel]" class="mr-1 size-3.5" />
              {{ selectedLogLevel }}
            </UBadge>

            <UBadge
              v-if="log.logger"
              color="neutral"
              variant="soft"
              size="sm"
              class="max-w-full min-w-0"
              :title="log.logger"
            >
              <span class="min-w-0 max-w-full truncate">{{ log.logger }}</span>
            </UBadge>

            <span class="text-xs text-toned">{{ lineTitle(log) }}</span>
          </div>

          <div class="flex shrink-0 flex-wrap justify-end gap-2">
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              icon="i-lucide-copy"
              @click="copyLogMessage(log)"
            >
              Message
            </UButton>

            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              icon="i-lucide-braces"
              @click="copyLogRaw(log)"
            >
              JSON
            </UButton>
          </div>

          <div class="min-w-0 space-y-2 sm:col-span-2">
            <p class="wrap-break-word w-full font-mono text-sm text-default">
              {{ logMessage(log) }}
            </p>

            <UAlert
              v-if="log.exception_message"
              color="error"
              variant="soft"
              icon="i-lucide-badge-alert"
              :title="log.exception_message"
              class="w-full"
            />
          </div>
        </div>

        <section v-if="log.exception" class="space-y-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-toned hover:text-default"
            @click="exceptionOpen = !exceptionOpen"
          >
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 transition-transform', exceptionOpen ? 'rotate-90' : '']"
            />
            <UIcon name="i-lucide-bug" class="size-4 text-error" />
            Exception
          </button>

          <pre
            v-if="exceptionOpen"
            class="max-h-72 overflow-auto rounded-sm border border-error/30 bg-error/5 p-3 text-xs whitespace-pre-wrap text-error"
            >{{ formatException(log.exception) }}</pre
          >
        </section>

        <section v-if="log.stack" class="space-y-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-toned hover:text-default"
            @click="stackOpen = !stackOpen"
          >
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 transition-transform', stackOpen ? 'rotate-90' : '']"
            />
            <UIcon name="i-lucide-layers" class="size-4 text-toned" />
            Stack
          </button>

          <pre
            v-if="stackOpen"
            class="max-h-72 overflow-auto rounded-sm border border-default bg-elevated/50 p-3 text-xs whitespace-pre-wrap text-default"
            >{{ formatStack(log.stack) }}</pre
          >
        </section>

        <section v-if="detailRows(log).length > 0" class="space-y-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-toned hover:text-default"
            @click="sourceOpen = !sourceOpen"
          >
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 transition-transform', sourceOpen ? 'rotate-90' : '']"
            />
            <UIcon name="i-lucide-file-code" class="size-4 text-info" />
            Source
          </button>

          <dl v-if="sourceOpen" class="grid gap-2 sm:grid-cols-2">
            <div
              v-for="row in detailRows(log)"
              :key="row.label"
              class="rounded-sm border border-default bg-elevated/40 p-3"
            >
              <dt
                class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-toned"
              >
                <UIcon :name="row.icon" class="size-3.5" />
                <span>{{ row.label }}</span>
              </dt>

              <dd class="mt-1 wrap-break-word font-mono text-xs text-default">{{ row.value }}</dd>
            </div>
          </dl>
        </section>

        <section v-if="fieldRows(log).length > 0" class="space-y-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-toned hover:text-default"
            @click="fieldsOpen = !fieldsOpen"
          >
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 transition-transform', fieldsOpen ? 'rotate-90' : '']"
            />
            <UIcon name="i-lucide-tags" class="size-4 text-primary" />
            Fields
          </button>

          <dl v-if="fieldsOpen" class="grid gap-2 sm:grid-cols-2">
            <div
              v-for="row in fieldRows(log)"
              :key="row.label"
              class="rounded-sm border border-default bg-elevated/40 p-3"
            >
              <dt
                class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-toned"
              >
                <UIcon :name="row.icon" class="size-3.5" />
                <span>{{ row.label }}</span>
              </dt>

              <dd class="mt-1 wrap-break-word font-mono text-xs text-default">{{ row.value }}</dd>
            </div>
          </dl>
        </section>

        <section class="space-y-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-toned hover:text-default"
            @click="rawJsonOpen = !rawJsonOpen"
          >
            <UIcon
              name="i-lucide-chevron-right"
              :class="['size-4 transition-transform', rawJsonOpen ? 'rotate-90' : '']"
            />
            <UIcon name="i-lucide-braces" class="size-4 text-toned" />
            Raw data
          </button>

          <pre
            v-if="rawJsonOpen"
            class="max-h-96 overflow-auto rounded-sm border border-default bg-elevated/50 p-3 text-xs whitespace-pre-wrap text-default"
            >{{ logRawData(log) }}</pre
          >
        </section>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useStorage } from '@vueuse/core';
import type { LogEntry } from '~/types';
import { copyText } from '~/utils';
import {
  formatLogException,
  formatLogStack,
  getLogLevel,
  logDetailRows,
  logFieldRows,
  logMessageText,
  logRaw,
  logTimestampTitle,
} from '~/utils/logs';

type LogLevel = 'debug' | 'info' | 'warning' | 'error';
type LogLevelColor = 'neutral' | 'info' | 'warning' | 'error';

const props = defineProps<{
  log: LogEntry | null;
  open: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
}>();

const LOG_LEVEL_COLOR: Record<LogLevel, LogLevelColor> = {
  debug: 'neutral',
  info: 'info',
  warning: 'warning',
  error: 'error',
};

const LOG_LEVEL_ICON: Record<LogLevel, string> = {
  debug: 'i-lucide-terminal',
  info: 'i-lucide-info',
  warning: 'i-lucide-triangle-alert',
  error: 'i-lucide-circle-x',
};

const detailsModalUi = {
  content: 'max-w-5xl',
  body: 'max-h-[75vh] overflow-y-auto',
};

const modalOpen = computed({
  get: () => props.open,
  set: (value: boolean) => emit('update:open', value),
});

const selectedLogLevel = computed<LogLevel>(() => getLogLevel(props.log?.level));

const exceptionOpen = useStorage<boolean>('logs_exception_open', false);
const stackOpen = useStorage<boolean>('logs_stack_open', true);
const fieldsOpen = useStorage<boolean>('logs_fields_open', true);
const rawJsonOpen = useStorage<boolean>('logs_raw_json_open', false);
const sourceOpen = useStorage<boolean>('logs_source_open', true);

const lineTitle = (item: LogEntry): string => logTimestampTitle(item.datetime ?? item.date);

const logMessage = (item: LogEntry): string => logMessageText(item);

const detailRows = (item: LogEntry) => logDetailRows(item);

const fieldRows = (item: LogEntry) => logFieldRows(item);

const formatException = (value: string | null | undefined): string => formatLogException(value);

const formatStack = (value: string | null | undefined): string => formatLogStack(value);

const logRawData = (item: LogEntry): string => logRaw(item);

const copyLogMessage = (item: LogEntry): void => {
  copyText(logMessage(item));
};

const copyLogRaw = (item: LogEntry): void => {
  copyText(logRawData(item));
};
</script>
