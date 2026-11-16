<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || toggleForm"
          @click="loadContent"
        >
          Refresh
        </UButton>
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-plus"
          :disabled="isLoading"
          @click="openAddForm"
        >
          Add variable
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Environment variables" :aria-busy="isLoading" class="space-y-4">
      <UFormField label="Filter variables" name="env-filter" class="w-full sm:max-w-md">
        <UInput
          id="env-filter"
          v-model="query"
          type="search"
          placeholder="Variable name"
          icon="i-lucide-search"
          class="w-full"
        />
      </UFormField>

      <UAlert
        v-if="filteredRows.length < 1 && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading environment variables"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Unable to load environment variables"
        :description="loadError"
      />

      <UEmpty
        v-else-if="filteredRows.length < 1"
        :icon="query ? 'i-lucide-search' : 'i-lucide-variable'"
        :title="query ? 'No matching environment variables' : 'No environment variables'"
      >
        <template v-if="!query" #actions>
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-plus"
            @click="openAddForm"
          >
            Add variable
          </UButton>
        </template>
      </UEmpty>

      <div v-if="0 < filteredRows.length" class="ws-card divide-y divide-default">
        <Lazy v-for="item in filteredRows" :key="item.key" :unrender="true" :min-height="112">
          <article
            :aria-labelledby="`env-${item.key}`"
            class="grid gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto]"
          >
            <div class="min-w-0 space-y-2">
              <div class="flex flex-wrap items-center gap-2">
                <h2
                  :id="`env-${item.key}`"
                  class="wrap-anywhere font-mono text-sm font-semibold text-highlighted"
                >
                  {{ item.key }}
                </h2>
                <UTooltip v-if="item.danger" text="Changes can affect WatchState's security.">
                  <UBadge color="error" variant="soft" size="xs" icon="i-lucide-shield-alert">
                    Security setting
                  </UBadge>
                </UTooltip>
                <UBadge v-if="item.deprecated" color="neutral" variant="soft" size="xs">
                  Deprecated
                </UBadge>
              </div>
              <p class="text-sm text-toned">{{ item.description }}</p>
            </div>

            <div class="flex min-w-0 items-start gap-2">
              <div class="min-w-0 flex-1 py-1 text-sm">
                <UBadge v-if="'bool' === item.type" color="neutral" variant="soft">
                  {{ fixBool(item.value) ? 'On' : 'Off' }}
                </UBadge>
                <span v-else-if="item.displayMasked" class="font-mono text-toned">
                  <span class="sr-only">Hidden value</span>••••••••
                </span>
                <UTooltip v-else :text="item.valueExpanded ? 'Collapse value' : 'Expand value'">
                  <button
                    type="button"
                    class="block w-full text-left font-mono text-xs leading-6 text-default"
                    :class="item.valueExpanded ? 'wrap-anywhere whitespace-pre-wrap' : 'truncate'"
                    :aria-expanded="!!item.valueExpanded"
                    @click="item.valueExpanded = !item.valueExpanded"
                  >
                    {{ item.value }}
                  </button>
                </UTooltip>
              </div>
              <UTooltip
                v-if="item.canMask"
                :text="item.displayMasked ? 'Show value' : 'Hide value'"
              >
                <UButton
                  type="button"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  square
                  class="shrink-0"
                  :icon="item.displayMasked ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                  :aria-label="`${item.displayMasked ? 'Show' : 'Hide'} ${item.key} value`"
                  :aria-pressed="!item.displayMasked"
                  @click="item.displayMasked = !item.displayMasked"
                />
              </UTooltip>
              <UTooltip text="Copy value">
                <UButton
                  type="button"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  square
                  icon="i-lucide-copy"
                  class="shrink-0"
                  :aria-label="`Copy ${item.key} value`"
                  @click="copyText(String(item.value ?? ''))"
                />
              </UTooltip>
            </div>

            <div class="flex flex-wrap items-start gap-2">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-pencil"
                @click="editEnv(item)"
              >
                Edit
              </UButton>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                @click="deleteEnv(item)"
              >
                Delete
              </UButton>
            </div>
          </article>
        </Lazy>
      </div>
    </section>

    <UModal
      :open="toggleForm"
      :title="formModalTitle"
      :ui="formModalUi"
      @update:open="handleFormOpenChange"
    >
      <template #body>
        <form id="env_add_form" class="space-y-5" @submit.prevent="addVariable">
          <UFormField label="Environment key" name="form_key">
            <USelectMenu
              id="form_key"
              v-model="form_key"
              :items="formKeyItems"
              value-key="value"
              placeholder="Search or select key"
              icon="i-lucide-key-round"
              class="w-full"
              @update:model-value="keyChanged"
            />
          </UFormField>

          <UAlert
            v-if="form_config && !value_set(form_key)"
            color="info"
            variant="soft"
            icon="i-lucide-info"
            title="Configuration override"
          >
            <template #description>
              <div class="space-y-2 text-sm text-default">
                <p>This variable overrides the following configuration value:</p>
                <p>
                  <code>{{ form_config }}</code>
                  <span>: </span>
                  <strong v-if="!form_mask">{{ form_config_value }}</strong>
                  <strong v-else class="text-toned italic">Hidden</strong>
                </p>
              </div>
            </template>
          </UAlert>

          <UFormField label="Environment value" name="form_value">
            <template v-if="!value_set(form_key)" #hint>
              <UBadge color="neutral" variant="soft">not set</UBadge>
            </template>

            <div v-if="form_mask && 'string' === form_type" class="space-y-3">
              <UInput
                id="form_value"
                v-model="formStringValue"
                required
                :type="false === form_expose ? 'password' : 'text'"
                placeholder="Value"
                class="w-full"
              >
                <template #trailing>
                  <UTooltip :text="form_expose ? 'Hide value' : 'Show value'">
                    <UButton
                      type="button"
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      :icon="form_expose ? 'i-lucide-eye-off' : 'i-lucide-eye'"
                      :aria-label="form_expose ? 'Hide value' : 'Show value'"
                      :aria-pressed="form_expose"
                      @click="form_expose = !form_expose"
                    />
                  </UTooltip>
                </template>
              </UInput>

              <div
                v-if="form_key"
                class="text-sm leading-6 text-toned"
                v-html="getHelp(form_key)"
              />
            </div>

            <div v-else class="space-y-3">
              <USelectMenu
                v-if="form_choice && form_choice.length > 0"
                id="form_value"
                v-model="formSelectValue"
                :items="formChoiceItems"
                value-key="value"
                placeholder="Select value"
                icon="i-lucide-list"
                class="w-full"
              />

              <div
                v-else-if="'bool' === form_type"
                class="rounded-md border border-default bg-elevated/30 px-3 py-3"
              >
                <USwitch
                  id="form_value"
                  :model-value="fixBool(form_value)"
                  color="neutral"
                  :label="fixBool(form_value) ? 'On' : 'Off'"
                  @update:model-value="updateBoolValue"
                />
              </div>

              <UInput
                v-else-if="'int' === form_type"
                id="form_value"
                v-model="formNumberValue"
                type="number"
                placeholder="Value"
                icon="i-lucide-type"
                pattern="[0-9]*"
                inputmode="numeric"
                class="w-full"
              />

              <UInput
                v-else
                id="form_value"
                v-model="formStringValue"
                type="text"
                placeholder="Value"
                icon="i-lucide-type"
                class="w-full"
              />

              <div
                v-if="form_key"
                class="text-sm leading-6 text-toned"
                v-html="getHelp(form_key)"
              />
            </div>
          </UFormField>
        </form>
      </template>

      <template #footer>
        <div class="flex w-full flex-col gap-2 sm:flex-row sm:justify-end">
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-save"
            class="justify-center"
            type="submit"
            form="env_add_form"
            :disabled="!form_key || '' === form_value"
          >
            Save
          </UButton>
        </div>
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute, useRouter } from '#app';
import {
  UAlert,
  UBadge,
  UButton,
  UEmpty,
  UFormField,
  UInput,
  UModal,
  USelectMenu,
  USwitch,
  UTooltip,
} from '#components';
import { useDialog } from '~/composables/useDialog';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { copyText, notification, parse_api_response, request, ucFirst } from '~/utils';
import type { EnvConfigValue, EnvVar, GenericResponse } from '~/types';

const route = useRoute();
const router = useRouter();

useHead({ title: 'Environment Variables' });

const pageShell = requireTopLevelPageShell('env');

type SelectItem = {
  label: string;
  value: string | number | boolean;
};

type EnvListItem = EnvVar & {
  canMask: boolean;
  displayMasked: boolean;
  valueExpanded?: boolean;
};

const makeEnvItem = (item: EnvVar): EnvListItem => ({
  ...item,
  canMask: item.mask,
  displayMasked: item.mask,
});

const items = ref<Array<EnvListItem>>([]);
const toggleForm = ref<boolean>(false);
const form_key = ref<string>('');
const form_value = ref<string | number | boolean | null>(null);
const form_type = ref<'string' | 'int' | 'bool' | null>(null);
const form_mask = ref<boolean>(false);
const form_expose = ref<boolean>(false);
const form_choice = ref<Array<string>>([]);
const form_config = ref<string | undefined>(undefined);
const form_config_value = ref<string>('');

const isLoading = ref<boolean>(true);
const loadError = ref<string>('');
const query = ref<string>((route.query.filter as string) ?? '');

const formModalUi = {
  content: 'max-w-3xl',
  body: 'space-y-5 p-5',
  footer: 'border-t border-default p-5',
};

const formKeyItems = computed<Array<SelectItem>>(() =>
  items.value.map((item) => ({
    label: item.key,
    value: item.key,
  })),
);

const formChoiceItems = computed<Array<SelectItem>>(() =>
  form_choice.value.map((choice) => ({
    label: ucFirst(String(choice).toLowerCase()),
    value: choice,
  })),
);

const formStringValue = computed<string>({
  get: () => String(form_value.value ?? ''),
  set: (value) => {
    form_value.value = value;
  },
});

const formNumberValue = computed<string>({
  get: () => String(form_value.value ?? ''),
  set: (value) => {
    form_value.value = value;
  },
});

const formSelectValue = computed<string | number | boolean | undefined>({
  get: () => form_value.value ?? undefined,
  set: (value) => {
    form_value.value = value ?? null;
  },
});

const formModalTitle = computed(() =>
  form_key.value ? `Edit ${form_key.value}` : 'Manage Environment Variable',
);

const resetFormData = (): void => {
  form_key.value = '';
  form_value.value = null;
  form_type.value = null;
  form_mask.value = false;
  form_expose.value = false;
  form_choice.value = [];
  form_config.value = undefined;
  form_config_value.value = '';
};

const closeFormRouteState = async (): Promise<void> => {
  const currentRoute = useRoute();

  if (currentRoute.query?.callback) {
    await navigateTo({ path: currentRoute.query.callback as string });
    return;
  }

  if (currentRoute.query?.edit || currentRoute.query?.value) {
    await router.push({ path: '/env' });
  }
};

const handleFormOpenChange = async (value: boolean): Promise<void> => {
  toggleForm.value = value;

  if (false === value) {
    await closeFormRouteState();
  }
};

const openAddForm = (): void => {
  resetFormData();
  toggleForm.value = true;
};

const updateBoolValue = (value: boolean | 'indeterminate'): void => {
  form_value.value = true === value;
};

const loadContent = async (): Promise<void> => {
  const currentRoute = useRoute();
  try {
    isLoading.value = true;
    loadError.value = '';
    items.value = [];
    const response = await request('/system/env');
    const json = await parse_api_response<{ data: Array<EnvVar>; file?: string }>(response);

    if ('error' in json) {
      loadError.value = `${json.error.code}: ${json.error.message}`;
      notification(
        'error',
        'Unable to load environment variables',
        `${json.error.code}: ${json.error.message}`,
        5000,
      );
      return;
    }

    items.value = json.data.map(makeEnvItem);

    if (currentRoute.query.edit) {
      const envItems = items.value as Array<{ key: string; value?: string }>;
      const item = envItems.find((i) => i.key === currentRoute.query.edit) as
        | EnvListItem
        | undefined;
      if (item && currentRoute.query?.value && !item?.value) {
        item.value = currentRoute.query.value as string;
      }
      if (!item) {
        notification(
          'error',
          'Variable not found',
          `Invalid key '${currentRoute.query.edit}'.`,
          2000,
        );
        resetFormData();
        toggleForm.value = false;
        await closeFormRouteState();
      } else {
        editEnv(item);
      }
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    loadError.value = message;
    notification('error', 'Unable to load environment variables', message, 5000);
  } finally {
    isLoading.value = false;
  }
};

const deleteEnv = async (env: EnvVar): Promise<void> => {
  const { status } = await useDialog().confirmDialog({
    title: 'Delete environment variable',
    message: `Delete '${env.key}'?`,
    confirmText: 'Delete',
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  try {
    const response = await request(`/system/env/${env.key}`, { method: 'DELETE' });

    if (200 !== response.status) {
      const json = await parse_api_response<GenericResponse>(response);
      if ('error' in json) {
        notification(
          'error',
          'Variable not deleted',
          `${json.error.code}: ${json.error.message}`,
          5000,
        );
      }
      return;
    }

    const envItems = items.value as Array<{ key: string; value?: unknown }>;
    items.value = envItems.filter((i) => {
      if (i.key === env.key) {
        delete i.value;
      }
      return true;
    }) as Array<EnvListItem>;

    notification('success', 'Variable deleted', `'${env.key}' was removed.`, 5000);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    notification('error', 'Variable not deleted', message, 5000);
  }
};

const addVariable = async (): Promise<void> => {
  const key = form_key.value.toUpperCase();

  if (!key.startsWith('WS_')) {
    notification('error', 'Variable not saved', 'Key must start with WS_');
    return;
  }

  // -- check if value is empty or the same
  if ('' === form_value.value) {
    notification('error', 'Variable not saved', 'Value cannot be empty.', 5000);
    return;
  }

  try {
    const response = await request(`/system/env/${key}`, {
      method: 'POST',
      body: JSON.stringify({ value: form_value.value }),
    });

    if (304 === response.status) {
      resetFormData();
      toggleForm.value = false;
      await closeFormRouteState();
      return;
    }

    const json = await parse_api_response<EnvVar>(response);

    if ('env' !== useRoute().name) {
      return;
    }

    if ('error' in json) {
      notification(
        'error',
        'Variable not saved',
        `${json.error.code}: ${json.error.message}`,
        5000,
      );
      return;
    }

    const envItems = items.value as Array<{ key: string }>;
    const index = envItems.findIndex((i) => i.key === key);
    if (-1 !== index) {
      items.value[index] = makeEnvItem(json);
    }

    notification('success', 'Variable saved', `'${key}' was updated.`, 5000);
    resetFormData();
    toggleForm.value = false;
    await closeFormRouteState();
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    notification('error', 'Variable not saved', message, 5000);
  }
};

const editEnv = (env: EnvVar): void => {
  form_key.value = env.key;
  form_value.value = env.value ?? null;

  if ('undefined' === typeof env.value && 'bool' === env.type) {
    form_value.value = false;
  }

  form_type.value = env.type;
  form_mask.value = env.mask;
  form_choice.value = env.choices || [];
  form_config.value = env.config;
  form_config_value.value =
    env.config_value === undefined ? '' : JSON.stringify(env.config_value as EnvConfigValue);
  form_expose.value = false;

  toggleForm.value = true;
  if (!useRoute().query.edit) {
    router.push({ path: '/env', query: { edit: env.key } });
  }
};

const keyChanged = (): void => {
  if (!form_key.value) {
    return;
  }

  const data = items.value.find((i) => i.key === form_key.value) as EnvListItem | undefined;
  if (!data) {
    return;
  }

  form_choice.value = data.choices || [];
  form_value.value = data.value ?? '';
  form_type.value = data.type || 'string';
  form_mask.value = data.mask || false;
  form_config.value = data.config;
  form_config_value.value =
    data.config_value === undefined ? '' : JSON.stringify(data.config_value as EnvConfigValue);
  form_expose.value = false;

  if ('undefined' === typeof form_value.value && 'bool' === form_type.value) {
    form_value.value = false;
  }

  router.push({ path: '/env', query: { edit: form_key.value } });
};

const getHelp = (key: string): string => {
  if (!key) {
    return '';
  }

  const data = items.value.find((i) => i.key === key);
  if (!data) {
    return '';
  }

  let text = `${data.description}`;

  if (data?.type) {
    text += ` Expects: <code>${data.type}</code>`;
  }

  return data?.deprecated
    ? `<strong><code class="line-through">Deprecated</code></strong> - ${text}`
    : text;
};

const fixBool = (value: string | number | boolean | null | undefined): boolean => {
  if (true === value) {
    return true;
  }

  const normalized = String(value ?? '').toLowerCase();
  return ['true', '1'].includes(normalized);
};

const filteredRows = computed<Array<EnvListItem>>(() => {
  const rows = items.value as Array<{ key: string; value?: unknown }>;
  if (!query.value) {
    return rows.filter((i) => 'undefined' !== typeof i.value) as Array<EnvListItem>;
  }

  return rows
    .filter((i) => i.key.toLowerCase().includes(query.value.toLowerCase()))
    .filter((i) => 'undefined' !== typeof i.value) as Array<EnvListItem>;
});

const stateCallBack = async (e: PopStateEvent): Promise<void> => {
  const eventDetail = (e as { detail?: unknown }).detail;
  if (!e.state && !eventDetail) {
    return;
  }

  const currentRoute = useRoute();
  if (!currentRoute.query?.edit) {
    resetFormData();
    toggleForm.value = false;
    return;
  }

  const item = items.value.find((i) => i.key === currentRoute.query.edit);
  if (item && currentRoute.query?.value && !item?.value) {
    item.value = currentRoute.query.value as string;
  }

  if (item) {
    editEnv(item);
  }
};

onMounted(async () => {
  await loadContent();
  window.addEventListener('popstate', stateCallBack);
});

onUnmounted(() => window.removeEventListener('popstate', stateCallBack));

watch(toggleForm, (value: boolean) => {
  if (!value) {
    resetFormData();
  }
});

const value_set = (key: string): boolean => {
  const item = items.value.find((i) => i.key === key);
  if (!item) {
    return false;
  }
  return 'undefined' !== typeof item.value;
};
</script>
