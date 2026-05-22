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
            <Popover placement="bottom-end" trigger="click">
              <template #trigger>
                <UButton
                  color="neutral"
                  variant="outline"
                  size="xs"
                  icon="i-lucide-copy"
                  trailing-icon="i-lucide-chevron-down"
                >
                  Copy
                </UButton>
              </template>

              <template #content="{ hide }">
                <div class="w-52 space-y-1 p-1">
                  <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
                    @click="copyLogMessage(log, hide)"
                  >
                    <UIcon name="i-lucide-scroll-text" class="size-4 text-toned" />
                    <span>Message</span>
                  </button>

                  <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
                    @click="copyLogRaw(log, hide)"
                  >
                    <UIcon name="i-lucide-braces" class="size-4 text-toned" />
                    <span>RAW DATA</span>
                  </button>
                </div>
              </template>
            </Popover>
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
            <UIcon name="i-lucide-layers" class="size-4 text-error" />
            Exception Stack
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
import Popover from '~/components/Popover.vue';
import type { LogEntry } from '~/types';
import { copyText } from '~/utils';
import {
  formatLogStack,
  getLogLevel,
  LOG_LEVEL_COLOR,
  LOG_LEVEL_ICON,
  logClipboardLine,
  logDetailRows,
  logFieldRows,
  logMessageText,
  logRaw,
  logTimestampTitle,
} from '~/utils/logs';

type LogLevel = 'debug' | 'info' | 'notice' | 'warning' | 'error';

const props = defineProps<{
  log: LogEntry | null;
  open: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
}>();

const detailsModalUi = {
  content: 'max-w-5xl',
  body: 'max-h-[75vh] overflow-y-auto',
};

const modalOpen = computed({
  get: () => props.open,
  set: (value: boolean) => emit('update:open', value),
});

const selectedLogLevel = computed<LogLevel>(() => getLogLevel(props.log?.level));

const stackOpen = useStorage<boolean>('logs_stack_open', true);
const fieldsOpen = useStorage<boolean>('logs_fields_open', true);
const rawJsonOpen = useStorage<boolean>('logs_raw_json_open', false);
const sourceOpen = useStorage<boolean>('logs_source_open', true);

const lineTitle = (item: LogEntry): string => logTimestampTitle(item.datetime ?? item.date);

const logMessage = (item: LogEntry): string => logMessageText(item);

const detailRows = (item: LogEntry) => logDetailRows(item);

const fieldRows = (item: LogEntry) => logFieldRows(item);

const formatStack = (value: string | null | undefined): string => formatLogStack(value);

const logRawData = (item: LogEntry): string => logRaw(item);

const copyLogMessage = (item: LogEntry, hide?: () => void): void => {
  copyText(logClipboardLine(item));
  hide?.();
};

const copyLogRaw = (item: LogEntry, hide?: () => void): void => {
  copyText(logRawData(item));
  hide?.();
};
</script>
