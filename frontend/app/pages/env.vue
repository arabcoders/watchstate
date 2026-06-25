<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <section class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #actions>
          <UInput
            v-if="toggleFilter || query"
            id="filter"
            v-model="query"
            type="search"
            placeholder="Filter displayed content"
            icon="i-lucide-filter"
            size="sm"
            class="w-full sm:w-72"
          />

          <UButton
            color="neutral"
            :variant="toggleFilter ? 'soft' : 'outline'"
            size="sm"
            icon="i-lucide-filter"
            @click="toggleFilter = !toggleFilter"
          >
            <span class="hidden sm:inline">Filter</span>
          </UButton>

          <UTooltip text="Add new variable">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-plus"
              :disabled="isLoading"
              @click="openAddForm"
            >
              <span class="hidden sm:inline">Add</span>
            </UButton>
          </UTooltip>

          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading || toggleForm"
            @click="loadContent"
          >
            <span class="hidden sm:inline">Reload</span>
          </UButton>
        </template>
      </PageHeader>

      <UAlert
        v-if="filteredRows.length < 1 && isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading data. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="filteredRows.length < 1"
        :color="query ? 'warning' : 'info'"
        variant="soft"
        icon="i-lucide-info"
        :title="query ? 'No results' : 'Information'"
      >
        <template #description>
          <div class="space-y-2 text-sm text-default">
            <p v-if="query">
              No environment variables found matching <strong>{{ query }}</strong
              >. Please try a different filter.
            </p>

            <p v-else class="flex flex-wrap items-center gap-2">
              <span>No environment variables configured yet.</span>
              <UButton
                color="neutral"
                variant="link"
                size="sm"
                icon="i-lucide-plus"
                class="px-0"
                @click="openAddForm"
              >
                Add a new variable
              </UButton>
            </p>
          </div>
        </template>
      </UAlert>

      <div class="grid gap-4 xl:grid-cols-3">
        <UCard
          v-for="item in filteredRows"
          :key="item.key"
          class="h-full shadow-sm"
          :class="item?.danger ? 'border-error/70' : 'border-default/70'"
          :ui="itemCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div class="break-all text-base font-semibold text-highlighted">
                  <span>
                    {{ item.key }}
                  </span>
                </div>

                <p class="mt-1 text-sm text-toned">
                  {{ item.description }}
                </p>
              </div>

              <UTooltip
                v-if="item.danger"
                text="Some variables usually have an impact on the security of the application."
              >
                <UBadge color="error" variant="soft" class="inline-flex items-center gap-1">
                  <UIcon name="i-lucide-triangle-alert" class="size-3.5" />
                  <span>Important</span>
                </UBadge>
              </UTooltip>
            </div>
          </template>

          <template #default>
            <div class="space-y-4 select-none">
              <div
                v-if="'bool' === item.type"
                class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <span
                  class="inline-flex items-center gap-2"
                  :class="fixBool(item.value) ? 'text-success' : 'text-toned'"
                >
                  <UIcon
                    :name="fixBool(item.value) ? 'i-lucide-toggle-right' : 'i-lucide-toggle-left'"
                    class="size-5"
                  />
                  <span>{{ fixBool(item.value) ? 'On (True)' : 'Off (False)' }}</span>
                </span>
              </div>

              <div
                v-else
                class="cursor-pointer rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <p
                  class="break-all overflow-hidden text-ellipsis whitespace-nowrap"
                  :class="item.displayMasked ? 'text-toned italic' : ''"
                  @click="(event) => !item.displayMasked && toggleValueOverflow(event)"
                >
                  <UIcon v-if="item.displayMasked" name="i-lucide-lock" class="size-4 text-toned" />
                  {{ item.displayMasked ? 'Hidden' : item.value }}
                </p>
              </div>
            </div>
          </template>

          <template #footer>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <UButton
                v-if="item.canMask"
                color="neutral"
                variant="outline"
                size="sm"
                :icon="item.displayMasked ? 'i-lucide-lock-open' : 'i-lucide-lock'"
                @click="item.displayMasked = !item.displayMasked"
              >
                {{ item.displayMasked ? 'Show' : 'Hide' }}
              </UButton>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-copy"
                @click="copyText(item.value as string)"
              >
                Copy
              </UButton>

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
          </template>
        </UCard>
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
            <USelect
              id="form_key"
              v-model="form_key"
              :items="formKeyItems"
              value-key="value"
              placeholder="Select Key"
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
                <p>This environment variable overrides the shown configuration key.</p>
                <p>
                  <code>{{ form_config }}</code>
                  <span>: </span>
                  <strong v-if="!form_mask">{{ form_config_value }}</strong>
                  <strong v-else class="text-toned italic">Hidden</strong>
                </p>
              </div>
            </template>
          </UAlert>

          <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
              <label for="form_value" class="text-sm font-medium text-highlighted"
                >Environment value</label
              >
              <UBadge v-if="!value_set(form_key)" color="neutral" variant="soft">not set</UBadge>
            </div>

            <div v-if="form_mask && 'string' === form_type" class="space-y-3">
              <div class="flex flex-col gap-2 sm:flex-row">
                <UInput
                  id="form_value"
                  v-model="formStringValue"
                  required
                  :type="false === form_expose ? 'password' : 'text'"
                  placeholder="Masked value"
                  class="flex-1"
                />

                <UButton
                  type="button"
                  color="neutral"
                  variant="outline"
                  :icon="!form_expose ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                  :aria-label="!form_expose ? 'Show value' : 'Hide value'"
                  class="whitespace-nowrap"
                  @click="form_expose = !form_expose"
                >
                  {{ form_expose ? 'Hide' : 'Show' }}
                </UButton>
              </div>

              <div
                v-if="form_key"
                class="text-sm leading-6 text-toned"
                v-html="getHelp(form_key)"
              />
            </div>

            <div v-else class="space-y-3">
              <USelect
                v-if="form_choice && form_choice.length > 0"
                id="form_value"
                v-model="formSelectValue"
                :items="formChoiceItems"
                value-key="value"
                placeholder="Select Value"
                icon="i-lucide-list"
                class="w-full"
              />

              <div
                v-else-if="'bool' === form_type"
                class="rounded-md border border-default bg-elevated/30 px-3 py-3"
              >
                <USwitch
                  :model-value="fixBool(form_value)"
                  :color="fixBool(form_value) ? 'success' : 'neutral'"
                  :label="fixBool(form_value) ? 'On (True)' : 'Off (False)'"
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
          </div>
        </form>
      </template>

      <template #footer>
        <div class="flex w-full flex-col gap-2 sm:flex-row sm:justify-end">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-x"
            class="justify-center"
            type="button"
            @click="cancelForm"
          >
            Cancel
          </UButton>

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

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </span>

          <span class="inline-flex items-center gap-1 text-xs font-medium text-toned">
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4"
            />
            <span>{{ show_page_tips ? 'Hide' : 'Show' }}</span>
          </span>
        </button>
      </template>

      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li>
          Some variable values are hidden. Use the <strong>Show</strong> or <strong>Hide</strong>
          button on the card to toggle their visibility.
        </li>
        <li>
          Some values are too large to fit into the view, clicking on the value will show the full
          value.
        </li>
        <li>
          These values are loaded from the <code>{{ file }}</code> file.
        </li>
      </ul>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute, useRouter } from '#app';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDirtyState } from '~/composables/useDirtyState';
import { useDialog } from '~/composables/useDialog';
import { useStorage } from '@vueuse/core';
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

type EnvCardItem = EnvVar & {
  canMask: boolean;
  displayMasked: boolean;
};

const makeCardItem = (item: EnvVar): EnvCardItem => ({
  ...item,
  canMask: item.mask,
  displayMasked: item.mask,
});

const items = ref<Array<EnvCardItem>>([]);
const toggleForm = ref<boolean>(false);
const form_key = ref<string>('');
const form_value = ref<string | number | boolean | null>(null);
const form_type = ref<'string' | 'int' | 'bool' | null>(null);
const form_mask = ref<boolean>(false);
const form_expose = ref<boolean>(false);
const form_choice = ref<Array<string>>([]);
const form_config = ref<string | undefined>(undefined);
const form_config_value = ref<string>('');

const show_page_tips = useStorage('show_page_tips', true);
const isLoading = ref<boolean>(true);
const file = ref<string>('.env');
const query = ref<string>((route.query.filter as string) ?? '');
const toggleFilter = ref<boolean>(false);

const formModalUi = {
  content: 'max-w-3xl',
  body: 'space-y-5 p-5',
  footer: 'border-t border-default p-5',
};

const itemCardUi = {
  header: 'p-5',
  body: 'p-5',
  footer: 'p-5 border-t border-default',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'p-5 pt-0',
};

const dirtySource = computed(() => ({
  key: form_key.value,
  value: form_value.value,
  type: form_type.value,
  mask: form_mask.value,
  choice: form_choice.value,
  config: form_config.value,
  config_value: form_config_value.value,
}));
const { isDirty: isFormDirty, markClean: markFormClean } = useDirtyState(dirtySource);

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
  markFormClean();
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

const { requestClose: requestCloseForm } = useDirtyCloseGuard(toggleForm, {
  dirty: isFormDirty,
  onDiscard: async () => {
    resetFormData();
  },
});

const requestCloseEnvForm = async (): Promise<boolean> => {
  const didClose = await requestCloseForm();

  if (true === didClose) {
    await closeFormRouteState();
  }

  return didClose;
};

const handleFormOpenChange = async (value: boolean): Promise<void> => {
  if (true === value) {
    toggleForm.value = true;
    return;
  }

  await requestCloseEnvForm();
};

const openAddForm = (): void => {
  resetFormData();
  toggleForm.value = true;
};

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = '';
  }
});

const updateBoolValue = (value: boolean | 'indeterminate'): void => {
  form_value.value = true === value;
};

const loadContent = async (): Promise<void> => {
  const currentRoute = useRoute();
  try {
    isLoading.value = true;
    items.value = [];
    const response = await request('/system/env');
    const json = await parse_api_response<{ data: Array<EnvVar>; file?: string }>(response);

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    items.value = json.data.map((item) => makeCardItem(item));

    if (json.file) {
      file.value = json.file;
    }

    if (currentRoute.query.edit) {
      const envItems = items.value as Array<{ key: string; value?: string }>;
      const item = envItems.find((i) => i.key === currentRoute.query.edit) as
        | EnvCardItem
        | undefined;
      if (item && currentRoute.query?.value && !item?.value) {
        item.value = currentRoute.query.value as string;
      }
      if (!item) {
        notification('error', 'Error', `Invalid key '${currentRoute.query.edit}'.`, 2000);
        resetFormData();
        toggleForm.value = false;
        await closeFormRouteState();
      } else {
        editEnv(item);
      }
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    notification('error', 'Error', `Error. ${message}`, 5000);
  } finally {
    isLoading.value = false;
  }
};

const deleteEnv = async (env: EnvVar): Promise<void> => {
  const { status } = await useDialog().confirmDialog({
    title: 'Delete environment variable',
    message: `Delete '${env.key}'?`,
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
        notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      }
      return;
    }

    const envItems = items.value as Array<{ key: string; value?: unknown }>;
    items.value = envItems.filter((i) => {
      if (i.key === env.key) {
        delete i.value;
      }
      return true;
    }) as Array<EnvCardItem>;

    notification(
      'success',
      'Success',
      `Environment variable '${env.key}' successfully deleted.`,
      5000,
    );
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  }
};

const addVariable = async (): Promise<void> => {
  const key = form_key.value.toUpperCase();

  if (!key.startsWith('WS_')) {
    notification('error', 'Error', 'Key must start with WS_');
    return;
  }

  // -- check if value is empty or the same
  if ('' === form_value.value) {
    notification('error', 'Error', 'Value cannot be empty.', 5000);
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
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    const envItems = items.value as Array<{ key: string }>;
    const index = envItems.findIndex((i) => i.key === key);
    if (-1 !== index) {
      items.value[index] = makeCardItem(json);
    }

    notification('success', 'Success', `Environment variable '${key}' successfully updated.`, 5000);
    resetFormData();
    toggleForm.value = false;
    await closeFormRouteState();
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
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
  markFormClean();
  if (!useRoute().query.edit) {
    router.push({ path: '/env', query: { edit: env.key } });
  }
};

const cancelForm = async (): Promise<void> => {
  await requestCloseEnvForm();
};

const keyChanged = (): void => {
  if (!form_key.value) {
    return;
  }

  const data = items.value.find((i) => i.key === form_key.value) as EnvCardItem | undefined;
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

  nextTick(() => {
    if ('undefined' === typeof form_value.value && 'bool' === form_type.value) {
      form_value.value = false;
    }

    markFormClean();
  });

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

const toggleValueOverflow = (event: MouseEvent): void => {
  const target = event.target as HTMLElement | null;

  target?.classList.toggle('overflow-hidden');
  target?.classList.toggle('text-ellipsis');
  target?.classList.toggle('whitespace-nowrap');
};

const fixBool = (value: string | number | boolean | null | undefined): boolean => {
  if (true === value) {
    return true;
  }

  const normalized = String(value ?? '').toLowerCase();
  return ['true', '1'].includes(normalized);
};

const filteredRows = computed<Array<EnvCardItem>>(() => {
  const rows = items.value as Array<{ key: string; value?: unknown }>;
  if (!query.value) {
    return rows.filter((i) => 'undefined' !== typeof i.value) as Array<EnvCardItem>;
  }

  return rows
    .filter((i) => i.key.toLowerCase().includes(query.value.toLowerCase()))
    .filter((i) => 'undefined' !== typeof i.value) as Array<EnvCardItem>;
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

markFormClean();
</script>
