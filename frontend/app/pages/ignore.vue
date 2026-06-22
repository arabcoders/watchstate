<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UTooltip text="Add new ignore rule">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-plus"
            @click="openAddForm"
            label="Add"
          />
        </UTooltip>

        <UTooltip text="Reload rules">
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading"
            @click="loadContent"
            label="Reload"
          />
        </UTooltip>
      </template>
    </PageHeader>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="0 === items.length"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="No ignore rules"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p>
            There are no ignore rules configured.
            <UButton
              color="primary"
              variant="link"
              size="sm"
              icon="i-lucide-plus"
              class="px-0"
              @click="openAddForm"
            >
              Add a new rule
            </UButton>
          </p>
        </div>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard v-for="item in items" :key="item.rule" class="h-full shadow-sm" :ui="ruleCardUi">
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex flex-1 items-start gap-2">
              <UIcon :name="getItemTypeIcon(item.type)" class="mt-0.5 size-4 shrink-0 text-toned" />

              <div class="min-w-0">
                <UTooltip :text="String(getItemTitle(item))">
                  <div class="truncate text-base font-semibold text-highlighted">
                    {{ getItemTitle(item) }}
                  </div>
                </UTooltip>
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-copy"
                @click="copyText(item.rule)"
              >
                <span class="hidden sm:inline">Copy</span>
              </UButton>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                @click="deleteIgnore(item)"
                label="Delete"
              />
            </div>
          </div>
        </template>

        <div class="grid grid-cols-2 gap-3">
          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-server" class="size-4" />
              <span>Backend</span>
            </div>

            <div class="text-sm text-default">
              <NuxtLink :to="`/backend/${item.backend}`" class="hover:text-primary">{{
                item.backend
              }}</NuxtLink>
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-crosshair" class="size-4" />
              <span>Scoped To</span>
            </div>

            <div
              class="text-sm text-default"
              :class="item.scoped_to ? expandableInlineClass(item.expandScopedTo, true) : ''"
              @click="item.scoped_to ? (item.expandScopedTo = !item.expandScopedTo) : undefined"
            >
              <NuxtLink v-if="item.scoped_to" :to="makeItemLink(item)" class="hover:text-primary">
                {{ item.scoped_to }}
              </NuxtLink>
              <span v-else>Global</span>
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-database" class="size-4" />
              <span>GUID</span>
            </div>

            <div
              class="text-sm text-default"
              :class="expandableInlineClass(item.expandGuid, true)"
              @click="item.expandGuid = !item.expandGuid"
            >
              <NuxtLink
                target="_blank"
                :to="makeGUIDLink(item.type, item.db, item.id)"
                class="hover:text-primary"
              >
                {{ `${item.db}://${item.id}` }}
              </NuxtLink>
            </div>
          </div>

          <div class="rounded-md border border-default bg-elevated/20 px-3 py-3">
            <div class="mb-1 inline-flex items-center gap-2 text-xs font-medium text-toned">
              <UIcon name="i-lucide-calendar" class="size-4" />
              <span>Created</span>
            </div>

            <div class="text-sm text-default">
              <UTooltip :text="`Created at: ${moment(item.created).format(TOOLTIP_DATE_FORMAT)}`">
                <span class="cursor-help">{{ moment(item.created).fromNow() }}</span>
              </UTooltip>
            </div>
          </div>
        </div>
      </UCard>
    </div>

    <UModal
      :open="toggleForm"
      title="Add Ignore rule"
      :ui="formModalUi"
      @update:open="handleFormOpenChange"
    >
      <template #body>
        <form id="ignore_form" class="space-y-5" @submit.prevent="addIgnoreRule">
          <UFormField
            label="Backend"
            name="form_select_backend"
            description="Ignore rules apply to backends. You must select the correct backend you want to ignore the GUID from."
          >
            <USelect
              id="form_select_backend"
              v-model="form.backend"
              :items="backendItems"
              value-key="value"
              placeholder="Select Backend"
              icon="i-lucide-server"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Provider"
            name="form_select_guid"
            description="You must select the GUID provider that is giving you incorrect data."
          >
            <USelect
              id="form_select_guid"
              v-model="form.db"
              :items="guidItems"
              value-key="value"
              placeholder="Select GUID provider"
              icon="i-lucide-database"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="GUID value"
            name="form_ignore_id"
            description="The GUID value to ignore."
          >
            <UInput
              id="form_ignore_id"
              v-model="form.id"
              type="text"
              icon="i-lucide-type"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Type"
            name="form_type"
            description="What kind of data does the GUID value reference?"
          >
            <USelect
              id="form_type"
              v-model="form.type"
              :items="typeItems"
              value-key="value"
              placeholder="Select type"
              icon="i-lucide-clapperboard"
              class="w-full"
            />
          </UFormField>

          <div class="rounded-md border border-default bg-elevated/30 px-3 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <div class="text-sm font-medium text-highlighted">Scoped rule</div>
                <p class="mt-1 text-sm leading-6 text-toned">
                  By default, rules are globally applied to all items from the selected backend. You
                  can limit the scope by enabling this option.
                </p>
              </div>

              <USwitch
                :model-value="form.scoped"
                color="neutral"
                @update:model-value="(value) => (form.scoped = true === value)"
              />
            </div>
          </div>

          <UFormField v-if="form.scoped" label="Scoped to" name="form_scoped_to">
            <UInput
              id="form_scoped_to"
              v-model="scopedToValue"
              type="text"
              icon="i-lucide-type"
              class="w-full"
            />

            <p class="text-sm leading-6 text-toned">
              The id to associate this rule with. The value must be the <code>{{ form.type }}</code>
              id as being reported by the backend.
            </p>
          </UFormField>
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
            form="ignore_form"
            :disabled="false === checkForm"
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
          Ignoring specific GUIDs sometimes helps prevent incorrect data being added to WatchState,
          due to incorrect metadata being provided by backends.
        </li>
        <li>
          <code>GUID</code> means, in terms of WatchState, the unique identifier for a specific item
          in the external data source.
        </li>
      </ul>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDialog } from '~/composables/useDialog';
import { useDirtyState } from '~/composables/useDirtyState';
import type { GenericResponse, GuidProvider, IgnoreItem } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  copyText,
  makeGUIDLink,
  notification,
  parse_api_response,
  request,
  stringToRegex,
  TOOLTIP_DATE_FORMAT,
  ucFirst,
} from '~/utils';

type BackendItem = {
  name: string;
  type?: string;
};

type IgnoreFormState = {
  id: string;
  type: string;
  backend: string;
  db: string;
  scoped: boolean;
  scoped_to: string | null;
};

type SelectItem = {
  label: string;
  value: string;
};

type IgnoreCardItem = IgnoreItem & {
  expandScopedTo?: boolean;
  expandGuid?: boolean;
};

useHead({ title: 'Ignore rules' });

const pageShell = requireTopLevelPageShell('ignore');

const route = useRoute();
const dialog = useDialog();

const types = ['show', 'movie', 'episode'];
const defaultForm = (): IgnoreFormState => ({
  id: '',
  type: '',
  backend: '',
  db: '',
  scoped: false,
  scoped_to: null,
});

const makeCardItem = (item: IgnoreItem): IgnoreCardItem => ({
  ...item,
  expandScopedTo: false,
  expandGuid: false,
});

const items = ref<Array<IgnoreCardItem>>([]);
const toggleForm = ref<boolean>(false);
const form = ref<IgnoreFormState>(defaultForm());
const show_page_tips = useStorage<boolean>('show_page_tips', true);
const isLoading = ref<boolean>(false);
const guids = ref<Array<GuidProvider>>([]);
const backends = ref<Array<BackendItem>>([]);

const ruleCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const formModalUi = {
  content: 'max-w-3xl',
  body: 'space-y-5 p-5',
  footer: 'border-t border-default p-5',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const backendItems = computed<Array<SelectItem>>(() =>
  backends.value.map((backend) => ({
    label: backend.name,
    value: backend.name,
  })),
);

const guidItems = computed<Array<SelectItem>>(() =>
  guids.value.map((guid) => ({
    label: guid.guid,
    value: guid.guid,
  })),
);

const typeItems = computed<Array<SelectItem>>(() =>
  types.map((type) => ({
    label: ucFirst(type),
    value: type,
  })),
);

const scopedToValue = computed<string>({
  get: () => form.value.scoped_to ?? '',
  set: (value) => {
    form.value.scoped_to = '' === value ? null : value;
  },
});

const dirtySource = computed(() => ({
  id: form.value.id,
  type: form.value.type,
  backend: form.value.backend,
  db: form.value.db,
  scoped: form.value.scoped,
  scoped_to: form.value.scoped_to,
}));
const { isDirty: isFormDirty, markClean: markFormClean } = useDirtyState(dirtySource);

const expandableInlineClass = (expanded?: boolean, allowBreakAll = false): string => {
  if (true === expanded) {
    return allowBreakAll ? 'break-all' : 'break-words';
  }

  return allowBreakAll ? 'ws-expandable-inline-breakall' : 'ws-expandable-inline';
};

const resetFormData = (): void => {
  form.value = defaultForm();
  markFormClean();
};

const { handleOpenChange: handleFormOpenChange, requestClose: requestCloseForm } =
  useDirtyCloseGuard(toggleForm, {
    dirty: isFormDirty,
    onDiscard: async () => {
      resetFormData();
    },
  });

const openAddForm = (): void => {
  resetFormData();
  toggleForm.value = true;
};

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  items.value = [];

  try {
    if (0 === guids.value.length) {
      const guidRequest = await request('/system/guids');
      const guidResponse = await parse_api_response<Array<GuidProvider>>(guidRequest);

      if ('ignore' !== route.name) {
        return;
      }

      if ('error' in guidResponse) {
        notification('error', 'Error', `${guidResponse.error.code}: ${guidResponse.error.message}`);
        return;
      }

      guids.value = guidResponse;
    }

    if (0 === backends.value.length) {
      const backendsRequest = await request('/backends');
      const backendsResponse = await parse_api_response<Array<BackendItem>>(backendsRequest);

      if ('ignore' !== route.name) {
        return;
      }

      if ('error' in backendsResponse) {
        notification(
          'error',
          'Error',
          `${backendsResponse.error.code}: ${backendsResponse.error.message}`,
        );
        return;
      }

      backends.value = backendsResponse;
    }

    const response = await request('/ignore');
    const json = await parse_api_response<Array<IgnoreItem>>(response);

    if ('ignore' !== route.name) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`);
      return;
    }

    items.value = json.map((item) => makeCardItem(item));
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(() => void loadContent());

const deleteIgnore = async (item: IgnoreItem): Promise<void> => {
  const { status: confirmStatus } = await dialog.confirmDialog({
    message: `Delete '${item.db}://${item.id}' rule?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    const response = await request('/ignore', {
      method: 'DELETE',
      body: JSON.stringify({
        rule: item.rule,
      }),
    });

    if (response.ok) {
      items.value = items.value.filter((currentItem) => currentItem.rule !== item.rule);
      notification('success', 'Success', `Ignore rule '${item.rule}' successfully deleted.`, 5000);
      return;
    }

    const json = await parse_api_response<GenericResponse>(response);
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    notification('error', 'Error', 'Failed to delete ignore rule.', 5000);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  }
};

const makeItemLink = (item: IgnoreItem): string => {
  if (!item.scoped_to) {
    return '';
  }

  const type = 'show' === item.type.toLowerCase() ? 'show' : 'id';
  const params = new URLSearchParams();
  params.append('perpage', '50');
  params.append('page', '1');
  params.append('q', `${item.backend}.${type}://${item.scoped_to}`);
  params.append('key', 'metadata');

  return `/history?${params.toString()}`;
};

const addIgnoreRule = async (): Promise<void> => {
  const provider = guids.value.find((guid) => guid.guid === form.value.db);
  if (provider?.validator?.pattern) {
    if (!stringToRegex(provider.validator.pattern).test(form.value.id)) {
      notification(
        'error',
        'Error',
        `Invalid GUID value, must match the pattern: '${provider.validator.pattern}'. Example ${provider.validator.example}`,
        5000,
      );
      return;
    }
  }

  try {
    const response = await request('/ignore', {
      method: 'POST',
      body: JSON.stringify(form.value),
    });

    const json = await parse_api_response<IgnoreItem>(response);
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    items.value.push(makeCardItem(json));
    notification('success', 'Success', 'Successfully added new ignore rule.', 5000);
    resetFormData();
    toggleForm.value = false;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
  }
};

const cancelForm = async (): Promise<void> => {
  await requestCloseForm();
};

watch(toggleForm, (value: boolean) => {
  if (!value) {
    resetFormData();
  }
});

const checkForm = computed<boolean>(() => {
  const { id, type, backend, db } = form.value;
  return '' !== id && '' !== type && '' !== backend && '' !== db;
});

const getItemTypeIcon = (type: string): string => {
  switch (type.toLowerCase()) {
    case 'show':
      return 'i-lucide-tv';
    case 'episode':
      return 'i-lucide-clapperboard';
    default:
      return 'i-lucide-film';
  }
};

const getItemTitle = (item: IgnoreItem): string =>
  item.title || (item.scoped ? 'Unknown title' : 'Global');

markFormClean();
</script>
