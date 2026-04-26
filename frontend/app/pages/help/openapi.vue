<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0 space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <span>{{ pageShell.pageLabel }}</span>
        </div>

        <div class="space-y-1">
          <h1 class="text-xl font-semibold text-highlighted sm:text-2xl">{{ specTitle }}</h1>
          <p class="text-sm text-toned">{{ specMeta }}</p>
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-end gap-2">
        <UInput
          v-if="showFilter || query"
          id="filter"
          v-model="query"
          type="search"
          placeholder="Filter routes"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <USelect
          v-model="selectedBackend"
          :items="backendItems"
          value-key="value"
          label-key="label"
          color="neutral"
          variant="outline"
          size="sm"
          class="w-full sm:w-44"
          :disabled="isLoading"
        />

        <UButton
          color="neutral"
          :variant="showFilter ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-filter"
          @click="toggleFilter"
        >
          <span class="hidden sm:inline">Filter</span>
        </UButton>

        <a :href="specUrl" target="_blank" rel="noopener noreferrer">
          <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-file-code-2">
            <span class="hidden sm:inline">JSON</span>
          </UButton>
        </a>
      </div>
    </div>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading routes. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Unable to load routes"
      :description="error"
    />

    <template v-else>
      <UAlert
        v-if="filteredRoutes.length < 1"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        :title="query ? 'No results' : 'No routes found'"
      >
        <template #description>
          <div class="space-y-2 text-sm text-default">
            <p v-if="query">
              No routes match <strong>{{ query }}</strong
              >.
            </p>
            <p v-else>No routes are available for the selected backend.</p>
          </div>
        </template>
      </UAlert>

      <div v-else class="grid items-start gap-4 xl:grid-cols-2">
        <UCard
          v-for="route in filteredRoutes"
          :key="route.key"
          class="border border-default/70 bg-default/90 shadow-sm"
          :ui="cardUi"
        >
          <template #header>
            <button
              type="button"
              class="flex w-full items-start gap-3 text-left"
              :aria-expanded="isExpanded(route.key)"
              @click="toggleExpanded(route.key)"
            >
              <UIcon
                :name="isExpanded(route.key) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-right'"
                class="mt-1 size-4 shrink-0 text-toned"
              />

              <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                  <UBadge
                    :color="methodColor(route.method)"
                    variant="soft"
                    size="sm"
                    class="font-mono"
                  >
                    {{ route.method }}
                  </UBadge>

                  <div class="min-w-0 font-mono text-sm font-semibold text-highlighted">
                    <span class="ws-wrap-anywhere">{{ route.path }}</span>
                  </div>
                </div>

                <p class="text-sm leading-6 text-default">{{ route.summary }}</p>

                <div class="flex flex-wrap items-center gap-2 text-xs text-toned">
                  <UBadge
                    v-for="tag in route.tags"
                    :key="`${route.key}-${tag}`"
                    color="neutral"
                    variant="outline"
                    size="sm"
                  >
                    {{ tag }}
                  </UBadge>

                  <UBadge
                    v-if="route.operationId"
                    color="neutral"
                    variant="outline"
                    size="sm"
                    icon="i-lucide-braces"
                  >
                    {{ route.operationId }}
                  </UBadge>

                  <UBadge
                    v-if="route.deprecated"
                    color="warning"
                    variant="soft"
                    size="sm"
                    icon="i-lucide-triangle-alert"
                  >
                    Deprecated
                  </UBadge>
                </div>
              </div>
            </button>
          </template>

          <div v-if="isExpanded(route.key)" class="space-y-3">
            <section v-if="route.parameters.length > 0" class="space-y-3">
              <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-sliders-horizontal" class="size-4 text-toned" />
                <span>Parameters</span>
              </div>

              <div class="space-y-3">
                <div
                  v-for="parameter in route.parameters"
                  :key="`${route.key}-param-${parameter.key}`"
                  class="rounded-md border border-default bg-default/70 px-3 py-3"
                >
                  <div class="flex flex-wrap items-center gap-2">
                    <div class="font-mono text-sm font-semibold text-highlighted">
                      {{ parameter.name }}
                    </div>

                    <UBadge color="neutral" variant="soft" size="sm">
                      {{ parameter.location }}
                    </UBadge>

                    <UBadge v-if="parameter.required" color="warning" variant="soft" size="sm">
                      Required
                    </UBadge>

                    <UBadge
                      v-if="parameter.schemaSummary"
                      color="neutral"
                      variant="outline"
                      size="sm"
                    >
                      {{ parameter.schemaSummary }}
                    </UBadge>
                  </div>

                  <p v-if="parameter.description" class="mt-2 text-sm leading-6 text-default">
                    {{ parameter.description }}
                  </p>

                  <pre
                    v-if="shouldShowShape(parameter.schemaSummary, parameter.shape)"
                    class="mt-3 overflow-x-auto rounded-md border border-default bg-default/70 p-3 text-xs text-toned"
                  ><code>{{ parameter.shape }}</code></pre>
                </div>
              </div>
            </section>

            <section v-if="route.requestBody" class="space-y-3">
              <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-arrow-up-from-line" class="size-4 text-toned" />
                <span>Request Body</span>
              </div>

              <div class="rounded-md border border-default bg-default/70 px-3 py-3">
                <div class="flex flex-wrap items-center gap-2">
                  <UBadge color="neutral" variant="soft" size="sm">
                    {{ route.requestBody.mediaType }}
                  </UBadge>

                  <UBadge
                    v-if="route.requestBody.required"
                    color="warning"
                    variant="soft"
                    size="sm"
                  >
                    Required
                  </UBadge>

                  <UBadge
                    v-if="route.requestBody.schemaSummary"
                    color="neutral"
                    variant="outline"
                    size="sm"
                  >
                    {{ route.requestBody.schemaSummary }}
                  </UBadge>
                </div>

                <p v-if="route.requestBody.description" class="text-sm leading-6 text-default">
                  {{ route.requestBody.description }}
                </p>

                <pre
                  v-if="shouldShowShape(route.requestBody.schemaSummary, route.requestBody.shape)"
                  class="overflow-x-auto rounded-md border border-default bg-default/70 p-3 text-xs text-toned"
                ><code>{{ route.requestBody.shape }}</code></pre>

                <div v-else-if="!route.requestBody.schemaSummary" class="text-sm text-toned">
                  No request schema documented.
                </div>
              </div>
            </section>

            <section class="space-y-3">
              <div class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
                <UIcon name="i-lucide-arrow-down-to-line" class="size-4 text-toned" />
                <span>Responses</span>
              </div>

              <div v-if="route.responses.length > 0" class="space-y-3">
                <div
                  v-for="response in route.responses"
                  :key="`${route.key}-response-${response.status}`"
                  class="rounded-md border border-default bg-default/70 px-3 py-3"
                >
                  <div class="flex flex-wrap items-center gap-2">
                    <UBadge :color="responseColor(response.status)" variant="soft" size="sm">
                      {{ response.status }}
                    </UBadge>

                    <span class="text-sm font-medium text-highlighted">{{ response.label }}</span>

                    <UBadge v-if="response.mediaType" color="neutral" variant="outline" size="sm">
                      {{ response.mediaType }}
                    </UBadge>

                    <UBadge
                      v-if="response.schemaSummary"
                      color="neutral"
                      variant="outline"
                      size="sm"
                    >
                      {{ response.schemaSummary }}
                    </UBadge>
                  </div>

                  <p v-if="response.showDescription" class="mt-2 text-sm leading-6 text-default">
                    {{ response.description }}
                  </p>

                  <pre
                    v-if="shouldShowShape(response.schemaSummary, response.shape)"
                    class="mt-3 overflow-x-auto rounded-md border border-default bg-default/70 p-3 text-xs text-toned"
                  ><code>{{ response.shape }}</code></pre>

                  <div v-else-if="!response.schemaSummary" class="mt-2 text-sm text-toned">
                    No response body documented.
                  </div>
                </div>
              </div>

              <div v-else class="text-sm text-toned">No responses documented.</div>
            </section>
          </div>
        </UCard>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useHead } from '#app';
import { awaitElement, parse_api_response } from '~/utils';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import type {
  GenericError,
  OpenAPIDocument,
  OpenAPIMediaType,
  OpenAPIOperation,
  OpenAPIParameter,
  OpenAPIPathItem,
  OpenAPIReference,
  OpenAPIRequestBody,
  OpenAPIResponse,
  OpenAPISchema,
} from '~/types';

type BackendKey = 'plex' | 'jellyfin' | 'emby';
type OpenAPIMethodKey = 'get' | 'put' | 'post' | 'delete' | 'options' | 'head' | 'patch' | 'trace';
type RouteMethod = 'GET' | 'PUT' | 'POST' | 'DELETE' | 'OPTIONS' | 'HEAD' | 'PATCH' | 'TRACE';
type MediaTypePreference = {
  mediaType: string;
  content: OpenAPIMediaType;
};

type BackendItem = {
  value: BackendKey;
  label: string;
  file: string;
};

type RouteParameterItem = {
  key: string;
  name: string;
  location: string;
  description: string;
  required: boolean;
  schemaSummary: string;
  shape: string;
};

type RouteRequestBodyItem = {
  description: string;
  required: boolean;
  mediaType: string;
  schemaSummary: string;
  shape: string;
};

type RouteResponseItem = {
  status: string;
  label: string;
  description: string;
  showDescription: boolean;
  mediaType: string;
  schemaSummary: string;
  shape: string;
};

type RouteEntry = {
  key: string;
  method: RouteMethod;
  path: string;
  summary: string;
  operationId: string;
  tags: Array<string>;
  deprecated: boolean;
  parameters: Array<RouteParameterItem>;
  requestBody: RouteRequestBodyItem | null;
  responses: Array<RouteResponseItem>;
};

useHead({ title: 'Backend OpenAPI' });

const pageShell = requireTopLevelPageShell('openapi');

const backendItems: Array<BackendItem> = [
  { value: 'plex', label: 'Plex', file: '/guides/openapi/plex.json' },
  { value: 'jellyfin', label: 'Jellyfin', file: '/guides/openapi/jellyfin.json' },
  { value: 'emby', label: 'Emby', file: '/guides/openapi/emby.json' },
];

const METHOD_KEYS: Array<{ key: OpenAPIMethodKey; label: RouteMethod }> = [
  { key: 'get', label: 'GET' },
  { key: 'post', label: 'POST' },
  { key: 'put', label: 'PUT' },
  { key: 'patch', label: 'PATCH' },
  { key: 'delete', label: 'DELETE' },
  { key: 'head', label: 'HEAD' },
  { key: 'options', label: 'OPTIONS' },
  { key: 'trace', label: 'TRACE' },
];

const MEDIA_TYPE_PREFERENCE: Array<string> = [
  'application/json',
  'application/json; profile="CamelCase"',
  'application/json; profile="PascalCase"',
  'application/*+json',
  'text/json',
  'application/xml',
  'text/plain',
  'text/html',
];

const cardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const selectedBackend = ref<BackendKey>('plex');
const query = ref<string>('');
const showFilter = ref<boolean>(false);
const spec = ref<OpenAPIDocument | null>(null);
const error = ref<string>('');
const isLoading = ref<boolean>(true);
const expandedRoutes = ref<Record<string, boolean>>({});

const activeBackend = computed<BackendItem>(() => {
  const fallback: BackendItem = backendItems[0] as BackendItem;

  return backendItems.find((item) => item.value === selectedBackend.value) ?? fallback;
});

const specUrl = computed<string>(() => activeBackend.value.file);
const specTitle = computed<string>(
  () => spec.value?.info?.title?.trim() || activeBackend.value.label,
);
const specVersion = computed<string>(() => spec.value?.info?.version?.trim() || 'Unknown');
const specMeta = computed<string>(() => {
  const parts = [activeBackend.value.label];

  if ('Unknown' !== specVersion.value) {
    parts.push(`v${specVersion.value}`);
  }

  return parts.join(' / ');
});

const refLabel = (ref: string): string => {
  return ref
    .replace(/^#\/components\//, '')
    .replace(/^schemas\//, '')
    .replace(/^responses\//, '');
};

const isReference = (value: unknown): value is OpenAPIReference => {
  return !!value && 'object' === typeof value && '$ref' in value && 'string' === typeof value.$ref;
};

const resolveRefValue = (ref: string): unknown => {
  if (!ref.startsWith('#/')) {
    return null;
  }

  const doc = spec.value;
  if (!doc) {
    return null;
  }

  const parts = ref.replace(/^#\//, '').split('/');
  let current: unknown = doc;

  for (const part of parts) {
    if (!current || 'object' !== typeof current || false === part in current) {
      return null;
    }

    current = (current as Record<string, unknown>)[part];
  }

  return current;
};

const resolveSchema = (
  schema?: OpenAPISchema | OpenAPIReference | null,
  visited: Set<string> = new Set(),
): OpenAPISchema | null => {
  if (!schema) {
    return null;
  }

  if (!isReference(schema)) {
    return schema;
  }

  if (visited.has(schema.$ref)) {
    return { title: refLabel(schema.$ref) };
  }

  const nextVisited = new Set(visited);
  nextVisited.add(schema.$ref);
  const resolved = resolveRefValue(schema.$ref);

  if (resolved && 'object' === typeof resolved) {
    return resolveSchema(resolved as OpenAPISchema | OpenAPIReference, nextVisited);
  }

  return { title: refLabel(schema.$ref) };
};

const resolveParameter = (
  parameter: OpenAPIParameter | OpenAPIReference,
): OpenAPIParameter | null => {
  if (!isReference(parameter)) {
    return parameter;
  }

  const resolved = resolveRefValue(parameter.$ref);
  if (resolved && 'object' === typeof resolved) {
    return resolved as OpenAPIParameter;
  }

  return null;
};

const resolveRequestBody = (
  requestBody?: OpenAPIRequestBody | OpenAPIReference,
): OpenAPIRequestBody | null => {
  if (!requestBody) {
    return null;
  }

  if (!isReference(requestBody)) {
    return requestBody;
  }

  const resolved = resolveRefValue(requestBody.$ref);
  if (resolved && 'object' === typeof resolved) {
    return resolved as OpenAPIRequestBody;
  }

  return null;
};

const resolveResponse = (response: OpenAPIResponse | OpenAPIReference): OpenAPIResponse | null => {
  if (!isReference(response)) {
    return response;
  }

  const resolved = resolveRefValue(response.$ref);
  if (resolved && 'object' === typeof resolved) {
    if (isReference(resolved)) {
      return resolveResponse(resolved);
    }

    return resolved as OpenAPIResponse;
  }

  return null;
};

const pickMediaType = (content?: Record<string, OpenAPIMediaType>): MediaTypePreference | null => {
  if (!content) {
    return null;
  }

  for (const preferred of MEDIA_TYPE_PREFERENCE) {
    if (preferred in content) {
      const preferredContent = content[preferred];
      if (preferredContent) {
        return { mediaType: preferred, content: preferredContent };
      }
    }

    if ('application/*+json' === preferred) {
      const match = Object.entries(content).find(([mediaType]) => mediaType.includes('+json'));
      if (match) {
        return { mediaType: match[0], content: match[1] };
      }
    }
  }

  const first = Object.entries(content)[0];
  if (!first) {
    return null;
  }

  return { mediaType: first[0], content: first[1] };
};

const compactDescription = (value?: string): string => {
  return value?.replace(/\s+/g, ' ').trim() || '';
};

const schemaTypeLabel = (schema?: OpenAPISchema | OpenAPIReference | null): string => {
  if (!schema) {
    return '';
  }

  if (isReference(schema)) {
    return refLabel(schema.$ref);
  }

  if (schema.allOf && schema.allOf.length > 0) {
    const labels = schema.allOf
      .map((item) => schemaTypeLabel(item))
      .filter((item) => item.length > 0);
    if (labels.length > 0) {
      return labels.join(' & ');
    }
  }

  if (schema.oneOf && schema.oneOf.length > 0) {
    return schema.oneOf
      .map((item) => schemaTypeLabel(item))
      .filter((item) => item.length > 0)
      .join(' | ');
  }

  if (schema.anyOf && schema.anyOf.length > 0) {
    return schema.anyOf
      .map((item) => schemaTypeLabel(item))
      .filter((item) => item.length > 0)
      .join(' | ');
  }

  if ('array' === schema.type) {
    return `array<${schemaTypeLabel(schema.items) || 'unknown'}>`;
  }

  if (schema.enum && schema.enum.length > 0) {
    const base = schema.enum.map((item) => String(item)).join(' | ');
    return true === schema.nullable ? `${base} | null` : base;
  }

  const label = schema.format
    ? `${schema.type || 'value'} (${schema.format})`
    : schema.type || schema.title || 'object';

  return true === schema.nullable ? `${label} | null` : label;
};

const mergeAllOfSchema = (schema: OpenAPISchema): OpenAPISchema => {
  if (!schema.allOf || schema.allOf.length < 1) {
    return schema;
  }

  const merged: OpenAPISchema = {
    ...schema,
    properties: { ...(schema.properties ?? {}) },
    required: [...(schema.required ?? [])],
  };

  for (const part of schema.allOf) {
    const resolved = resolveSchema(part);
    if (!resolved) {
      continue;
    }

    const normalized = mergeAllOfSchema(resolved);
    merged.properties = {
      ...(merged.properties ?? {}),
      ...(normalized.properties ?? {}),
    };
    merged.required = Array.from(
      new Set([...(merged.required ?? []), ...(normalized.required ?? [])]),
    );

    if (!merged.type && normalized.type) {
      merged.type = normalized.type;
    }

    if (!merged.additionalProperties && undefined !== normalized.additionalProperties) {
      merged.additionalProperties = normalized.additionalProperties;
    }
  }

  return merged;
};

const schemaShape = (
  schema?: OpenAPISchema | OpenAPIReference | null,
  depth: number = 0,
  seen: Set<string> = new Set(),
): string => {
  if (!schema) {
    return '';
  }

  if (isReference(schema)) {
    const name = refLabel(schema.$ref);

    if (seen.has(schema.$ref) || depth > 3) {
      return name;
    }

    const resolved = resolveSchema(schema, seen);

    if (!resolved) {
      return name;
    }

    const nextSeen = new Set(seen);
    nextSeen.add(schema.$ref);
    const nested = schemaShape(resolved, depth + 1, nextSeen);
    return nested ? `${name} ${nested}` : name;
  }

  const normalized = mergeAllOfSchema(schema);

  if (normalized.oneOf && normalized.oneOf.length > 0) {
    return normalized.oneOf.map((item) => schemaTypeLabel(item)).join(' | ');
  }

  if (normalized.anyOf && normalized.anyOf.length > 0) {
    return normalized.anyOf.map((item) => schemaTypeLabel(item)).join(' | ');
  }

  if ('array' === normalized.type) {
    return `[${schemaShape(normalized.items, depth + 1, seen) || schemaTypeLabel(normalized.items) || 'unknown'}]`;
  }

  if (normalized.properties && Object.keys(normalized.properties).length > 0) {
    const indent = '  '.repeat(depth);
    const innerIndent = '  '.repeat(depth + 1);
    const lines = Object.entries(normalized.properties)
      .slice(0, 8)
      .map(([key, value]) => {
        const required = normalized.required?.includes(key) ? '' : '?';
        const rendered = schemaShape(value, depth + 1, seen) || schemaTypeLabel(value) || 'unknown';
        return `${innerIndent}${key}${required}: ${rendered}`;
      });

    if (Object.keys(normalized.properties).length > 8) {
      lines.push(`${innerIndent}...`);
    }

    return ['{', ...lines, `${indent}}`].join('\n');
  }

  if ('object' === normalized.type && normalized.additionalProperties) {
    if (true === normalized.additionalProperties) {
      return 'Record<string, unknown>';
    }

    return `Record<string, ${schemaTypeLabel(normalized.additionalProperties) || 'unknown'}>`;
  }

  if (normalized.example) {
    return JSON.stringify(normalized.example, null, 2);
  }

  return schemaTypeLabel(normalized);
};

const summarizeParameter = (
  routeKey: string,
  parameter: OpenAPIParameter | OpenAPIReference,
  index: number,
): RouteParameterItem | null => {
  const resolved = resolveParameter(parameter);
  if (!resolved) {
    return null;
  }

  const schema = resolveSchema(resolved.schema);

  return {
    key: `${routeKey}-${resolved.in ?? 'unknown'}-${resolved.name ?? index}`,
    name: resolved.name ?? `parameter_${index + 1}`,
    location: resolved.in ?? 'unknown',
    description: compactDescription(resolved.description),
    required: true === resolved.required,
    schemaSummary: schemaTypeLabel(resolved.schema ?? schema),
    shape: schemaShape(schema ?? resolved.schema),
  };
};

const summarizeRequestBody = (
  requestBody?: OpenAPIRequestBody | OpenAPIReference,
): RouteRequestBodyItem | null => {
  const resolved = resolveRequestBody(requestBody);
  if (!resolved) {
    return null;
  }

  const media = pickMediaType(resolved.content);
  if (!media) {
    return {
      description: compactDescription(resolved.description),
      required: true === resolved.required,
      mediaType: 'none',
      schemaSummary: '',
      shape: '',
    };
  }

  const schema = resolveSchema(media.content.schema);

  return {
    description: compactDescription(resolved.description),
    required: true === resolved.required,
    mediaType: media.mediaType,
    schemaSummary: schemaTypeLabel(media.content.schema ?? schema),
    shape: schemaShape(schema ?? media.content.schema),
  };
};

const summarizeResponse = (
  status: string,
  response: OpenAPIResponse | OpenAPIReference,
): RouteResponseItem | null => {
  const resolved = resolveResponse(response);
  if (!resolved) {
    return null;
  }

  const media = pickMediaType(resolved.content);
  const schema = resolveSchema(media?.content.schema);
  const description = compactDescription(resolved.description);
  const label = description || 'Response';

  return {
    status,
    label,
    description,
    showDescription: false,
    mediaType: media?.mediaType ?? '',
    schemaSummary: schemaTypeLabel(media?.content.schema ?? schema),
    shape: schemaShape(schema ?? media?.content.schema),
  };
};

const buildSummary = (pathItem: OpenAPIPathItem, operation: OpenAPIOperation): string => {
  const candidates = [
    operation.summary,
    pathItem.summary,
    operation.description,
    pathItem.description,
  ];

  for (const candidate of candidates) {
    const text = compactDescription(candidate);
    if (text) {
      return text;
    }
  }

  return 'No summary provided.';
};

const routeEntries = computed<Array<RouteEntry>>(() => {
  const paths = spec.value?.paths ?? {};

  return Object.entries(paths)
    .flatMap(([path, pathItem]): Array<RouteEntry> => {
      return METHOD_KEYS.flatMap(({ key, label }): Array<RouteEntry> => {
        const operation = pathItem[key];
        if (!operation) {
          return [];
        }

        const routeKey = `${label}:${path}`;
        const parameters = [...(pathItem.parameters ?? []), ...(operation.parameters ?? [])]
          .map((parameter, index) => summarizeParameter(routeKey, parameter, index))
          .filter((item): item is RouteParameterItem => null !== item);

        const responses = Object.entries(operation.responses ?? {})
          .map(([status, response]) => summarizeResponse(status, response))
          .filter((item): item is RouteResponseItem => null !== item)
          .sort((left, right) =>
            left.status.localeCompare(right.status, undefined, { numeric: true }),
          );

        return [
          {
            key: routeKey,
            method: label,
            path,
            summary: buildSummary(pathItem, operation),
            operationId: operation.operationId?.trim() || '',
            tags: Array.isArray(operation.tags)
              ? operation.tags.filter((tag) => tag.trim().length > 0)
              : [],
            deprecated: true === operation.deprecated,
            parameters,
            requestBody: summarizeRequestBody(operation.requestBody),
            responses,
          },
        ];
      });
    })
    .sort((left, right) => {
      if (left.path === right.path) {
        return left.method.localeCompare(right.method);
      }

      return left.path.localeCompare(right.path);
    });
});

const filteredRoutes = computed<Array<RouteEntry>>(() => {
  const search = query.value.trim().toLowerCase();

  if (!search) {
    return routeEntries.value;
  }

  return routeEntries.value.filter((entry) => {
    const values = [
      entry.method,
      entry.path,
      entry.summary,
      entry.operationId,
      ...entry.tags,
      ...entry.parameters.map(
        (item) => `${item.name} ${item.location} ${item.description} ${item.shape}`,
      ),
      ...(entry.requestBody
        ? [
            `${entry.requestBody.description} ${entry.requestBody.mediaType} ${entry.requestBody.shape} ${entry.requestBody.schemaSummary}`,
          ]
        : []),
      ...entry.responses.map(
        (item) =>
          `${item.status} ${item.description} ${item.mediaType} ${item.shape} ${item.schemaSummary}`,
      ),
    ];

    return values.some((value) => value.toLowerCase().includes(search));
  });
});

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;

  if (!showFilter.value) {
    query.value = '';
    return;
  }

  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const isExpanded = (key: string): boolean => {
  return true === expandedRoutes.value[key];
};

const toggleExpanded = (key: string): void => {
  expandedRoutes.value = {
    ...expandedRoutes.value,
    [key]: !isExpanded(key),
  };
};

const shouldShowShape = (summary: string, shape: string): boolean => {
  if (!shape) {
    return false;
  }

  const normalizedSummary = summary.trim();
  const normalizedShape = shape.trim();

  if (!normalizedSummary) {
    return true;
  }

  return normalizedSummary !== normalizedShape;
};

const loadSpec = async (): Promise<void> => {
  try {
    isLoading.value = true;
    error.value = '';
    spec.value = null;
    expandedRoutes.value = {};

    const response = await fetch(`${specUrl.value}?_=${Date.now()}`);
    if (!response.ok) {
      const err = await parse_api_response<GenericError>(response);
      error.value = `${err.error.code}: ${err.error.message}`;
      return;
    }

    spec.value = (await response.json()) as OpenAPIDocument;
  } catch (caughtError) {
    error.value = caughtError instanceof Error ? caughtError.message : 'Unexpected error';
  } finally {
    isLoading.value = false;
  }
};

const methodColor = (
  method: RouteMethod,
): 'primary' | 'success' | 'warning' | 'error' | 'neutral' => {
  switch (method) {
    case 'GET':
      return 'primary';
    case 'POST':
      return 'success';
    case 'PUT':
    case 'PATCH':
      return 'warning';
    case 'DELETE':
      return 'error';
    default:
      return 'neutral';
  }
};

const responseColor = (status: string): 'success' | 'warning' | 'error' | 'neutral' => {
  const code = Number.parseInt(status, 10);

  if (!Number.isFinite(code)) {
    return 'neutral';
  }

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
};

watch(selectedBackend, async () => await loadSpec(), { immediate: true });
</script>
