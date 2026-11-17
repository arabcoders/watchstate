<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" />

    <form class="space-y-6" :aria-busy="is_loading" @submit.prevent="check_url">
      <UFormField label="Template" name="use-template" class="sm:max-w-md">
        <USelect
          id="use-template"
          v-model="use_template"
          :items="templateItems"
          value-key="value"
          placeholder="Select a template"
          icon="i-lucide-file-text"
          class="w-full"
          :disabled="is_loading"
        />
      </UFormField>

      <UAlert
        v-if="has_template_values()"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        description="Template values found. Replace placeholders such as [API_KEY] before sending."
      />

      <div class="grid items-end gap-3 sm:grid-cols-[7rem_minmax(0,1fr)_auto]">
        <UFormField label="Method" name="method">
          <USelect
            id="method"
            v-model="item.method"
            :items="methods"
            class="w-full"
            :disabled="is_loading"
          />
        </UFormField>

        <UFormField label="URL" name="url" required>
          <UInput
            id="url"
            v-model="item.url"
            type="text"
            autocomplete="off"
            placeholder="https://example.com/api/v1/"
            icon="i-lucide-link"
            class="w-full"
            :disabled="is_loading"
          />
        </UFormField>

        <UButton
          type="submit"
          color="primary"
          variant="solid"
          size="md"
          icon="i-lucide-send"
          class="justify-center"
          :loading="is_loading"
          :disabled="invalid_form || is_loading"
        >
          Send request
        </UButton>
      </div>

      <section aria-labelledby="request-headers-heading" class="space-y-3">
        <div class="flex items-center justify-between gap-3">
          <h2 id="request-headers-heading" class="text-sm font-semibold text-highlighted">
            Headers
          </h2>
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-plus"
            :disabled="is_loading"
            @click="add_header()"
          >
            Add header
          </UButton>
        </div>

        <div v-if="0 < item.headers.length" class="space-y-2">
          <div
            v-for="(header, index) in item.headers"
            :key="index"
            class="grid items-center gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto]"
          >
            <UInput
              v-model="header.key"
              type="text"
              placeholder="Header name"
              :aria-label="`Header ${index + 1} name`"
              class="w-full"
              :disabled="is_loading"
              @update:model-value="revealedHeaders.delete(`form:${index}`)"
            />
            <UInput
              v-model="header.value"
              :type="
                header.sensitive && !revealedHeaders.has(`form:${index}`) ? 'password' : 'text'
              "
              placeholder="Value"
              :aria-label="`Header ${index + 1} value`"
              autocomplete="off"
              autocapitalize="none"
              :spellcheck="false"
              class="w-full"
              :disabled="is_loading"
            >
              <template v-if="header.sensitive" #trailing>
                <UTooltip
                  :text="revealedHeaders.has(`form:${index}`) ? 'Hide value' : 'Show value'"
                >
                  <UButton
                    type="button"
                    color="neutral"
                    variant="ghost"
                    square
                    size="xs"
                    :icon="
                      revealedHeaders.has(`form:${index}`) ? 'i-lucide-eye-off' : 'i-lucide-eye'
                    "
                    :aria-label="revealedHeaders.has(`form:${index}`) ? 'Hide value' : 'Show value'"
                    :aria-pressed="revealedHeaders.has(`form:${index}`)"
                    :disabled="is_loading"
                    @click="toggleHeaderVisibility(`form:${index}`)"
                  />
                </UTooltip>
              </template>
            </UInput>
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              icon="i-lucide-trash-2"
              class="justify-center"
              :disabled="is_loading"
              @click="
                () => {
                  item.headers.splice(index, 1);
                  revealedHeaders.clear();
                }
              "
            >
              Remove
            </UButton>
          </div>
        </div>

        <UEmpty v-else size="sm" variant="outline" icon="i-lucide-list" title="No custom headers" />
      </section>

      <div class="flex flex-wrap justify-end gap-2">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-rotate-ccw"
          :disabled="is_loading"
          @click="reset_form"
        >
          Reset
        </UButton>
        <UTooltip text="Copy cURL, including sensitive header values">
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-terminal"
            :disabled="invalid_form || is_loading"
            @click="generateCurl"
          >
            Copy cURL
          </UButton>
        </UTooltip>
      </div>
    </form>

    <UAlert
      v-if="requestError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Request failed"
      :description="requestError"
    />

    <section
      v-if="hasResponse"
      aria-labelledby="response-heading"
      class="space-y-4 border-t border-default pt-6"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-2">
          <div class="flex items-center gap-3">
            <h2 id="response-heading" class="text-lg font-semibold text-highlighted">Response</h2>
            <UBadge
              :color="getHttpStatusColor(response.response.status)"
              variant="soft"
              size="sm"
              class="font-mono"
            >
              <span class="sr-only">Status </span>{{ response.response.status }}
            </UBadge>
          </div>
          <p class="wrap-anywhere font-mono text-xs text-toned">
            {{ response.request.method }} {{ response.request.url }}
          </p>
        </div>
        <UTooltip text="Copy request and response, including sensitive header values">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            aria-label="Copy request and response"
            @click="copyText(JSON.stringify(response, null, 2))"
          >
            Copy
          </UButton>
        </UTooltip>
      </div>

      <UTabs v-model="activeResultTab" :items="resultTabs" variant="pill" color="neutral" size="sm">
        <template #request>
          <UTable :data="requestHeaderRows" :columns="headerColumns" sticky="header" :ui="tableUi">
            <template #empty>
              <UEmpty size="sm" variant="naked" icon="i-lucide-list" title="No request headers" />
            </template>

            <template #key-cell="{ row }">
              <span class="font-mono text-xs text-highlighted">{{ row.original.key }}</span>
            </template>

            <template #value-cell="{ row }">
              <div class="flex items-start gap-2">
                <span class="ws-wrap-anywhere min-w-0 text-default">
                  {{
                    row.original.sensitive && !revealedHeaders.has(`request:${row.original.key}`)
                      ? '••••••••'
                      : row.original.value
                  }}
                </span>
                <UTooltip
                  v-if="row.original.sensitive"
                  :text="
                    revealedHeaders.has(`request:${row.original.key}`) ? 'Hide value' : 'Show value'
                  "
                >
                  <UButton
                    type="button"
                    color="neutral"
                    variant="ghost"
                    square
                    size="xs"
                    class="shrink-0"
                    :icon="
                      revealedHeaders.has(`request:${row.original.key}`)
                        ? 'i-lucide-eye-off'
                        : 'i-lucide-eye'
                    "
                    :aria-label="
                      revealedHeaders.has(`request:${row.original.key}`)
                        ? 'Hide value'
                        : 'Show value'
                    "
                    :aria-pressed="revealedHeaders.has(`request:${row.original.key}`)"
                    @click="toggleHeaderVisibility(`request:${row.original.key}`)"
                  />
                </UTooltip>
              </div>
            </template>
          </UTable>
        </template>

        <template #response>
          <UTable :data="responseHeaderRows" :columns="headerColumns" sticky="header" :ui="tableUi">
            <template #empty>
              <UEmpty size="sm" variant="naked" icon="i-lucide-list" title="No response headers" />
            </template>

            <template #key-cell="{ row }">
              <span class="font-mono text-xs text-highlighted">{{ row.original.key }}</span>
            </template>

            <template #value-cell="{ row }">
              <div class="flex items-start gap-2">
                <span class="min-w-0 wrap-anywhere" :class="headerToneClass(row.original.key)">
                  {{
                    row.original.sensitive && !revealedHeaders.has(`response:${row.original.key}`)
                      ? '••••••••'
                      : row.original.value
                  }}
                </span>
                <UTooltip
                  v-if="row.original.sensitive"
                  :text="
                    revealedHeaders.has(`response:${row.original.key}`)
                      ? 'Hide value'
                      : 'Show value'
                  "
                >
                  <UButton
                    type="button"
                    color="neutral"
                    variant="ghost"
                    square
                    size="xs"
                    class="shrink-0"
                    :icon="
                      revealedHeaders.has(`response:${row.original.key}`)
                        ? 'i-lucide-eye-off'
                        : 'i-lucide-eye'
                    "
                    :aria-label="
                      revealedHeaders.has(`response:${row.original.key}`)
                        ? 'Hide value'
                        : 'Show value'
                    "
                    :aria-pressed="revealedHeaders.has(`response:${row.original.key}`)"
                    @click="toggleHeaderVisibility(`response:${row.original.key}`)"
                  />
                </UTooltip>
              </div>
            </template>
          </UTable>
        </template>

        <template #body>
          <pre
            class="ws-terminal-panel ws-terminal-panel-lg rounded-md bg-elevated/40 text-sm text-default"
          ><code>{{ response.response.body ? tryParse(response.response.body) : 'Empty body' }}</code></pre>
        </template>
      </UTabs>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useHead } from '#app';
import {
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UFormField,
  UInput,
  USelect,
  UTable,
  UTabs,
  UTooltip,
} from '#components';
import type { TableColumn } from '@nuxt/ui';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { copyText, getHttpStatusColor, notification, parse_api_response, request } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import type { URLCheckHeader, URLCheckRequest, URLCheckResponse } from '~/types';

useHead({ title: 'URL Checker' });

const pageShell = requireTopLevelPageShell('url-check');
const dialog = useDialog();

const use_template = ref<string>('');
const activeResultTab = ref<string>('request');
const templates = ref<Array<{ id: number; key: string; override: URLCheckRequest }>>([
  {
    id: 1,
    key: 'Jellyfin/Emby Server: Info',
    override: {
      method: 'GET',
      url: 'http://[ip:port]/system/Info',
      headers: [
        { key: 'Accept', value: 'application/json' },
        { key: 'Authorization', value: 'MediaBrowser Token="[API_KEY]"', sensitive: true },
      ],
    },
  },
  {
    id: 2,
    key: 'Plex: Info',
    override: {
      method: 'GET',
      url: 'http://[ip:port]/',
      headers: [
        { key: 'Accept', value: 'application/json' },
        { key: 'X-Plex-Token', value: '[PLEX_TOKEN]', sensitive: true },
      ],
    },
  },
  {
    id: 3,
    key: 'Plex: Libraries',
    override: {
      method: 'GET',
      url: 'http://[ip:port]/library/sections',
      headers: [
        { key: 'Accept', value: 'application/json' },
        { key: 'X-Plex-Token', value: '[PLEX_TOKEN]', sensitive: true },
      ],
    },
  },
  {
    id: 4,
    key: 'Plex.tv: External Users',
    override: {
      method: 'GET',
      url: 'http://plex.tv/api/users',
      headers: [{ key: 'X-Plex-Token', value: '[PLEX_TOKEN]', sensitive: true }],
    },
  },
  {
    id: 5,
    key: 'Plex.tv: Home Users',
    override: {
      method: 'GET',
      url: 'http://plex.tv/api/v2/home/users/',
      headers: [
        { key: 'X-Plex-Token', value: '[PLEX_TOKEN]', sensitive: true },
        { key: 'X-Plex-Client-Identifier', value: '[machineIdentifier]' },
      ],
    },
  },
  {
    id: 6,
    key: 'Jellyfin/Emby Server: Get Items',
    override: {
      method: 'GET',
      url: 'http://[ip:port]/items',
      headers: [
        { key: 'Accept', value: 'application/json' },
        { key: 'Authorization', value: 'MediaBrowser Token="[API_KEY]"', sensitive: true },
      ],
    },
  },
  {
    id: 7,
    key: 'Plex.tv: Validate Token',
    override: {
      method: 'GET',
      url: 'https://plex.tv/api/v2/user',
      headers: [{ key: 'X-Plex-Token', value: '[PLEX_TOKEN]', sensitive: true }],
    },
  },
]);
const methods = ref<Array<string>>(['GET', 'POST', 'PUT', 'PATCH', 'HEAD', 'DELETE']);

const templateItems = computed<Array<{ label: string; value: string }>>(() =>
  templates.value.map((template) => ({
    label: `${template.id}. ${template.key}`,
    value: template.key,
  })),
);

const resultTabs = [
  { label: 'Request headers', value: 'request', slot: 'request', icon: 'i-lucide-arrow-up-right' },
  {
    label: 'Response headers',
    value: 'response',
    slot: 'response',
    icon: 'i-lucide-arrow-down-left',
  },
  { label: 'Body', value: 'body', slot: 'body', icon: 'i-lucide-file-text' },
];

const tableUi = {
  root: 'max-h-[28rem]',
};

const headerColumns: Array<TableColumn<URLCheckHeader>> = [
  {
    accessorKey: 'key',
    header: 'Header',
    meta: {
      class: {
        th: 'w-56',
      },
    },
  },
  {
    accessorKey: 'value',
    header: 'Value',
  },
];

const defaultData = (): URLCheckRequest => ({ url: '', method: 'GET', headers: [] });
const defaultResponse = (): URLCheckResponse => ({
  request: { url: '', method: 'GET', headers: {} },
  response: { status: null, headers: {}, body: '' },
});

const cloneItem = (value: URLCheckRequest): URLCheckRequest =>
  JSON.parse(JSON.stringify(value)) as URLCheckRequest;

const item = ref<URLCheckRequest>(defaultData());
const is_loading = ref<boolean>(false);
const requestError = ref('');
const response = ref<URLCheckResponse>(defaultResponse());
const revealedHeaders = ref(new Set<string>());
const sensitiveHeaders = ref(new Set<string>());

const hasResponse = computed<boolean>(() => null !== response.value.response.status);
const requestHeaderRows = computed<Array<URLCheckHeader>>(() =>
  Object.entries(response.value.request.headers ?? {}).map(([key, value]) => ({
    key,
    value,
    sensitive: sensitiveHeaders.value.has(key.trim().toLowerCase()),
  })),
);
const responseHeaderRows = computed<Array<URLCheckHeader>>(() =>
  Object.entries(response.value.response.headers ?? {}).map(([key, value]) => ({
    key,
    value,
    sensitive: sensitiveHeaders.value.has(key.trim().toLowerCase()),
  })),
);

const toggleHeaderVisibility = (key: string): void => {
  if (revealedHeaders.value.has(key)) {
    revealedHeaders.value.delete(key);
    return;
  }

  revealedHeaders.value.add(key);
};

const mergeTemplateHeaders = (
  currentHeaders: Array<URLCheckHeader>,
  templateHeaders: Array<URLCheckHeader>,
): Array<URLCheckHeader> => {
  const currentValues = new Map<string, string>();

  for (const header of currentHeaders) {
    if (!header.key) {
      continue;
    }

    currentValues.set(header.key.toLowerCase(), header.value);
  }

  return templateHeaders.map((header) => {
    const preservedValue = currentValues.get(header.key.toLowerCase());

    if (undefined === preservedValue) {
      return { ...header };
    }

    return {
      ...header,
      value: preservedValue,
    };
  });
};

watch(use_template, async (newValue: string) => {
  if ('' === newValue) {
    return;
  }

  const template = templates.value.find((t) => t.key === newValue);
  if (!template) {
    notification('error', 'Template unavailable', 'Template not found.');
    return;
  }

  const nextItem = cloneItem(template.override);
  nextItem.headers = mergeTemplateHeaders(item.value.headers, nextItem.headers);
  item.value = nextItem;
  revealedHeaders.value.clear();
  await nextTick();
  use_template.value = '';
});

const reset_form = (): void => {
  item.value = defaultData();
  response.value = defaultResponse();
  requestError.value = '';
  revealedHeaders.value.clear();
  sensitiveHeaders.value.clear();
  activeResultTab.value = 'request';
};

const invalid_form = computed<boolean>(() => {
  if (!item.value.url || !item.value.method) {
    return true;
  }

  try {
    new URL(item.value.url);
  } catch {
    return true;
  }

  return false;
});

const has_template_values = (): boolean => {
  if (/\[.+?]/.test(item.value.url)) {
    return true;
  }

  for (const header of item.value.headers) {
    if (/\[.+?]/.test(header.key) || /\[.+?]/.test(header.value)) {
      return true;
    }
  }

  return false;
};

const add_header = (k?: string, v?: string): void => {
  item.value.headers.push({ key: k ?? '', value: v ?? '' });
};

const check_url = async (): Promise<void> => {
  if (is_loading.value) {
    return;
  }

  if (true === invalid_form.value) {
    notification('error', 'Request not sent', 'Enter a valid URL and select a method.');
    return;
  }

  if (has_template_values()) {
    const { status } = await dialog.confirmDialog({
      title: 'Template values found',
      message: 'Send the request without replacing its placeholders?',
      confirmText: 'Send request',
      confirmColor: 'neutral',
    });

    if (true !== status) {
      return;
    }
  }

  is_loading.value = true;
  requestError.value = '';
  revealedHeaders.value.clear();
  sensitiveHeaders.value = new Set(
    item.value.headers
      .filter((header) => header.sensitive)
      .map((header) => header.key.trim().toLowerCase()),
  );

  try {
    response.value = defaultResponse();
    await nextTick();

    const resp = await request('/system/url/check', {
      method: 'POST',
      body: JSON.stringify({
        url: item.value.url,
        method: item.value.method,
        headers: item.value.headers.map(({ key, value }) => ({ key, value })),
      }),
    });

    const json = await parse_api_response<URLCheckResponse>(resp);

    if ('error' in json) {
      requestError.value = `${json.error.code ?? resp.status}: ${json.error.message ?? 'Unknown error'}`;
      notification('error', 'Request failed', requestError.value);
      return;
    }

    response.value = json;
    activeResultTab.value = 'response';
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    requestError.value = message;
    notification('error', 'Request failed', message);
  } finally {
    is_loading.value = false;
  }
};

const tryParse = (body: string): string => {
  try {
    return JSON.stringify(JSON.parse(body), null, 2);
  } catch {
    return body;
  }
};

const _escape = (str: string): string => str.replace(/'/g, "'\\''");

const buildCurlCommand = (req: {
  url: string;
  method?: string;
  headers?: Record<string, string> | Array<{ key: string; value: string }>;
  body?: string;
}): string => {
  const method = req.method ?? 'GET';
  const parts: Array<string> = ['curl', '-v'];

  if ('GET' !== method) {
    parts.push('-X');
    parts.push(method);
  }

  const headers: Array<{ key: string; value: string }> = [];
  if (req.headers) {
    if (Array.isArray(req.headers)) {
      for (const header of req.headers) {
        headers.push({ key: header.key, value: header.value });
      }
    } else {
      for (const key of Object.keys(req.headers)) {
        headers.push({ key, value: req.headers[key] ?? '' });
      }
    }
  }

  for (const header of headers) {
    parts.push('-H');
    parts.push(`'${_escape(`${header.key}: ${header.value}`)}'`);
  }

  if (req.body) {
    parts.push('--data');
    parts.push(`'${_escape(req.body)}'`);
  }

  parts.push(`'${_escape(req.url)}'`);

  return parts.map((part) => part.toString()).join(' ');
};

const generateCurl = (): void => {
  try {
    copyText(
      buildCurlCommand({
        url: item.value.url,
        method: item.value.method,
        headers: item.value.headers.map((header) => ({ key: header.key, value: header.value })),
      }),
    );
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'cURL command unavailable', message);
  }
};

const headerToneClass = (key: string): string =>
  key.toLowerCase().startsWith('ws-') ? 'font-medium text-error' : 'text-default';
</script>
