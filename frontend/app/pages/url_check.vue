<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <span>{{ pageShell.pageLabel }}</span>
        </div>
      </div>

      <div v-if="hasResponse" class="flex flex-wrap items-center justify-end gap-2">
        <UTooltip text="Copy request and response">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-copy"
            @click="copyText(JSON.stringify(response, null, 2))"
          >
            <span class="hidden sm:inline">Copy</span>
          </UButton>
        </UTooltip>
      </div>
    </div>

    <UCard class="border border-default/70 bg-default/90 shadow-sm" :ui="formCardUi">
      <template #header>
        <div class="inline-flex items-center gap-2 text-base font-semibold text-highlighted">
          <UIcon name="i-lucide-send" class="size-4 shrink-0 text-toned" />
          <span>Request</span>
        </div>
      </template>

      <form class="space-y-4" @submit.prevent="check_url">
        <UAlert
          v-if="has_template_values()"
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Template values found"
          description="Replace values in [...] before testing when needed."
        />

        <UFormField label="Template" name="use-template">
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

        <UFormField label="URL" name="url" required>
          <div class="flex flex-col gap-2 sm:flex-row">
            <USelect
              v-model="item.method"
              :items="methods"
              class="sm:w-40"
              :disabled="is_loading"
            />

            <UInput
              id="url"
              v-model="item.url"
              type="text"
              autocomplete="off"
              placeholder="https://example.com/api/v1/"
              icon="i-lucide-link"
              class="flex-1"
              :disabled="is_loading"
            />
          </div>
        </UFormField>

        <div class="space-y-3">
          <div class="flex items-center justify-between gap-3">
            <div class="text-sm font-medium text-highlighted">Headers</div>

            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-plus"
              :disabled="is_loading"
              @click="add_header()"
            >
              Add
            </UButton>
          </div>

          <div v-if="item.headers.length > 0" class="space-y-2">
            <div
              v-for="(header, index) in item.headers"
              :key="index"
              class="flex flex-col gap-2 rounded-md border border-default bg-elevated/40 px-3 py-3 sm:flex-row"
            >
              <UInput
                v-model="header.key"
                type="text"
                placeholder="Header Key"
                class="flex-1"
                :disabled="is_loading"
              />
              <UInput
                v-model="header.value"
                type="text"
                placeholder="Header Value"
                class="flex-1"
                :disabled="is_loading"
              />
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                icon="i-lucide-x"
                class="shrink-0 whitespace-nowrap"
                :disabled="is_loading"
                @click="item.headers.splice(index, 1)"
              >
                Remove
              </UButton>
            </div>
          </div>

          <UAlert
            v-else
            color="neutral"
            variant="soft"
            icon="i-lucide-info"
            title="No custom headers"
            description="Add headers only when the target endpoint requires them."
          />
        </div>
      </form>

      <template #footer>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-terminal"
            :disabled="invalid_form || is_loading"
            @click="generateCurl"
          >
            Copy CURL
          </UButton>

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-rotate-ccw"
            :disabled="is_loading"
            @click="reset_form"
          >
            Reset
          </UButton>

          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-send"
            :loading="is_loading"
            :disabled="invalid_form || is_loading"
            @click="check_url"
          >
            Send Request
          </UButton>
        </div>
      </template>
    </UCard>

    <div v-if="hasResponse" class="grid gap-4 xl:grid-cols-[minmax(0,18rem)_minmax(0,1fr)]">
      <UCard class="border border-default/70 bg-default/90 shadow-sm" :ui="summaryCardUi">
        <template #header>
          <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-activity" class="size-4 text-toned" />
            <span>Summary</span>
          </div>
        </template>

        <div class="space-y-3">
          <div
            class="rounded-md border border-default bg-elevated/40 px-3 py-3 text-sm text-default"
          >
            <div class="mb-1 text-xs font-medium uppercase tracking-[0.16em] text-toned">
              Method
            </div>
            <div class="font-mono text-highlighted">{{ response.request.method }}</div>
          </div>

          <div
            class="rounded-md border border-default bg-elevated/40 px-3 py-3 text-sm text-default"
          >
            <div class="mb-1 text-xs font-medium uppercase tracking-[0.16em] text-toned">URL</div>
            <div class="ws-wrap-anywhere font-mono text-xs text-highlighted">
              {{ response.request.url }}
            </div>
          </div>

          <div
            class="rounded-md border border-default bg-elevated/40 px-3 py-3 text-sm text-default"
          >
            <div class="mb-1 text-xs font-medium uppercase tracking-[0.16em] text-toned">
              Status
            </div>
            <div :class="statusToneClass(response.response.status)" class="font-mono font-semibold">
              {{ response.response.status }}
            </div>
          </div>
        </div>
      </UCard>

      <UCard class="border border-default/70 bg-default/90 shadow-sm" :ui="resultCardUi">
        <template #header>
          <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-panels-top-left" class="size-4 text-toned" />
            <span>Details</span>
          </div>
        </template>

        <UTabs v-model="activeResultTab" :items="resultTabs" variant="pill" color="neutral">
          <template #request>
            <UTable
              :data="requestHeaderRows"
              :columns="headerColumns"
              sticky="header"
              :ui="tableUi"
            >
              <template #empty>
                <div class="py-6 text-center text-sm text-toned">No request headers found.</div>
              </template>

              <template #key-cell="{ row }">
                <span class="font-medium text-highlighted">{{ uc_words(row.original.key) }}</span>
              </template>

              <template #value-cell="{ row }">
                <span class="ws-wrap-anywhere text-default">{{ row.original.value }}</span>
              </template>
            </UTable>
          </template>

          <template #response>
            <UTable
              :data="responseHeaderRows"
              :columns="headerColumns"
              sticky="header"
              :ui="tableUi"
            >
              <template #empty>
                <div class="py-6 text-center text-sm text-toned">No response headers found.</div>
              </template>

              <template #key-cell="{ row }">
                <span class="font-medium text-highlighted">{{ uc_words(row.original.key) }}</span>
              </template>

              <template #value-cell="{ row }">
                <span :class="headerToneClass(row.original.key)">{{ row.original.value }}</span>
              </template>
            </UTable>
          </template>

          <template #body>
            <pre
              class="ws-terminal-panel ws-terminal-panel-lg rounded-md bg-elevated text-sm text-default"
            ><code>{{ response.response.body ? tryParse(response.response.body) : 'Empty body' }}</code></pre>
          </template>
        </UTabs>
      </UCard>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useHead } from '#app';
import type { TableColumn } from '@nuxt/ui';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { copyText, notification, parse_api_response, request } from '~/utils';
import { useDialog } from '~/composables/useDialog';

type HeaderItem = {
  key: string;
  value: string;
};

type Item = {
  url: string;
  method: string;
  headers: Array<HeaderItem>;
};

type URLCheckResponse = {
  request: {
    url: string;
    method: string;
    headers: Record<string, string>;
  };
  response: {
    status: number | null;
    headers: Record<string, string>;
    body: string;
  };
};

useHead({ title: 'URL Checker' });

const pageShell = requireTopLevelPageShell('url-check');
const dialog = useDialog();

const use_template = ref<string>('');
const activeResultTab = ref<string>('request');
const templates = ref<Array<{ id: number; key: string; override: Item }>>([
  {
    id: 1,
    key: 'Jellyfin/Emby Server: Info',
    override: {
      method: 'GET',
      url: 'http://[ip:port]/system/Info',
      headers: [
        { key: 'Accept', value: 'application/json' },
        { key: 'X-MediaBrowser-Token', value: '[API_KEY]' },
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
        { key: 'X-Plex-Token', value: '[PLEX_TOKEN]' },
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
        { key: 'X-Plex-Token', value: '[PLEX_TOKEN]' },
      ],
    },
  },
  {
    id: 4,
    key: 'Plex.tv: External Users',
    override: {
      method: 'GET',
      url: 'http://plex.tv/api/users',
      headers: [{ key: 'X-Plex-Token', value: '[PLEX_TOKEN]' }],
    },
  },
  {
    id: 5,
    key: 'Plex.tv: Home Users',
    override: {
      method: 'GET',
      url: 'http://plex.tv/api/v2/home/users/',
      headers: [
        { key: 'X-Plex-Token', value: '[PLEX_TOKEN]' },
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
        { key: 'X-MediaBrowser-Token', value: '[API_KEY]' },
      ],
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
  { label: 'Request Headers', value: 'request', slot: 'request', icon: 'i-lucide-arrow-up-right' },
  {
    label: 'Response Headers',
    value: 'response',
    slot: 'response',
    icon: 'i-lucide-arrow-down-left',
  },
  { label: 'Body', value: 'body', slot: 'body', icon: 'i-lucide-file-text' },
];

const formCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const summaryCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const resultCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const tableUi = {
  root: 'max-h-[28rem] overflow-auto rounded-md border border-default bg-elevated/20',
  th: 'px-3 py-2 text-sm text-left font-medium text-toned',
  td: 'px-3 py-2 text-sm text-default align-top whitespace-normal break-all',
};

const headerColumns: Array<TableColumn<HeaderItem>> = [
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

const defaultData = (): Item => ({ url: '', method: 'GET', headers: [] });
const defaultResponse = (): URLCheckResponse => ({
  request: { url: '', method: 'GET', headers: {} },
  response: { status: null, headers: {}, body: '' },
});

const cloneItem = (value: Item): Item => JSON.parse(JSON.stringify(value)) as Item;

const item = ref<Item>(defaultData());
const is_loading = ref<boolean>(false);
const response = ref<URLCheckResponse>(defaultResponse());

const hasResponse = computed<boolean>(() => null !== response.value.response.status);
const requestHeaderRows = computed<Array<HeaderItem>>(() =>
  Object.entries(response.value.request.headers ?? {}).map(([key, value]) => ({ key, value })),
);
const responseHeaderRows = computed<Array<HeaderItem>>(() =>
  Object.entries(response.value.response.headers ?? {}).map(([key, value]) => ({ key, value })),
);

const mergeTemplateHeaders = (
  currentHeaders: Array<HeaderItem>,
  templateHeaders: Array<HeaderItem>,
): Array<HeaderItem> => {
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

    return { key: header.key, value: preservedValue };
  });
};

watch(use_template, async (newValue: string) => {
  if ('' === newValue) {
    return;
  }

  const template = templates.value.find((t) => t.key === newValue);
  if (!template) {
    notification('error', 'Error', 'Template not found');
    return;
  }

  const nextItem = cloneItem(template.override);
  nextItem.headers = mergeTemplateHeaders(item.value.headers, nextItem.headers);
  item.value = nextItem;
  await nextTick();
  use_template.value = '';
});

const reset_form = (): void => {
  item.value = defaultData();
  response.value = defaultResponse();
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
  if (true === invalid_form.value) {
    notification('error', 'Error', 'Please fill in all required fields.');
    return;
  }

  if (has_template_values()) {
    const { status } = await dialog.confirmDialog({
      title: 'Template values found',
      message: 'The form contains template values. Do you want to continue?',
      confirmColor: 'warning',
    });

    if (true !== status) {
      return;
    }
  }

  is_loading.value = true;

  try {
    response.value = defaultResponse();
    await nextTick();

    const resp = await request('/system/url/check', {
      method: 'POST',
      body: JSON.stringify(item.value),
    });

    const json = await parse_api_response<URLCheckResponse>(resp);

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
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `failed to send request. ${message}`);
  } finally {
    is_loading.value = false;
  }
};

const uc_words = (str: string): string =>
  str.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());

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
    notification('error', 'Error', `Failed to generate cURL command. ${message}`);
  }
};

const statusToneClass = (status: number | null): string | undefined => {
  if (status === null) {
    return undefined;
  }

  if (status >= 200 && status < 300) {
    return 'text-emerald-500';
  }

  if (status >= 300 && status < 400) {
    return 'text-amber-500';
  }

  if (status >= 400 && status < 500) {
    return 'text-red-500';
  }

  if (status >= 500) {
    return 'text-violet-500';
  }
};

const headerToneClass = (key: string): string =>
  key.toLowerCase().startsWith('ws-') ? 'font-medium text-red-500' : 'text-default';
</script>
