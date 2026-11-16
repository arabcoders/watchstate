<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="Exclude incorrect GUIDs from matching on a backend."
    >
      <template #kicker>
        <span>{{ pageShell.pageLabel }}</span>
        <span>/</span>
        <span>{{ ucFirst(apiUser) }}</span>
      </template>
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || isSaving || null !== deletingRule"
          @click="() => void loadContent()"
        >
          Reload
        </UButton>
        <UButton
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-plus"
          :disabled="isLoading || !!loadError || isSaving || null !== deletingRule"
          @click="openAddForm"
        >
          Add rule
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Ignore rules" :aria-busy="isLoading" class="space-y-4">
      <div v-if="0 < items.length" class="flex flex-wrap items-end justify-between gap-3">
        <UFormField label="Filter rules" name="ignore_filter" class="w-full sm:max-w-sm">
          <UInput
            id="ignore_filter"
            v-model="filter"
            icon="i-lucide-search"
            placeholder="GUID, backend, type or source item"
            class="w-full"
          />
        </UFormField>
        <p v-if="!isLoading && !loadError" class="text-sm text-toned" aria-live="polite">
          {{ filteredItems.length }}{{ filter.trim() ? ` of ${items.length}` : '' }}
          {{ 1 === items.length ? 'rule' : 'rules' }}
        </p>
      </div>

      <UAlert
        v-if="isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading ignore rules"
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Unable to load ignore rules"
        :description="loadError"
      />

      <UEmpty v-else-if="0 === items.length" icon="i-lucide-list-filter" title="No ignore rules" />

      <UEmpty
        v-else-if="0 === filteredItems.length"
        icon="i-lucide-search"
        title="No matching rules"
      />

      <div v-else class="ws-card divide-y divide-default">
        <article
          v-for="item in filteredItems"
          :key="item.rule"
          class="grid grid-cols-2 gap-4 p-4 sm:p-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.6fr)_auto]"
        >
          <div class="col-span-2 min-w-0 space-y-2 xl:col-span-1">
            <h2 class="wrap-anywhere font-mono text-base font-semibold text-highlighted">
              <NuxtLink
                v-if="makeGUIDLink(item.type, item.db, item.id)"
                :to="makeGUIDLink(item.type, item.db, item.id)"
                target="_blank"
                rel="noopener noreferrer"
                class="hover:text-primary hover:underline underline-offset-2"
                :aria-label="`View ${item.guid} (opens in a new tab)`"
              >
                {{ item.guid }}
              </NuxtLink>
              <span v-else>{{ item.guid }}</span>
            </h2>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-toned">
              <span class="inline-flex items-center gap-1.5">
                <UIcon :name="getItemTypeIcon(item.type)" class="size-4" />
                {{ item.type }}
              </span>
              <span aria-hidden="true">·</span>
              <NuxtLink
                :to="`/backend/${encodeURIComponent(item.backend)}`"
                class="text-primary hover:underline underline-offset-2"
              >
                {{ item.backend }}
              </NuxtLink>
            </div>
          </div>

          <dl class="min-w-0 space-y-1 text-sm">
            <dt class="text-xs text-muted">Source item</dt>
            <dd v-if="item.scoped" class="space-y-1">
              <NuxtLink
                :to="makeItemLink(item)"
                class="wrap-anywhere font-mono text-primary hover:underline underline-offset-2"
              >
                {{ item.scoped_to }}
              </NuxtLink>
              <p v-if="item.title" class="wrap-anywhere text-xs text-toned">
                {{ item.title }}
              </p>
            </dd>
            <dd v-else>All items</dd>
          </dl>

          <dl class="min-w-0 space-y-1 text-sm">
            <dt class="text-xs text-muted">Created</dt>
            <dd>
              <UTooltip :text="moment(item.created).format(TOOLTIP_DATE_FORMAT)">
                <time :datetime="item.created" class="cursor-help">
                  {{ moment(item.created).fromNow() }}
                </time>
              </UTooltip>
            </dd>
          </dl>

          <div class="col-span-2 flex flex-wrap items-start gap-2 xl:col-span-1 xl:justify-end">
            <UTooltip text="Copy rule">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-copy"
                :aria-label="`Copy rule for ${item.guid}`"
                @click="copyText(item.rule)"
              />
            </UTooltip>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :loading="item.rule === deletingRule"
              :disabled="isSaving || null !== deletingRule"
              :aria-label="`Delete rule for ${item.guid}`"
              @click="() => void deleteIgnore(item)"
            >
              Delete
            </UButton>
          </div>
        </article>
      </div>
    </section>

    <UModal
      :open="toggleForm"
      title="Add ignore rule"
      :ui="formModalUi"
      :dismissible="!isSaving"
      :close="!isSaving"
      @update:open="handleFormOpenChange"
    >
      <template #body>
        <form id="ignore_form" class="space-y-5" @submit.prevent="addIgnoreRule">
          <UAlert
            color="warning"
            variant="soft"
            icon="i-lucide-triangle-alert"
            title="Ignore only incorrect GUIDs"
            description="Ignoring a valid GUID can prevent items from matching."
          />

          <fieldset :disabled="isSaving" class="space-y-5">
            <UFormField label="Backend" name="form_select_backend">
              <USelect
                id="form_select_backend"
                v-model="form.backend"
                :items="backendItems"
                value-key="value"
                placeholder="Select backend"
                icon="i-lucide-server"
                class="w-full"
              />
            </UFormField>

            <UFormField label="Provider" name="form_select_guid">
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

            <UFormField label="GUID value" name="form_ignore_id">
              <UInput
                id="form_ignore_id"
                v-model="form.id"
                type="text"
                icon="i-lucide-type"
                class="w-full"
              />
            </UFormField>

            <UFormField label="Type" name="form_type">
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

            <USwitch
              v-model="form.scoped"
              color="neutral"
              label="Limit to one source item"
              description="Otherwise, this rule applies to all matching items on this backend for this identity."
            />

            <UFormField
              v-if="form.scoped"
              label="Source item ID"
              name="form_scoped_to"
              :description="`Use the ${form.type || 'item'} ID reported by the selected backend, not the WatchState history ID.`"
              required
            >
              <UInput
                id="form_scoped_to"
                v-model="scopedToValue"
                type="text"
                icon="i-lucide-type"
                class="w-full"
                required
              />
            </UFormField>
          </fieldset>
        </form>
      </template>

      <template #footer>
        <div class="flex w-full justify-end">
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-save"
            type="submit"
            form="ignore_form"
            :loading="isSaving"
            :disabled="!checkForm || isSaving"
          >
            Save
          </UButton>
        </div>
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useHead } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import PageHeader from '~/components/PageHeader.vue';
import {
  NuxtLink,
  UAlert,
  UButton,
  UEmpty,
  UFormField,
  UIcon,
  UInput,
  UModal,
  USelect,
  USwitch,
  UTooltip,
} from '#components';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDialog } from '~/composables/useDialog';
import { useDirtyState } from '~/composables/useDirtyState';
import type { Backend, GenericResponse, GuidProvider, IgnoreItem } from '~/types';
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

useHead({ title: 'Ignore rules' });

const pageShell = requireTopLevelPageShell('ignore');
const apiUser = useStorage<string>('api_user', 'main');
const dialog = useDialog();
const types = ['show', 'movie', 'episode'];
const defaultForm = () => ({
  id: '',
  type: '',
  backend: '',
  db: '',
  scoped: false,
  scoped_to: null as string | null,
});

const items = ref<Array<IgnoreItem>>([]);
const filter = ref('');
const toggleForm = ref(false);
const form = ref(defaultForm());
const isLoading = ref(true);
const loadError = ref('');
const isSaving = ref(false);
const deletingRule = ref<string | null>(null);
const guids = ref<Array<GuidProvider>>([]);
const backends = ref<Array<Pick<Backend, 'name'>>>([]);
let loadController: AbortController | null = null;
let loadId = 0;
let identityVersion = 0;
let disposed = false;

const formModalUi = {
  content: 'max-w-3xl',
  body: 'p-5',
  footer: 'border-t border-default p-5',
};

const filteredItems = computed(() => {
  const rules = items.value.map((item) => ({
    ...item,
    guid: `${item.db}://${item.id}`,
  }));
  const query = filter.value.trim().toLowerCase();
  if (!query) {
    return rules;
  }

  return rules.filter((item) =>
    [
      item.rule,
      item.guid,
      item.backend,
      item.type,
      item.title,
      item.scoped_to,
      item.scoped ? '' : 'All items',
    ].some((value) =>
      String(value ?? '')
        .toLowerCase()
        .includes(query),
    ),
  );
});

const backendItems = computed(() =>
  backends.value.map((backend) => ({
    label: backend.name,
    value: backend.name,
  })),
);

const guidItems = computed(() =>
  guids.value.map((guid) => ({
    label: guid.guid,
    value: guid.guid,
  })),
);

const typeItems = computed(() =>
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

const { isDirty: isFormDirty, markClean: markFormClean } = useDirtyState(form);

const resetFormData = (): void => {
  form.value = defaultForm();
  markFormClean();
};

const { handleOpenChange: handleFormOpenChange } = useDirtyCloseGuard(toggleForm, {
  dirty: isFormDirty,
  confirmColor: 'warning',
  onDiscard: resetFormData,
});

const openAddForm = (): void => {
  resetFormData();
  toggleForm.value = true;
};

const loadContent = async (): Promise<void> => {
  if (disposed) {
    return;
  }

  loadController?.abort();
  const controller = new AbortController();
  loadController = controller;
  const id = ++loadId;
  const identity = apiUser.value;
  isLoading.value = true;
  loadError.value = '';

  try {
    if (0 === guids.value.length) {
      const response = await request('/system/guids', {
        signal: controller.signal,
        headers: { 'X-User': identity },
      });
      const json = await parse_api_response<Array<GuidProvider>>(response);
      if (disposed || id !== loadId || identity !== apiUser.value) {
        return;
      }
      if ('error' in json) {
        throw new Error(`${json.error.code}: ${json.error.message}`);
      }

      guids.value = json;
    }

    if (0 === backends.value.length) {
      const response = await request('/backends', {
        signal: controller.signal,
        headers: { 'X-User': identity },
      });
      const json = await parse_api_response<Array<Pick<Backend, 'name'>>>(response);
      if (disposed || id !== loadId || identity !== apiUser.value) {
        return;
      }
      if ('error' in json) {
        throw new Error(`${json.error.code}: ${json.error.message}`);
      }

      backends.value = json;
    }

    const response = await request('/ignore', {
      signal: controller.signal,
      headers: { 'X-User': identity },
    });
    if (disposed || id !== loadId || identity !== apiUser.value) {
      return;
    }
    if (304 === response.status) {
      return;
    }
    if (204 === response.status) {
      items.value = [];
      return;
    }

    const json = await parse_api_response<Array<IgnoreItem>>(response);
    if (disposed || id !== loadId || identity !== apiUser.value) {
      return;
    }
    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }

    items.value = json;
  } catch (error: unknown) {
    if (disposed || id !== loadId || identity !== apiUser.value || controller.signal.aborted) {
      return;
    }

    loadError.value = error instanceof Error ? error.message : 'Unexpected request error';
    notification('error', 'Ignore rules unavailable', loadError.value);
  } finally {
    if (!disposed && id === loadId) {
      isLoading.value = false;
    }
  }
};

const deleteIgnore = async (item: IgnoreItem): Promise<void> => {
  if (
    disposed ||
    isLoading.value ||
    loadError.value ||
    isSaving.value ||
    null !== deletingRule.value
  ) {
    return;
  }

  const identity = apiUser.value;
  const version = identityVersion;
  const rule = item.rule;
  const guid = `${item.db}://${item.id}`;
  deletingRule.value = rule;

  try {
    const { status } = await dialog.confirmDialog({
      title: 'Delete ignore rule',
      message: `Delete '${rule}' for identity '${identity}'?`,
      confirmText: 'Delete rule',
      confirmColor: 'error',
    });

    if (
      true !== status ||
      disposed ||
      version !== identityVersion ||
      identity !== apiUser.value ||
      !items.value.some((currentItem) => rule === currentItem.rule)
    ) {
      return;
    }

    const response = await request('/ignore', {
      method: 'DELETE',
      headers: { 'X-User': identity },
      body: JSON.stringify({ rule }),
    });
    if (disposed || version !== identityVersion || identity !== apiUser.value) {
      return;
    }

    if (!response.ok) {
      const json = await parse_api_response<GenericResponse>(response);
      if (disposed || version !== identityVersion || identity !== apiUser.value) {
        return;
      }
      throw new Error('error' in json ? json.error.message : 'Failed to delete ignore rule.');
    }

    items.value = items.value.filter((currentItem) => rule !== currentItem.rule);
    notification('success', 'Rule deleted', `'${guid}' was removed from '${identity}'.`);
  } catch (error: unknown) {
    if (!disposed && version === identityVersion && identity === apiUser.value) {
      const message = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Rule not deleted', message);
    }
  } finally {
    if (!disposed && version === identityVersion) {
      deletingRule.value = null;
    }
  }
};

const makeItemLink = (item: IgnoreItem): string => {
  if (null === item.scoped_to || undefined === item.scoped_to) {
    return '';
  }

  const type = 'show' === item.type.toLowerCase() ? 'show' : 'id';
  const params = new URLSearchParams({
    perpage: '50',
    page: '1',
    q: `${item.backend}.${type}://${item.scoped_to}`,
    key: 'metadata',
  });

  return `/history?${params.toString()}`;
};

const addIgnoreRule = async (): Promise<void> => {
  if (disposed || !checkForm.value || isSaving.value || null !== deletingRule.value) {
    return;
  }

  const identity = apiUser.value;
  const version = identityVersion;
  const payload = { ...form.value };
  isSaving.value = true;

  try {
    const provider = guids.value.find((guid) => guid.guid === payload.db);
    if (
      provider?.validator?.pattern &&
      !stringToRegex(provider.validator.pattern).test(payload.id)
    ) {
      notification(
        'error',
        'Invalid GUID',
        `Value must match '${provider.validator.pattern}'. Example: ${provider.validator.example}`,
        5000,
      );
      return;
    }

    const response = await request('/ignore', {
      method: 'POST',
      headers: { 'X-User': identity },
      body: JSON.stringify(payload),
    });
    const json = await parse_api_response<IgnoreItem>(response);
    if (disposed || version !== identityVersion || identity !== apiUser.value) {
      return;
    }
    if ('error' in json) {
      throw new Error(`${json.error.code}: ${json.error.message}`);
    }

    items.value.push(json);
    notification('success', 'Rule added', `Ignore rule added to '${identity}'.`);
    resetFormData();
    toggleForm.value = false;
  } catch (error: unknown) {
    if (!disposed && version === identityVersion && identity === apiUser.value) {
      const message = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Rule not added', message);
    }
  } finally {
    if (!disposed && version === identityVersion) {
      isSaving.value = false;
    }
  }
};

watch(toggleForm, (value) => {
  if (!value) {
    resetFormData();
  }
});

watch(
  apiUser,
  () => {
    identityVersion++;
    loadId++;
    loadController?.abort();
    items.value = [];
    backends.value = [];
    isSaving.value = false;
    deletingRule.value = null;
    toggleForm.value = false;
    resetFormData();
    void loadContent();
  },
  { flush: 'sync' },
);

const checkForm = computed(() => {
  const { id, type, backend, db, scoped, scoped_to } = form.value;
  return (
    '' !== id.trim() &&
    '' !== type &&
    '' !== backend &&
    '' !== db &&
    (!scoped || '' !== (scoped_to ?? '').trim())
  );
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

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  disposed = true;
  identityVersion++;
  loadId++;
  loadController?.abort();
});

markFormClean();
</script>
