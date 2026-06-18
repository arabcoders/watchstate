<template>
  <UModal v-model:open="open" title="Log details" :ui="modalUi">
    <template #body>
      <div v-if="log" class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <StatCard
            label="Level"
            :value="getLogLevel(log.level)"
            :icon="LOG_LEVEL_ICON[getLogLevel(log.level)]"
            :color="LOG_LEVEL_COLOR[getLogLevel(log.level)]"
          />
          <StatCard v-if="log.logger" label="Logger" :value="log.logger" icon="i-lucide-tag" />
          <StatCard
            label="Date"
            :value="moment(log.datetime).fromNow()"
            icon="i-lucide-clock"
            :tooltip="moment(log.datetime).format(TOOLTIP_DATE_FORMAT)"
          />
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <UDropdownMenu :items="copyMenuItems" :content="copyMenuContent" :modal="false">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-copy"
              trailing-icon="i-lucide-chevron-down"
            >
              Copy
            </UButton>
          </UDropdownMenu>
        </div>
        <UAlert
          :color="LOG_LEVEL_COLOR[getLogLevel(log.level)]"
          variant="soft"
          :icon="LOG_LEVEL_ICON[getLogLevel(log.level)]"
          title=""
          class="min-w-0"
        >
          <template #description>
            <p class="wrap-break-word w-full font-mono text-sm text-default">
              {{ log.message }}
            </p>
          </template>
        </UAlert>

        <section v-if="log.exception" class="space-y-3">
          <button
            type="button"
            class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
            @click="exceptionOpen = !exceptionOpen"
          >
            <div class="flex items-center gap-3">
              <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-error/30 bg-error/5 text-error"
              >
                <UIcon name="i-lucide-bug" class="size-4" />
              </span>
              <p class="text-base font-semibold text-highlighted">Exception</p>
            </div>
            <div class="flex items-center gap-2" @click.stop>
              <UButton
                size="sm"
                color="neutral"
                :variant="wrapException ? 'soft' : 'outline'"
                icon="i-lucide-wrap-text"
                @click="wrapException = !wrapException"
              >
                <span class="hidden sm:inline">Wrap</span>
              </UButton>
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                icon="i-lucide-copy"
                @click="copyText(formatException(log.exception), true)"
              >
                Copy
              </UButton>
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  exceptionOpen ? 'rotate-90' : '',
                ]"
              />
            </div>
          </button>

          <pre
            v-if="exceptionOpen"
            class="ws-terminal ws-terminal-panel max-h-96 overflow-auto"
            :class="wrapException ? 'whitespace-pre-wrap wrap-break-word' : 'whitespace-pre'"
            >{{ formatException(log.exception) }}</pre
          >
        </section>

        <section v-if="detailRows.length > 0" class="space-y-3">
          <button
            type="button"
            class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
            @click="sourceOpen = !sourceOpen"
          >
            <div class="flex items-center gap-3">
              <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
              >
                <UIcon name="i-lucide-file-code" class="size-4" />
              </span>
              <p class="text-base font-semibold text-highlighted">Source</p>
            </div>
            <div class="flex items-center gap-2" @click.stop>
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                icon="i-lucide-copy"
                @click="copyText(sourceJson, true)"
              >
                Copy
              </UButton>
              <UIcon
                name="i-lucide-chevron-right"
                :class="['size-4 text-toned transition-transform', sourceOpen ? 'rotate-90' : '']"
              />
            </div>
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

        <section v-if="fieldRows.length > 0" class="space-y-3">
          <button
            type="button"
            class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
            @click="fieldsOpen = !fieldsOpen"
          >
            <div class="flex items-center gap-3">
              <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
              >
                <UIcon name="i-lucide-tags" class="size-4" />
              </span>
              <p class="text-base font-semibold text-highlighted">Fields</p>
            </div>
            <div class="flex items-center gap-2" @click.stop>
              <UButton
                size="sm"
                color="neutral"
                :variant="wrapFields ? 'soft' : 'outline'"
                icon="i-lucide-wrap-text"
                @click="wrapFields = !wrapFields"
              >
                <span class="hidden sm:inline">Wrap</span>
              </UButton>
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                icon="i-lucide-copy"
                @click="copyText(displayedFieldsJson, true)"
              >
                Copy
              </UButton>
              <UIcon
                name="i-lucide-chevron-right"
                :class="['size-4 text-toned transition-transform', fieldsOpen ? 'rotate-90' : '']"
              />
            </div>
          </button>

          <div v-if="fieldsOpen" class="space-y-2">
            <UInput
              v-model="fieldsQuery"
              type="search"
              icon="i-lucide-filter"
              placeholder="Filter fields"
              size="sm"
              class="w-full"
            />

            <UAlert
              v-if="fieldsQuery && 0 === visibleFieldRows.length"
              color="warning"
              variant="soft"
              icon="i-lucide-filter"
              title="No matching fields"
            />

            <div
              v-for="field in visibleFieldRows"
              :key="field.key"
              class="rounded-sm border border-default bg-elevated/40"
            >
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left"
                @click="toggleField(field.key)"
              >
                <span class="text-[11px] font-semibold tracking-wide text-toned">
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
                      v-model="fieldFilters[field.key]"
                      type="search"
                      icon="i-lucide-filter"
                      placeholder="Filter field lines"
                      size="sm"
                      class="w-full"
                    />
                  </div>
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-copy"
                    @click="copyText(displayedFieldValue(field), true)"
                  />
                </div>

                <UAlert
                  v-if="fieldFilter(field.key) && 0 === filteredFieldLineCount(field)"
                  color="warning"
                  variant="soft"
                  icon="i-lucide-filter"
                  title="No matching lines"
                  class="mb-3"
                />

                <pre
                  v-if="
                    field.kind === 'json' &&
                    (!fieldFilter(field.key) || filteredFieldLineCount(field) > 0)
                  "
                  class="ws-terminal ws-terminal-panel max-h-96 overflow-auto"
                  :class="wrapFields ? 'whitespace-pre-wrap wrap-break-word' : 'whitespace-pre'"
                ><code class="block">{{ displayedFieldValue(field) }}</code></pre>
                <pre
                  v-else-if="
                    field.kind === 'text' &&
                    (!fieldFilter(field.key) || filteredFieldLineCount(field) > 0)
                  "
                  class="ws-terminal ws-terminal-panel max-h-96 overflow-auto"
                  :class="wrapFields ? 'whitespace-pre-wrap wrap-break-word' : 'whitespace-pre'"
                  >{{ displayedFieldValue(field) }}</pre
                >
                <p v-else class="wrap-break-word font-mono text-xs text-default">
                  {{ field.value }}
                </p>
              </div>
            </div>
          </div>
        </section>

        <section class="space-y-3">
          <button
            type="button"
            class="flex w-full flex-wrap items-center justify-between gap-3 text-left"
            @click="rawJsonOpen = !rawJsonOpen"
          >
            <div class="flex items-center gap-3">
              <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/70 text-primary"
              >
                <UIcon name="i-lucide-braces" class="size-4" />
              </span>
              <p class="text-base font-semibold text-highlighted">Raw Data</p>
            </div>
            <div class="flex items-center gap-2" @click.stop>
              <UButton
                size="sm"
                color="neutral"
                :variant="wrapRaw ? 'soft' : 'outline'"
                icon="i-lucide-wrap-text"
                @click="wrapRaw = !wrapRaw"
              >
                <span class="hidden sm:inline">Wrap</span>
              </UButton>
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                icon="i-lucide-copy"
                @click="copyText(displayedRawJson, true)"
              >
                Copy
              </UButton>
              <UIcon
                name="i-lucide-chevron-right"
                :class="['size-4 text-toned transition-transform', rawJsonOpen ? 'rotate-90' : '']"
              />
            </div>
          </button>

          <div v-if="rawJsonOpen" class="space-y-3">
            <UInput
              v-model="rawJsonQuery"
              type="search"
              icon="i-lucide-filter"
              placeholder="Filter raw data"
              size="sm"
              class="w-full"
            />

            <UAlert
              v-if="rawJsonQuery && 0 === filteredRawJsonLineCount"
              color="warning"
              variant="soft"
              icon="i-lucide-filter"
              title="No matching lines"
            />

            <pre
              v-if="!rawJsonQuery || filteredRawJsonLineCount > 0"
              class="ws-terminal ws-terminal-panel max-h-96 overflow-auto"
              :class="wrapRaw ? 'whitespace-pre-wrap wrap-break-word' : 'whitespace-pre'"
              >{{ displayedRawJson }}</pre
            >
          </div>
        </section>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { useStorage } from '@vueuse/core';
import { computed, ref } from 'vue';
import { copyText, TOOLTIP_DATE_FORMAT } from '~/utils';
import moment from 'moment';
import StatCard from '~/components/StatCard.vue';
import type { ServerJsonLogEntry, ServerJsonLogException } from '~/types';
import { filterLogTextLines, getLogLevel, LOG_LEVEL_ICON } from '~/utils/logs';
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
const wrapException = useStorage<boolean>('logs_wrap_exception', false);
const wrapFields = useStorage<boolean>('logs_wrap_fields', false);
const wrapRaw = useStorage<boolean>('logs_wrap_raw', false);

const fieldOpenState = ref<Record<string, boolean>>({});
const fieldFilters = ref<Record<string, string>>({});
const fieldsQuery = ref<string>('');
const rawJsonQuery = ref<string>('');

const fieldOpen = (key: string): boolean => fieldOpenState.value[key] ?? false;
const toggleField = (key: string): void => {
  fieldOpenState.value[key] = !fieldOpen(key);
};
const fieldFilter = (key: string): string => fieldFilters.value[key] ?? '';

const rawJson = computed(() => (props.log ? JSON.stringify(props.log, null, 2) : ''));

const sourceJson = computed(() =>
  JSON.stringify(
    {
      source: props.log?.source ?? null,
      process: props.log?.process ?? null,
    },
    null,
    2,
  ),
);

const filteredRawJsonLines = computed<Array<string>>(() =>
  filterLogTextLines(rawJson.value, rawJsonQuery.value),
);
const filteredRawJsonLineCount = computed<number>(() => filteredRawJsonLines.value.length);
const displayedRawJson = computed<string>(() =>
  rawJsonQuery.value ? filteredRawJsonLines.value.join('\n') : rawJson.value,
);

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
      label: key,
      value,
      preview: value.split('\n')[0] ?? value,
      kind,
    });
  }

  return rows;
});

const visibleFieldRows = computed((): FieldRow[] => {
  const query = fieldsQuery.value.trim().toLowerCase();
  if (!query) {
    return fieldRows.value;
  }

  if (query.includes('.')) {
    const keyMatches = fieldRows.value.filter((field) => field.key.toLowerCase().includes(query));
    if (keyMatches.length > 0) {
      return keyMatches;
    }
  }

  return fieldRows.value.filter((field) => {
    const haystack = `${field.key}\n${field.preview}\n${field.value}`.toLowerCase();
    return haystack.includes(query) || filterLogTextLines(field.value, query).length > 0;
  });
});

const displayedFieldValue = (field: FieldRow): string => {
  const filter = fieldFilters.value[field.key];
  if (filter && filter.trim()) {
    return filterLogTextLines(field.value, filter).join('\n');
  }
  return field.value;
};

const displayedFieldsJson = computed(() => {
  const allFields = props.log?.fields ?? {};
  if (!fieldsQuery.value.trim()) {
    return JSON.stringify(allFields, null, 2);
  }

  const visibleKeys = new Set(visibleFieldRows.value.map((f) => f.key));
  const filtered: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(allFields)) {
    if (visibleKeys.has(key)) {
      filtered[key] = value;
    }
  }

  return JSON.stringify(filtered, null, 2);
});

const filteredFieldLineCount = (field: FieldRow): number => {
  return filterLogTextLines(field.value, fieldFilter(field.key)).length;
};
</script>
