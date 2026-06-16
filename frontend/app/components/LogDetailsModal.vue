<template>
  <UModal v-model:open="open" title="Log details" :ui="modalUi">
    <template #body>
      <div v-if="log" class="space-y-5">
        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
          <div class="flex min-w-0 flex-wrap items-center gap-2">
            <UBadge
              :color="LOG_LEVEL_COLOR[getLogLevel(log.level)]"
              variant="soft"
              size="sm"
              class="w-24 uppercase whitespace-nowrap"
            >
              <UIcon :name="LOG_LEVEL_ICON[getLogLevel(log.level)]" class="mr-1 size-3.5" />
              {{ getLogLevel(log.level) }}
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

            <span class="text-xs text-toned">{{ logTimeTitle(log.datetime) }}</span>
          </div>

          <UDropdownMenu :items="copyMenuItems" :content="copyMenuContent" :modal="false">
            <UButton
              color="neutral"
              variant="outline"
              size="xs"
              icon="i-lucide-copy"
              trailing-icon="i-lucide-chevron-down"
            >
              Copy
            </UButton>
          </UDropdownMenu>

          <div class="min-w-0 space-y-2 sm:col-span-2">
            <p class="wrap-break-word w-full font-mono text-sm text-default">
              {{ log.message }}
            </p>
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
            class="max-h-96 overflow-auto rounded-sm border border-error/30 bg-error/5 p-3 text-xs whitespace-pre-wrap text-error"
            >{{ formatException(log.exception) }}</pre
          >
        </section>

        <section v-if="detailRows.length > 0" class="space-y-2">
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
              v-for="row in detailRows"
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

        <section v-if="fieldRows.length > 0" class="space-y-2">
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

          <div v-if="fieldsOpen" class="space-y-2">
            <div
              v-for="field in fieldRows"
              :key="field.key"
              class="rounded-sm border border-default bg-elevated/40"
            >
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left"
                @click="toggleField(field.key)"
              >
                <span class="text-[11px] font-semibold uppercase tracking-wide text-toned">
                  {{ field.label }}
                </span>
                <div class="flex items-center gap-2">
                  <span v-if="field.preview" class="max-w-md truncate text-xs text-toned">
                    {{ field.preview }}
                  </span>
                  <UIcon
                    name="i-lucide-chevron-right"
                    :class="[
                      'size-4 shrink-0 text-toned transition-transform',
                      fieldOpen(field.key) ? 'rotate-90' : '',
                    ]"
                  />
                </div>
              </button>

              <div v-if="fieldOpen(field.key)" class="border-t border-default/70 px-3 py-3">
                <div class="mb-3 flex items-center justify-between gap-3">
                  <div v-if="field.kind !== 'scalar'" class="w-full">
                    <UInput
                      :model-value="fieldFilter(field.key)"
                      type="search"
                      icon="i-lucide-filter"
                      placeholder="Filter field lines"
                      size="sm"
                      class="w-full"
                      @change="setFieldFilter(field.key, ($event.target as HTMLInputElement).value)"
                    />
                  </div>
                  <UButton
                    size="xs"
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-copy"
                    @click="copyText(displayedFieldValue(field), true)"
                  />
                </div>

                <pre
                  v-if="field.kind === 'json'"
                  class="max-h-96 overflow-auto rounded-sm border border-default bg-elevated/50 p-3 text-xs whitespace-pre-wrap text-default"
                ><code class="block">{{ displayedFieldValue(field) }}</code></pre>
                <pre
                  v-else-if="field.kind === 'text'"
                  class="max-h-96 overflow-auto rounded-sm border border-default bg-elevated/50 p-3 text-xs whitespace-pre-wrap text-default"
                  >{{ displayedFieldValue(field) }}</pre
                >
                <p v-else class="wrap-break-word font-mono text-xs text-default">
                  {{ field.value }}
                </p>
              </div>
            </div>
          </div>
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
            >{{ rawJson }}</pre
          >
        </section>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, ref } from 'vue';
import { copyText } from '~/utils';
import type { ServerJsonLogEntry, ServerJsonLogException } from '~/types';
import { getLogLevel, LOG_LEVEL_ICON, logTimeTitle } from '~/utils/logs';
import type { LogLevel } from '~/utils/logs';

type LogLevelColor = 'neutral' | 'primary' | 'info' | 'warning' | 'error';
type DetailRow = { label: string; value: string; icon: string };
type FieldRow = {
  key: string;
  label: string;
  value: string;
  preview: string;
  kind: 'scalar' | 'text' | 'json';
};

const props = defineProps<{
  modelValue?: boolean;
  log?: ServerJsonLogEntry | null;
}>();

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void;
}>();

const open = computed({
  get: () => props.modelValue ?? false,
  set: (val: boolean) => emit('update:modelValue', val),
});

const modalUi = {
  content: 'max-w-5xl',
  body: 'max-h-[75vh] overflow-y-auto',
} as const;

const LOG_LEVEL_COLOR: Record<LogLevel, LogLevelColor> = {
  debug: 'neutral',
  info: 'info',
  notice: 'primary',
  warning: 'warning',
  error: 'error',
};

const exceptionOpen = useStorage<boolean>('logs_exception_open', false);
const fieldsOpen = useStorage<boolean>('logs_fields_open', true);
const rawJsonOpen = useStorage<boolean>('logs_raw_json_open', false);
const sourceOpen = useStorage<boolean>('logs_source_open', true);

const fieldOpenState = ref<Record<string, boolean>>({});
const fieldFilters = ref<Record<string, string>>({});

const fieldOpen = (key: string): boolean => fieldOpenState.value[key] ?? false;
const toggleField = (key: string): void => {
  fieldOpenState.value[key] = !fieldOpen(key);
};
const fieldFilter = (key: string): string => fieldFilters.value[key] ?? '';
const setFieldFilter = (key: string, value: string): void => {
  fieldFilters.value[key] = value;
};

const rawJson = computed(() => (props.log ? JSON.stringify(props.log, null, 2) : ''));

const copyMenuContent = computed(() => ({ align: 'end' as const }));

const copyMenuItems = computed(() => [
  [
    {
      label: 'Copy ID',
      icon: 'i-lucide-hash',
      onSelect: () => {
        if (props.log) {
          copyText(props.log.id);
        }
      },
    },
    {
      label: 'Copy Message',
      icon: 'i-lucide-message-square-text',
      onSelect: () => {
        if (props.log) {
          copyText(props.log.message);
        }
      },
    },
    {
      label: 'Copy JSON',
      icon: 'i-lucide-braces',
      onSelect: () => {
        if (props.log) {
          copyText(rawJson.value);
        }
      },
    },
  ],
]);

const compactRows = (rows: Array<DetailRow | null>): DetailRow[] =>
  rows.filter((row): row is DetailRow => null !== row && '' !== row.value);

const formatDetailValue = (value: unknown): string => {
  if (null === value || undefined === value) {
    return '';
  }
  return String(value);
};

const formatNameId = (nameValue: unknown, idValue: unknown): string => {
  const left = formatDetailValue(nameValue);
  const right = formatDetailValue(idValue);
  if (left && right) {
    return `${left} / ${right}`;
  }
  return left || right;
};

const detailRows = computed((): DetailRow[] => {
  if (!props.log) {
    return [];
  }
  return compactRows([
    { label: 'File', value: formatDetailValue(props.log.source?.file), icon: 'i-lucide-file' },
    { label: 'Line', value: formatDetailValue(props.log.source?.line), icon: 'i-lucide-hash' },
    {
      label: 'Function',
      value: formatDetailValue(props.log.source?.function),
      icon: 'i-lucide-code-2',
    },
    { label: 'Module', value: formatDetailValue(props.log.source?.module), icon: 'i-lucide-box' },
    {
      label: 'Path',
      value: formatDetailValue(props.log.source?.path),
      icon: 'i-lucide-folder-tree',
    },
    {
      label: 'Process / ID',
      value: formatNameId(props.log.process?.name, props.log.process?.id),
      icon: 'i-lucide-cpu',
    },
  ]);
});

const formatException = (exception: ServerJsonLogException | null | undefined): string => {
  if (!exception) {
    return '';
  }
  return JSON.stringify(exception, null, 2);
};

const formatFieldValue = (value: unknown): string => {
  if (typeof value === 'string') {
    return value;
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return JSON.stringify(value, null, 2);
};

const isJsonLike = (value: string): boolean => {
  const trimmed = value.trim();
  return (
    ((trimmed.startsWith('{') && trimmed.endsWith('}')) ||
      (trimmed.startsWith('[') && trimmed.endsWith(']'))) &&
    trimmed.length > 1
  );
};

const isJsonContainer = (value: unknown): value is Record<string, unknown> | unknown[] =>
  Array.isArray(value) || (!!value && typeof value === 'object');

const parseJsonContainerString = (value: string): Record<string, unknown> | unknown[] | null => {
  if (!isJsonLike(value)) {
    return null;
  }
  try {
    const parsed = JSON.parse(value) as unknown;
    if (Array.isArray(parsed)) {
      return parsed;
    }
    if (parsed && typeof parsed === 'object') {
      return parsed as Record<string, unknown>;
    }
  } catch {
    return null;
  }
  return null;
};

const fieldRows = computed((): FieldRow[] => {
  if (!props.log) {
    return [];
  }
  const rows: FieldRow[] = [];

  for (const [key, rawValue] of Object.entries(props.log.fields ?? {})) {
    if (rawValue === undefined || rawValue === null || rawValue === '') {
      continue;
    }

    const jsonValue =
      typeof rawValue === 'string'
        ? parseJsonContainerString(rawValue)
        : isJsonContainer(rawValue)
          ? rawValue
          : null;

    const value = jsonValue ? JSON.stringify(jsonValue, null, 2) : formatFieldValue(rawValue);

    const kind: FieldRow['kind'] = jsonValue
      ? 'json'
      : typeof rawValue === 'string'
        ? rawValue.includes('\n')
          ? 'text'
          : 'scalar'
        : 'scalar';

    rows.push({
      key,
      label: key.replaceAll('_', ' '),
      value,
      preview: value.split('\n')[0] ?? value,
      kind,
    });
  }

  return rows;
});

const filterLinesByQuery = (value: string, query: string): string => {
  if (!query.trim()) {
    return value;
  }
  return value
    .split('\n')
    .filter((line) => line.toLowerCase().includes(query.trim().toLowerCase()))
    .join('\n');
};

const displayedFieldValue = (field: FieldRow): string => {
  const filter = fieldFilters.value[field.key];
  if (filter && filter.trim()) {
    return filterLinesByQuery(field.value, filter);
  }
  return field.value;
};
</script>
