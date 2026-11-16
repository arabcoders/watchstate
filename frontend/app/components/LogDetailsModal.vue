<template>
  <UModal v-model:open="open" title="Log details" :ui="modalUi">
    <template #body>
      <div v-if="log" class="space-y-5">
        <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
          <div class="flex items-center gap-2">
            <dt class="sr-only">Level</dt>
            <dd>
              <UBadge
                :color="LOG_LEVEL_COLOR[getLogLevel(log.level)]"
                :icon="LOG_LEVEL_ICON[getLogLevel(log.level)]"
                variant="soft"
              >
                {{ getLogLevel(log.level) }}
              </UBadge>
            </dd>
          </div>
          <div v-if="log.logger" class="flex min-w-0 items-baseline gap-2">
            <dt class="text-muted">Logger</dt>
            <dd class="min-w-0 wrap-break-word font-mono text-xs">{{ log.logger }}</dd>
          </div>
          <div class="flex items-baseline gap-2">
            <dt class="text-muted">Date</dt>
            <dd>
              <UTooltip :text="moment(log.datetime).format(TOOLTIP_DATE_FORMAT)">
                <time :datetime="log.datetime">
                  {{ moment(log.datetime).fromNow() }}
                </time>
              </UTooltip>
            </dd>
          </div>
        </dl>
        <UAlert
          :color="LOG_LEVEL_COLOR[getLogLevel(log.level)]"
          variant="soft"
          :icon="LOG_LEVEL_ICON[getLogLevel(log.level)]"
          class="min-w-0"
        >
          <template #description>
            <p class="wrap-break-word w-full font-mono text-sm text-default">
              {{ log.message }}
            </p>
          </template>
        </UAlert>

        <section v-if="log.exception" class="space-y-3 border-t border-default pt-4">
          <div class="flex flex-wrap items-center gap-3">
            <h3 class="min-w-0 flex-1">
              <button
                type="button"
                class="flex w-full items-center gap-2 py-1 text-left text-sm font-semibold text-highlighted"
                :aria-expanded="exceptionOpen"
                :aria-controls="`${detailsId}-exception`"
                @click="exceptionOpen = !exceptionOpen"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'size-4 shrink-0 text-toned transition-transform',
                    exceptionOpen ? 'rotate-90' : '',
                  ]"
                />
                <span>Exception</span>
              </button>
            </h3>
            <div class="flex items-center gap-2">
              <UButton
                size="sm"
                color="neutral"
                :variant="wrapException ? 'soft' : 'outline'"
                :aria-pressed="wrapException"
                icon="i-lucide-wrap-text"
                @click="
                  () => {
                    wrapException = !wrapException;
                  }
                "
              >
                Wrap
              </UButton>
              <UTooltip text="Copy exception">
                <UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-copy"
                  aria-label="Copy exception"
                  @click="copyText(formatException(log.exception), true)"
                />
              </UTooltip>
            </div>
          </div>

          <pre
            v-if="exceptionOpen"
            :id="`${detailsId}-exception`"
            class="ws-terminal ws-terminal-panel max-h-96 overflow-auto"
            :class="wrapException ? 'whitespace-pre-wrap wrap-break-word' : 'whitespace-pre'"
            >{{ formatException(log.exception) }}</pre>
        </section>

        <section v-if="detailRows.length > 0" class="space-y-3 border-t border-default pt-4">
          <div class="flex items-center gap-3">
            <h3 class="min-w-0 flex-1">
              <button
                type="button"
                class="flex w-full items-center gap-2 py-1 text-left text-sm font-semibold text-highlighted"
                :aria-expanded="sourceOpen"
                :aria-controls="`${detailsId}-source`"
                @click="sourceOpen = !sourceOpen"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'size-4 shrink-0 text-toned transition-transform',
                    sourceOpen ? 'rotate-90' : '',
                  ]"
                />
                <span>Source</span>
              </button>
            </h3>
            <UTooltip text="Copy source">
              <UButton
                size="sm"
                color="neutral"
                variant="outline"
                icon="i-lucide-copy"
                aria-label="Copy source"
                @click="copyText(sourceJson, true)"
              />
            </UTooltip>
          </div>

          <dl v-if="sourceOpen" :id="`${detailsId}-source`" class="space-y-2">
            <div
              v-for="row in detailRows"
              :key="row.label"
              class="grid grid-cols-[7rem_minmax(0,1fr)] items-baseline gap-3"
            >
              <dt class="inline-flex items-center gap-1.5 text-xs text-muted">
                <UIcon :name="row.icon" class="size-3.5" />
                <span>{{ row.label }}</span>
              </dt>

              <dd class="wrap-break-word font-mono text-xs text-default">{{ row.value }}</dd>
            </div>
          </dl>
        </section>

        <section v-if="fieldRows.length > 0" class="space-y-3 border-t border-default pt-4">
          <div class="flex flex-wrap items-center gap-3">
            <h3 class="min-w-0 flex-1">
              <button
                type="button"
                class="flex w-full items-center gap-2 py-1 text-left text-sm font-semibold text-highlighted"
                :aria-expanded="fieldsOpen"
                :aria-controls="`${detailsId}-fields`"
                @click="fieldsOpen = !fieldsOpen"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'size-4 shrink-0 text-toned transition-transform',
                    fieldsOpen ? 'rotate-90' : '',
                  ]"
                />
                <span>Fields</span>
              </button>
            </h3>
            <div class="flex items-center gap-2">
              <UButton
                size="sm"
                color="neutral"
                :variant="wrapFields ? 'soft' : 'outline'"
                :aria-pressed="wrapFields"
                icon="i-lucide-wrap-text"
                @click="
                  () => {
                    wrapFields = !wrapFields;
                  }
                "
              >
                Wrap
              </UButton>
              <UTooltip text="Copy fields">
                <UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-copy"
                  aria-label="Copy fields"
                  @click="copyText(displayedFieldsJson, true)"
                />
              </UTooltip>
            </div>
          </div>

          <div v-if="fieldsOpen" :id="`${detailsId}-fields`" class="space-y-2">
            <UInput
              v-model="fieldsQuery"
              type="search"
              icon="i-lucide-filter"
              placeholder="Filter fields"
              size="sm"
              class="w-full"
            />

            <UEmpty
              v-if="fieldsQuery && 0 === visibleFieldRows.length"
              size="sm"
              variant="naked"
              icon="i-lucide-filter"
              title="No matching fields"
            />

            <div
              v-for="(field, fieldIndex) in visibleFieldRows"
              :key="field.key"
              class="border-b border-default last:border-b-0"
            >
              <button
                type="button"
                class="grid w-full grid-cols-[minmax(7rem,0.45fr)_minmax(0,1fr)] items-center gap-3 py-2 text-left sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]"
                :aria-expanded="fieldOpen(field.key)"
                :aria-controls="`${detailsId}-field-${fieldIndex}`"
                @click="toggleField(field.key)"
              >
                <span class="min-w-0 truncate text-[11px] font-semibold tracking-wide text-toned">
                  {{ field.label }}
                </span>
                <span class="flex min-w-0 items-center justify-end gap-2">
                  <span v-if="field.preview" class="min-w-0 truncate text-xs text-toned">
                    {{ field.preview }}
                  </span>
                  <UIcon
                    name="i-lucide-chevron-right"
                    :class="[
                      'size-4 shrink-0 text-toned transition-transform',
                      fieldOpen(field.key) ? 'rotate-90' : '',
                    ]"
                  />
                </span>
              </button>

              <div
                v-if="fieldOpen(field.key)"
                :id="`${detailsId}-field-${fieldIndex}`"
                class="pb-3 pt-1"
              >
                <div class="mb-3 flex items-center justify-end gap-3">
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
                  <UTooltip :text="`Copy ${field.label}`">
                    <UButton
                      size="sm"
                      color="neutral"
                      variant="outline"
                      icon="i-lucide-copy"
                      :aria-label="`Copy ${field.label}`"
                      @click="copyText(displayedFieldValue(field), true)"
                    />
                  </UTooltip>
                </div>

                <UEmpty
                  v-if="fieldFilter(field.key) && 0 === filteredFieldLineCount(field)"
                  size="sm"
                  variant="naked"
                  icon="i-lucide-filter"
                  title="No matching lines"
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
                  >{{ displayedFieldValue(field) }}</pre>
                <p v-else class="wrap-break-word font-mono text-xs text-default">
                  {{ field.value }}
                </p>
              </div>
            </div>
          </div>
        </section>

        <section class="space-y-3 border-t border-default pt-4">
          <div class="flex flex-wrap items-center gap-3">
            <h3 class="min-w-0 flex-1">
              <button
                type="button"
                class="flex w-full items-center gap-2 py-1 text-left text-sm font-semibold text-highlighted"
                :aria-expanded="rawJsonOpen"
                :aria-controls="`${detailsId}-raw`"
                @click="rawJsonOpen = !rawJsonOpen"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'size-4 shrink-0 text-toned transition-transform',
                    rawJsonOpen ? 'rotate-90' : '',
                  ]"
                />
                <span>Raw JSON</span>
              </button>
            </h3>
            <div class="flex items-center gap-2">
              <UButton
                size="sm"
                color="neutral"
                :variant="wrapRaw ? 'soft' : 'outline'"
                :aria-pressed="wrapRaw"
                icon="i-lucide-wrap-text"
                @click="
                  () => {
                    wrapRaw = !wrapRaw;
                  }
                "
              >
                Wrap
              </UButton>
              <UTooltip text="Copy raw JSON">
                <UButton
                  size="sm"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-copy"
                  aria-label="Copy raw JSON"
                  @click="copyText(displayedRawJson, true)"
                />
              </UTooltip>
            </div>
          </div>

          <div v-if="rawJsonOpen" :id="`${detailsId}-raw`" class="space-y-3">
            <UInput
              v-model="rawJsonQuery"
              type="search"
              icon="i-lucide-filter"
              placeholder="Filter raw data"
              size="sm"
              class="w-full"
            />

            <UEmpty
              v-if="rawJsonQuery && 0 === filteredRawJsonLineCount"
              size="sm"
              variant="naked"
              icon="i-lucide-filter"
              title="No matching lines"
            />

            <pre
              v-if="!rawJsonQuery || filteredRawJsonLineCount > 0"
              class="ws-terminal ws-terminal-panel max-h-96 overflow-auto"
              :class="wrapRaw ? 'whitespace-pre-wrap wrap-break-word' : 'whitespace-pre'"
              >{{ displayedRawJson }}</pre>
          </div>
        </section>
      </div>
    </template>
    <template #footer>
      <div v-if="log" class="flex w-full flex-wrap justify-end gap-2">
        <UDropdownMenu
          v-if="quickActions.length > 0"
          :items="[quickActions]"
          :content="{ align: 'end' }"
          :modal="false"
        >
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-arrow-up-right-from-square"
            trailing-icon="i-lucide-chevron-down"
          >
            Open
          </UButton>
        </UDropdownMenu>
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
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { navigateTo } from '#app';
import { useStorage } from '@vueuse/core';
import { computed, ref, useId } from 'vue';
import {
  UAlert,
  UBadge,
  UButton,
  UDropdownMenu,
  UEmpty,
  UIcon,
  UInput,
  UModal,
  UTooltip,
} from '#components';
import { copyText, goto_history_item, makeEventName, TOOLTIP_DATE_FORMAT } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import moment from 'moment';
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
type QuickAction = {
  label: string;
  icon: string;
  onSelect: () => void | Promise<void>;
};

const props = defineProps<{
  modelValue?: boolean;
  log?: ServerJsonLogEntry | null;
}>();

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void;
  (event: 'openEvent', eventId: string): void;
}>();

const dialog = useDialog();
const apiUser = useStorage('api_user', 'main');
const detailsId = useId();

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

const asString = (value: unknown): string | null => {
  if ('string' === typeof value) {
    const trimmed = value.trim();
    return '' === trimmed ? null : trimmed;
  }
  if ('number' === typeof value || 'boolean' === typeof value) {
    return String(value);
  }
  return null;
};

const fieldValue = (path: string): unknown => {
  const fields = props.log?.fields;
  if (!fields) {
    return null;
  }
  if (path in fields) {
    return fields[path];
  }

  let cursor: unknown = fields;
  for (const segment of path.split('.')) {
    if (!cursor || Array.isArray(cursor) || 'object' !== typeof cursor || !(segment in cursor)) {
      return null;
    }
    cursor = (cursor as Record<string, unknown>)[segment];
  }

  return cursor;
};

const fieldString = (path: string): string | null => asString(fieldValue(path));

const eventId = computed<string | null>(() => fieldString('event.id') ?? fieldString('event_id'));
const historyId = computed<string | null>(() => fieldString('history.id'));
const identityUser = computed<string | null>(() => fieldString('identity.user'));
const identityBackend = computed<string | null>(() => fieldString('identity.backend'));

const switchIdentity = async (identity: string): Promise<boolean> => {
  if (identity === apiUser.value) {
    return true;
  }

  const { status } = await dialog.confirmDialog({
    title: 'Switch identity?',
    message: `Switch from '${apiUser.value}' to '${identity}' to open this item?`,
    confirmText: 'Switch identity',
  });

  if (true !== status) {
    return false;
  }

  apiUser.value = identity;
  return true;
};

const quickActions = computed<Array<QuickAction>>(() => {
  const actions: Array<QuickAction> = [];

  const event = eventId.value;
  if (event) {
    actions.push({
      label: `Event #${makeEventName(event)}`,
      icon: 'i-lucide-activity',
      onSelect: () => {
        emit('openEvent', event);
      },
    });
  }

  const history = historyId.value;
  if (history) {
    actions.push({
      label: `History #${history}`,
      icon: 'i-lucide-history',
      onSelect: async () => {
        await goto_history_item({ history_id: history, user: identityUser.value });
      },
    });
  }

  const backend = identityBackend.value;
  if (backend) {
    actions.push({
      label: `Backend ${backend}`,
      icon: 'i-lucide-server',
      onSelect: async () => {
        const user = identityUser.value;
        if (user && false === (await switchIdentity(user))) {
          return;
        }
        await navigateTo(`/backend/${backend}`);
      },
    });
  }

  return actions;
});

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
