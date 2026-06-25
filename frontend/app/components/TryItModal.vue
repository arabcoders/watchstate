<template>
  <UModal
    :open="open"
    :title="title"
    :ui="modalUi"
    @update:open="(value: boolean) => emit('update:open', value)"
  >
    <template #body>
      <div class="space-y-5">
        <UAlert
          v-if="route?.deprecated"
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Deprecated endpoint"
          description="This route is marked as deprecated in the spec."
        />

        <UAlert
          v-if="!backend"
          color="warning"
          variant="soft"
          icon="i-lucide-server-off"
          title="No backend selected"
          description="Select a configured backend from the dropdown at the top of the page first."
        />

        <UAlert
          v-if="!hasFormFields"
          color="neutral"
          variant="soft"
          icon="i-lucide-info"
          title="No parameters"
          description="This endpoint has no configurable parameters."
        />

        <section v-if="pathParams.length > 0" class="space-y-3">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="toggleSection('path')"
          >
            <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
              <UIcon name="i-lucide-slash" class="size-4 text-toned" />
              <span>Path Parameters</span>
            </div>
            <div class="flex items-center gap-2">
              <UBadge color="neutral" variant="outline" size="sm">
                {{ pathParams.length }}
              </UBadge>
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  isSectionOpen('path') ? 'rotate-90' : '',
                ]"
              />
            </div>
          </button>

          <div v-if="isSectionOpen('path')" class="grid gap-3 sm:grid-cols-2">
            <UFormField
              v-for="param in pathParams"
              :key="param.key"
              :label="param.name"
              :description="param.description"
              :required="param.required"
            >
              <ParamInput
                :model-value="pathValues[param.name] ?? ''"
                :param="param"
                :disabled="isLoading"
                @update:model-value="(value: string) => (pathValues[param.name] = value)"
              />
            </UFormField>
          </div>
        </section>

        <section v-if="queryParams.length > 0" class="space-y-3">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="toggleSection('query')"
          >
            <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
              <UIcon name="i-lucide-list" class="size-4 text-toned" />
              <span>Query Parameters</span>
            </div>
            <div class="flex items-center gap-2">
              <UBadge color="neutral" variant="outline" size="sm">
                {{ queryParams.length }}
              </UBadge>
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  isSectionOpen('query') ? 'rotate-90' : '',
                ]"
              />
            </div>
          </button>

          <div v-if="isSectionOpen('query')" class="grid gap-3 sm:grid-cols-2">
            <UFormField
              v-for="param in queryParams"
              :key="param.key"
              :label="param.name"
              :description="param.description"
              :required="param.required"
            >
              <ParamInput
                :model-value="queryValues[param.name] ?? ''"
                :param="param"
                :disabled="isLoading"
                @update:model-value="(value: string) => (queryValues[param.name] = value)"
              />
            </UFormField>
          </div>
        </section>

        <section v-if="headerParams.length > 0 || headerRows.length > 0" class="space-y-3">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="toggleSection('headers')"
          >
            <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
              <UIcon name="i-lucide-braces" class="size-4 text-toned" />
              <span>Headers</span>
            </div>
            <div class="flex items-center gap-2">
              <UBadge color="neutral" variant="outline" size="sm">
                {{ headerRows.length }}
              </UBadge>
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  isSectionOpen('headers') ? 'rotate-90' : '',
                ]"
              />
            </div>
          </button>

          <div v-if="isSectionOpen('headers')" class="space-y-2">
            <div class="flex justify-end">
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-plus"
                :disabled="isLoading"
                @click="addHeaderRow()"
              >
                Add
              </UButton>
            </div>
            <div
              v-for="(row, index) in headerRows"
              :key="index"
              class="flex flex-col gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 sm:flex-row"
            >
              <UInput
                v-model="row.key"
                type="text"
                placeholder="Header Key"
                class="flex-1"
                :disabled="isLoading"
              />
              <UInput
                v-model="row.value"
                type="text"
                placeholder="Header Value"
                class="flex-1"
                :disabled="isLoading"
              />
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                icon="i-lucide-x"
                size="xs"
                class="shrink-0"
                :disabled="isLoading"
                @click="headerRows.splice(index, 1)"
              />
            </div>
          </div>
        </section>

        <section v-if="route?.requestBody" class="space-y-3">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 text-left"
            @click="toggleSection('body')"
          >
            <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
              <UIcon name="i-lucide-arrow-up-from-line" class="size-4 text-toned" />
              <span>Request Body</span>
            </div>
            <div class="flex items-center gap-2">
              <UBadge color="neutral" variant="outline" size="sm">
                {{ route.requestBody.mediaType }}
              </UBadge>
              <UIcon
                name="i-lucide-chevron-right"
                :class="[
                  'size-4 text-toned transition-transform',
                  isSectionOpen('body') ? 'rotate-90' : '',
                ]"
              />
            </div>
          </button>

          <div v-if="isSectionOpen('body')" class="space-y-2">
            <div class="flex items-center justify-end gap-2">
              <UBadge v-if="route.requestBody.required" color="warning" variant="soft" size="sm">
                Required
              </UBadge>
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                size="xs"
                icon="i-lucide-wand-sparkles"
                :disabled="isLoading"
                @click="formatBody"
              >
                Format
              </UButton>
            </div>
            <p v-if="route.requestBody.description" class="text-xs text-toned">
              {{ route.requestBody.description }}
            </p>
            <textarea
              v-model="bodyText"
              rows="8"
              :placeholder="bodyPlaceholder"
              class="ws-wrap-anywhere min-h-40 w-full rounded-md border border-default bg-elevated/30 px-3 py-2 font-mono text-sm text-default outline-none transition focus:border-primary"
              @blur="formatBody"
            />
          </div>
        </section>

        <div v-if="response" class="space-y-3">
          <div
            class="flex items-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2"
          >
            <UBadge
              :color="responseStatusColor"
              variant="soft"
              size="sm"
              class="font-mono shrink-0"
            >
              {{ response.response.status }}
            </UBadge>
            <span class="font-mono text-sm font-semibold text-highlighted shrink-0">
              {{ response.request.method }}
            </span>
            <span class="ws-wrap-anywhere min-w-0 flex-1 truncate font-mono text-xs text-toned">
              {{ response.request.url }}
            </span>
            <UTooltip text="Copy response body">
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-copy"
                :disabled="!response.response.body"
                @click="copyText(formatBodyText(response.response.body))"
              />
            </UTooltip>
          </div>

          <UTabs
            v-model="activeResultTab"
            :items="resultTabs"
            variant="pill"
            color="neutral"
            size="sm"
          >
            <template #request>
              <UTable :data="requestHeaderRows" :columns="headerColumns" :ui="tableUi">
                <template #empty>
                  <div class="py-4 text-center text-xs text-toned">No request headers.</div>
                </template>
                <template #key-cell="{ row }">
                  <span class="font-medium text-highlighted">{{ ucWords(row.original.key) }}</span>
                </template>
                <template #value-cell="{ row }">
                  <span class="ws-wrap-anywhere text-default">{{ row.original.value }}</span>
                </template>
              </UTable>
            </template>
            <template #response>
              <UTable :data="responseHeaderRows" :columns="headerColumns" :ui="tableUi">
                <template #empty>
                  <div class="py-4 text-center text-xs text-toned">No response headers.</div>
                </template>
                <template #key-cell="{ row }">
                  <span class="font-medium text-highlighted">{{ ucWords(row.original.key) }}</span>
                </template>
                <template #value-cell="{ row }">
                  <span :class="headerToneClass(row.original.key)">{{ row.original.value }}</span>
                </template>
              </UTable>
            </template>
            <template #body>
              <pre
                class="ws-terminal-panel ws-terminal-panel-lg max-h-72 overflow-auto rounded-md bg-elevated text-xs text-default"
              ><code>{{ formatBodyText(response.response.body) }}</code></pre>
            </template>
          </UTabs>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-end gap-2">
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-rotate-ccw"
          :disabled="isLoading"
          @click="resetForm"
        >
          Reset
        </UButton>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-terminal"
          :disabled="!canSend"
          @click="copyCurl"
        >
          Copy cURL
        </UButton>
        <UButton
          :color="isDestructive ? 'error' : 'primary'"
          :icon="isDestructive ? 'i-lucide-triangle-alert' : 'i-lucide-send'"
          :loading="isLoading"
          :disabled="!canSend"
          @click="send"
        >
          Send Request
        </UButton>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { TableColumn } from '@nuxt/ui';
import ParamInput from '~/components/ParamInput.vue';
import { copyText, notification, parse_api_response, request } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import type { Backend, GenericError, OpenAPIRouteEntry, ProxyExchange } from '~/types';

type HeaderItem = {
  key: string;
  value: string;
};

const props = withDefaults(
  defineProps<{
    open?: boolean;
    route?: OpenAPIRouteEntry | null;
    backend?: Backend | null;
  }>(),
  {
    open: false,
    route: null,
    backend: null,
  },
);

const emit = defineEmits<{
  'update:open': [value: boolean];
}>();

const dialog = useDialog();

const modalUi = {
  content: 'max-w-4xl',
  body: 'max-h-[70vh] overflow-y-auto p-5',
  footer: 'p-4',
};

const tableUi = {
  root: 'max-h-64 overflow-auto rounded-md border border-default bg-elevated/20',
  th: 'px-2 py-1.5 text-xs text-left font-medium text-toned',
  td: 'px-2 py-1.5 text-xs text-default align-top whitespace-normal break-all',
};

const headerColumns: Array<TableColumn<HeaderItem>> = [
  { accessorKey: 'key', header: 'Header', meta: { class: { th: 'w-44' } } },
  { accessorKey: 'value', header: 'Value' },
];

const resultTabs = [
  { label: 'Request', value: 'request', slot: 'request', icon: 'i-lucide-arrow-up-right' },
  { label: 'Response', value: 'response', slot: 'response', icon: 'i-lucide-arrow-down-left' },
  { label: 'Body', value: 'body', slot: 'body', icon: 'i-lucide-file-text' },
];

const pathValues = ref<Record<string, string>>({});
const queryValues = ref<Record<string, string>>({});
const headerRows = ref<Array<HeaderItem>>([]);
const bodyText = ref<string>('');
const response = ref<ProxyExchange | null>(null);
const isLoading = ref<boolean>(false);
const activeResultTab = ref<string>('response');
const collapsedSections = ref<Record<string, boolean>>({});

const FORM_SECTION_IDS: Array<string> = ['path', 'query', 'headers', 'body'];

const isSectionOpen = (id: string): boolean => !collapsedSections.value[id];

const toggleSection = (id: string): void => {
  collapsedSections.value[id] = !collapsedSections.value[id];
};

const collapseAllFormSections = (): void => {
  for (const id of FORM_SECTION_IDS) {
    collapsedSections.value[id] = true;
  }
};

const title = computed<string>(() => {
  if (!props.route) {
    return 'Try it out';
  }

  const suffix = props.backend ? ` via ${props.backend.name}` : '';
  return `${props.route.method} ${props.route.path}${suffix}`;
});

const pathParams = computed(() =>
  (props.route?.parameters ?? []).filter((param) => 'path' === param.location),
);

const queryParams = computed(() =>
  (props.route?.parameters ?? []).filter((param) => 'query' === param.location),
);

const headerParams = computed(() =>
  (props.route?.parameters ?? []).filter((param) => 'header' === param.location),
);

const hasFormFields = computed<boolean>(() => {
  return (
    pathParams.value.length > 0 ||
    queryParams.value.length > 0 ||
    headerParams.value.length > 0 ||
    !!props.route?.requestBody
  );
});

const requestHeaderRows = computed<Array<HeaderItem>>(() => {
  if (!response.value) {
    return [];
  }

  return Object.entries(response.value.request.headers ?? {}).map(([key, value]) => ({
    key,
    value,
  }));
});

const responseHeaderRows = computed<Array<HeaderItem>>(() => {
  if (!response.value) {
    return [];
  }

  return Object.entries(response.value.response.headers ?? {}).map(([key, value]) => ({
    key,
    value,
  }));
});

const isDestructive = computed<boolean>(() => {
  const method = (props.route?.method ?? '').toUpperCase();
  return ['DELETE', 'PUT', 'PATCH'].includes(method);
});

const canSend = computed<boolean>(() => {
  if (isLoading.value || !props.route || !props.backend) {
    return false;
  }

  const missingPath = pathParams.value.some((param) => {
    if (!param.required) {
      return false;
    }

    return !(pathValues.value[param.name] ?? '').trim();
  });

  return !missingPath;
});

const bodyPlaceholder = computed<string>(() => {
  const body = props.route?.requestBody;
  if (!body) {
    return '';
  }

  if (body.example) {
    return body.example;
  }

  if (body.shape) {
    return body.shape;
  }

  return 'Enter request body...';
});

const ucWords = (value: string): string =>
  value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());

const formatBodyText = (value: string): string => {
  if (!value) {
    return 'Empty body';
  }

  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch {
    return value;
  }
};

const responseStatusColor = computed<'success' | 'warning' | 'error' | 'neutral'>(() => {
  const code = response.value?.response.status ?? 0;

  if (code >= 200 && code < 300) {
    return 'success';
  }

  if (code >= 300 && code < 500) {
    return 'warning';
  }

  if (code >= 500) {
    return 'error';
  }

  return 'neutral';
});

const headerToneClass = (key: string): string =>
  key.toLowerCase().startsWith('ws-') ? 'font-medium text-red-500' : 'text-default';

const addHeaderRow = (key?: string, value?: string): void => {
  headerRows.value.push({ key: key ?? '', value: value ?? '' });
};

const resetForm = (): void => {
  pathValues.value = {};
  queryValues.value = {};
  headerRows.value = [];
  bodyText.value = '';
  response.value = null;
  activeResultTab.value = 'response';
  collapsedSections.value = {};
  seedFromRoute();
};

const seedFromRoute = (): void => {
  const route = props.route;
  if (!route) {
    return;
  }

  for (const param of pathParams.value) {
    pathValues.value[param.name] = param.example ?? '';
  }

  for (const param of queryParams.value) {
    queryValues.value[param.name] = param.example ?? '';
  }

  for (const param of headerParams.value) {
    if (param.example) {
      addHeaderRow(param.name, param.example);
    }
  }

  if (route.requestBody?.example) {
    bodyText.value = route.requestBody.example;
    formatBody();
  }
};

const formatBody = (): void => {
  const trimmed = bodyText.value.trim();
  if ('' === trimmed) {
    return;
  }

  try {
    const parsed = JSON.parse(trimmed);
    bodyText.value = JSON.stringify(parsed, null, 2);
  } catch {
    // leave non-JSON bodies untouched so users can edit XML/form-encoded payloads
  }
};

const resolvePath = (): string => {
  const route = props.route;
  if (!route) {
    return '';
  }

  let path = route.path;

  for (const param of pathParams.value) {
    const value = pathValues.value[param.name] ?? '';
    const placeholder = new RegExp(`\\{${param.name}[^}]*\\}`, 'g');
    path = path.replace(placeholder, encodeURIComponent(value));
  }

  return path;
};

const buildPayload = (): {
  method: string;
  path: string;
  query: Record<string, string>;
  headers: Record<string, string>;
  body: string;
} => {
  const query: Record<string, string> = {};

  for (const param of queryParams.value) {
    const value = queryValues.value[param.name] ?? '';
    if ('' !== value.trim()) {
      query[param.name] = value;
    }
  }

  const headers: Record<string, string> = {};

  for (const row of headerRows.value) {
    const key = row.key.trim();
    if ('' === key) {
      continue;
    }
    headers[key] = row.value;
  }

  return {
    method: (props.route?.method ?? 'GET').toUpperCase(),
    path: resolvePath(),
    query,
    headers,
    body: bodyText.value.trim(),
  };
};

const send = async (): Promise<void> => {
  if (!canSend.value || !props.backend) {
    return;
  }

  if (isDestructive.value) {
    const { status } = await dialog.confirmDialog({
      title: 'Confirm destructive request',
      message: `This will send a ${props.route?.method} request to ${props.backend.name}. Continue?`,
      confirmColor: 'error',
    });

    if (true !== status) {
      return;
    }
  }

  isLoading.value = true;
  response.value = null;

  try {
    const payload = buildPayload();
    const resp = await request(`/backend/${props.backend.name}/proxy`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    const json = await parse_api_response<ProxyExchange | GenericError>(resp);

    if ('error' in json) {
      notification(
        'error',
        'Error',
        `${json.error.code ?? resp.status}: ${json.error.message ?? 'Unknown error'}`,
      );
      return;
    }

    response.value = json;
    activeResultTab.value = 'response';
    collapseAllFormSections();
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Failed to send request. ${message}`);
  } finally {
    isLoading.value = false;
  }
};

const escapeShell = (value: string): string => value.replace(/'/g, "'\\''");

const buildCurlCommand = (): string => {
  if (!props.route || !props.backend) {
    return '';
  }

  const payload = buildPayload();
  const baseUrl = props.backend.url;

  const parts: Array<string> = ['curl', '-v'];

  if ('GET' !== payload.method) {
    parts.push('-X', payload.method);
  }

  for (const [key, value] of Object.entries(payload.headers)) {
    parts.push('-H', `'${escapeShell(`${key}: ${value}`)}'`);
  }

  if (payload.body) {
    parts.push('--data', `'${escapeShell(payload.body)}'`);
  }

  const queryString = Object.entries(payload.query)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join('&');

  const fullPath = queryString ? `${payload.path}?${queryString}` : payload.path;
  parts.push(`'${escapeShell(`${baseUrl}${fullPath}`)}'`);

  return parts.join(' ');
};

const copyCurl = (): void => {
  try {
    copyText(buildCurlCommand());
    notification('success', 'Copied', 'cURL command copied to clipboard.');
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Failed to copy cURL command. ${message}`);
  }
};

watch(
  () => props.route?.key,
  () => {
    response.value = null;
    activeResultTab.value = 'response';
    resetForm();
  },
);
</script>
