<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="Each identity keeps separate watch history & backend settings."
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading || isSyncing || isAdding || isNavigating"
          aria-label="Refresh identities"
          @click="() => void loadContent()"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section aria-label="Identity directory" :aria-busy="isLoading" class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div aria-live="polite" class="space-y-1">
          <p v-if="hasResults" class="text-sm text-toned">
            <strong class="font-medium text-highlighted">{{ identities.length }}</strong>
            {{ 1 === identities.length ? 'identity' : 'identities' }}
          </p>
          <p
            v-if="isLoading"
            role="status"
            class="inline-flex items-center gap-1.5 text-xs text-muted"
          >
            <UIcon name="i-lucide-loader-circle" class="size-3.5 animate-spin" />
            {{ hasResults ? 'Refreshing identities…' : 'Loading identities…' }}
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-users-round"
            to="/identities/provision"
            :disabled="isSyncing || isAdding || isNavigating"
          >
            Match & Provision
          </UButton>
          <UButton
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-plus"
            :disabled="managementDisabled"
            @click="openAddIdentityForm"
          >
            Add Identity
          </UButton>
        </div>
      </div>

      <UAlert
        v-if="loadError"
        color="error"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="hasResults ? 'Identities could not be refreshed' : 'Identities could not be loaded'"
      >
        <template #description>
          <div class="space-y-2">
            <p class="wrap-break-word">{{ loadError }}</p>
            <p v-if="hasResults">
              Showing the last loaded identities. Refresh before making changes.
            </p>
          </div>
        </template>
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :disabled="isLoading || isSyncing || isAdding || isNavigating"
            @click="() => void loadContent()"
          >
            Retry
          </UButton>
        </template>
      </UAlert>

      <UEmpty
        v-if="hasResults && !isLoading && !loadError && 0 === identities.length"
        icon="i-lucide-users"
        title="No identities"
        description="Add an identity, or use Match & Provision to link backend users."
      />

      <div v-if="0 < identities.length" class="ws-card divide-y divide-default">
        <article
          v-for="identity in identities"
          :key="identity.identity"
          class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-x-6 sm:p-5 lg:grid-cols-[14rem_minmax(0,1fr)_auto]"
        >
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <UIcon name="i-lucide-user" class="size-4 shrink-0 text-toned" />
              <h2 class="wrap-anywhere text-base font-semibold text-highlighted">
                {{ ucFirst(identity.identity) }}
              </h2>
              <UBadge v-if="'main' === identity.identity" color="neutral" variant="soft" size="xs">
                Primary
              </UBadge>
            </div>
          </div>

          <div class="min-w-0 space-y-1 sm:col-start-1 lg:col-start-auto">
            <p class="text-xs text-muted">Backends</p>
            <div v-if="0 < identity.backends.length" class="flex flex-wrap gap-x-4 gap-y-1">
              <UButton
                v-for="backend in identity.backends"
                :key="backend"
                color="neutral"
                variant="link"
                size="sm"
                icon="i-lucide-server"
                class="min-w-0 px-0"
                :ui="{ label: 'wrap-anywhere whitespace-normal text-left' }"
                :disabled="managementDisabled"
                :aria-label="`Open ${backend} as ${identity.identity}`"
                @click="() => void identityLink(identity, backend)"
              >
                {{ backend }}
              </UButton>
            </div>
            <p v-else class="text-sm text-toned">No backends configured</p>
          </div>

          <div
            class="flex flex-wrap items-start gap-2 sm:col-start-2 sm:row-span-2 sm:row-start-1 sm:justify-end lg:col-start-auto lg:row-span-1 lg:row-start-auto"
          >
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-pencil"
              :disabled="managementDisabled"
              :aria-label="`Edit ${identity.identity}`"
              @click="openEditIdentity(identity.identity)"
            >
              Edit
            </UButton>
            <UButton
              v-if="'main' !== identity.identity"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :disabled="managementDisabled"
              :to="`/identities/${identity.identity}/delete?redirect=/identities`"
              :aria-label="`Delete ${identity.identity}`"
            >
              Delete
            </UButton>
          </div>
        </article>
      </div>
    </section>

    <section
      v-if="0 < identities.length"
      aria-labelledby="sync-backends-title"
      class="ws-panel-padded flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
      <div class="space-y-1">
        <h2 id="sync-backends-title" class="text-sm font-semibold text-highlighted">
          Shared backend settings
        </h2>
        <p class="max-w-3xl text-sm text-toned">
          Copy shared backend settings from main to linked identities.
        </p>
      </div>
      <UButton
        color="neutral"
        variant="outline"
        size="sm"
        icon="i-lucide-copy"
        class="self-start sm:shrink-0"
        :loading="isSyncing"
        :disabled="managementDisabled"
        @click="() => void syncBackends()"
      >
        Sync Backends
      </UButton>
    </section>

    <UModal
      :open="toggleForm"
      title="Add Identity"
      :ui="addIdentityModalUi"
      :dismissible="!isAdding"
      :close="{ disabled: isAdding }"
      @update:open="handleAddIdentityOpenChange"
    >
      <template #body>
        <form
          v-if="toggleForm"
          :id="addIdentityFormId"
          class="space-y-4"
          @submit.prevent="addIdentity"
        >
          <UAlert
            v-if="formError"
            color="error"
            variant="soft"
            icon="i-lucide-circle-alert"
            title="Identity not added"
            :description="formError"
          />
          <UFormField
            label="Identity name"
            name="identity"
            description="Use a unique name with lowercase letters, numbers & underscores."
          >
            <UInput
              v-model="newIdentityName"
              type="text"
              required
              icon="i-lucide-user"
              class="w-full"
              placeholder="e.g. alex"
              :disabled="isAdding"
            />
          </UFormField>
        </form>
      </template>
      <template #footer>
        <div class="flex w-full flex-col gap-2 sm:flex-row sm:justify-end">
          <UButton
            :form="addIdentityFormId"
            type="submit"
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-plus"
            :loading="isAdding"
            :disabled="managementDisabled"
          >
            Add Identity
          </UButton>
        </div>
      </template>
    </UModal>

    <UModal
      :open="editIdentityOpen"
      :title="editIdentityId ? `Edit Identity: ${ucFirst(editIdentityId)}` : 'Edit Identity'"
      :ui="editIdentityModalUi"
      @update:open="handleEditIdentityOpenChange"
    >
      <template #body>
        <IdentityEditForm
          v-if="editIdentityOpen && editIdentityId"
          :key="editIdentityId"
          :identity-id="editIdentityId"
          :actions-target="`#${editIdentityFooterId}`"
          @saved="() => void handleIdentityEdited()"
          @dirty-change="(dirty) => (editIdentityDirty = dirty)"
        />
      </template>
      <template #footer>
        <div :id="editIdentityFooterId" class="w-full" />
      </template>
    </UModal>
  </main>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { navigateTo, useHead, useRouter } from '#app';
import { UAlert, UBadge, UButton, UEmpty, UFormField, UIcon, UInput, UModal } from '#components';
import { useStorage } from '@vueuse/core';
import IdentityEditForm from '~/components/IdentityEditForm.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDirtyState } from '~/composables/useDirtyState';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request, ucFirst } from '~/utils';
import type { GenericResponse, IdentityListItem } from '~/types';

useHead({ title: 'Identities' });

const pageShell = requireTopLevelPageShell('identities');
const addIdentityFormId = useId();
const editIdentityFooterId = useId();
const router = useRouter();
const dialog = useDialog();
const apiUser = useStorage<string>('api_user', 'main', undefined, { flush: 'sync' });
const identities = ref<Array<IdentityListItem>>([]);
const toggleForm = ref(false);
const editIdentityOpen = ref(false);
const editIdentityId = ref('');
const editIdentityDirty = ref(false);
const isLoading = ref(true);
const hasResults = ref(false);
const loadError = ref('');
const isSyncing = ref(false);
const isAdding = ref(false);
const isNavigating = ref(false);
const newIdentityName = ref('');
const formError = ref<string | null>(null);
const managementDisabled = computed(
  () =>
    !hasResults.value ||
    isLoading.value ||
    !!loadError.value ||
    isSyncing.value ||
    isAdding.value ||
    isNavigating.value,
);
const addIdentityDirtySource = computed(() => ({
  identity: newIdentityName.value.trim().toLowerCase(),
}));
const { isDirty: isAddIdentityDirty, markClean: markAddIdentityClean } =
  useDirtyState(addIdentityDirtySource);
let loadController: AbortController | null = null;
let loadId = 0;
let disposed = false;
let pendingIdentity: { identity: string; path: string } | null = null;

const addIdentityModalUi = {
  content: 'max-w-xl',
  body: 'p-4 sm:p-5',
};
const editIdentityModalUi = {
  content: 'max-w-6xl',
  body: 'p-4 sm:p-5',
};

const resetAddIdentityForm = (): void => {
  newIdentityName.value = '';
  formError.value = null;
  markAddIdentityClean();
};

const { handleOpenChange: handleAddIdentityOpenChange } = useDirtyCloseGuard(toggleForm, {
  dirty: isAddIdentityDirty,
  onDiscard: () => {
    if (!disposed) {
      resetAddIdentityForm();
    }
  },
});

const { handleOpenChange: handleEditIdentityOpenChange } = useDirtyCloseGuard(editIdentityOpen, {
  dirty: editIdentityDirty,
  onDiscard: () => {
    if (!disposed) {
      editIdentityDirty.value = false;
      editIdentityId.value = '';
    }
  },
});

const openAddIdentityForm = (): void => {
  if (disposed || managementDisabled.value) {
    return;
  }

  resetAddIdentityForm();
  toggleForm.value = true;
};

const openEditIdentity = (identityId: string): void => {
  if (
    disposed ||
    managementDisabled.value ||
    !identities.value.some((identity) => identityId === identity.identity)
  ) {
    return;
  }

  editIdentityDirty.value = false;
  editIdentityId.value = identityId;
  editIdentityOpen.value = true;
};

const loadContent = async (): Promise<void> => {
  if (disposed) {
    return;
  }

  loadController?.abort();
  const controller = new AbortController();
  loadController = controller;
  const id = ++loadId;
  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/identities', { signal: controller.signal });
    const json = await parse_api_response<{ identities: Array<IdentityListItem> }>(response);

    if (disposed || id !== loadId) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (!response.ok) {
      throw new Error(`Identities request failed (${response.status}).`);
    }

    identities.value = json.identities;
    hasResults.value = true;
  } catch (error: unknown) {
    if (disposed || id !== loadId || controller.signal.aborted) {
      return;
    }

    loadError.value = error instanceof Error ? error.message : 'Unexpected request error';
    notification('error', 'Identities unavailable', loadError.value);
  } finally {
    if (!disposed && id === loadId) {
      isLoading.value = false;
    }
  }
};

const addIdentity = async (): Promise<void> => {
  if (disposed || managementDisabled.value || !toggleForm.value) {
    return;
  }

  formError.value = null;
  const identity = newIdentityName.value.trim().toLowerCase();

  if (!/^[a-z0-9_]+$/.test(identity)) {
    formError.value = 'Use lowercase letters, numbers & underscores for the identity name.';
    return;
  }

  isAdding.value = true;

  try {
    const response = await request('/identities', {
      method: 'POST',
      body: JSON.stringify({ identity }),
    });
    if (disposed) {
      return;
    }
    if (!response.ok) {
      const result = await parse_api_response<GenericResponse>(response);
      if ('error' in result) {
        throw new Error(result.error.message);
      }
      throw new Error(`Identity creation failed (${response.status}).`);
    }

    notification('success', 'Identity added', `'${identity}' is ready to configure.`);
    resetAddIdentityForm();
    toggleForm.value = false;
    await loadContent();
  } catch (error: unknown) {
    if (!disposed) {
      formError.value = error instanceof Error ? error.message : 'Unexpected request error';
    }
  } finally {
    if (!disposed) {
      isAdding.value = false;
    }
  }
};

const handleIdentityEdited = async (): Promise<void> => {
  if (disposed || !editIdentityOpen.value) {
    return;
  }

  editIdentityDirty.value = false;
  editIdentityOpen.value = false;
  editIdentityId.value = '';
  await loadContent();
};

watch(
  () => router.currentRoute.value.path,
  (path) => {
    if (pendingIdentity && pendingIdentity.path === path) {
      apiUser.value = pendingIdentity.identity;
      pendingIdentity = null;
    }
  },
  { flush: 'sync' },
);

const identityLink = async (identity: IdentityListItem, backend: string): Promise<void> => {
  if (
    disposed ||
    managementDisabled.value ||
    !identities.value.includes(identity) ||
    !identity.backends.includes(backend)
  ) {
    return;
  }

  const path = `/backend/${encodeURIComponent(backend)}`;
  pendingIdentity = { identity: identity.identity, path };
  isNavigating.value = true;

  try {
    await navigateTo(path);
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected navigation error';
      notification('error', 'Backend could not be opened', message);
    }
  } finally {
    pendingIdentity = null;
    if (!disposed) {
      isNavigating.value = false;
    }
  }
};

const syncBackends = async (): Promise<void> => {
  if (disposed || managementDisabled.value || 0 === identities.value.length) {
    return;
  }

  isSyncing.value = true;

  try {
    const { status } = await dialog.confirmDialog({
      title: 'Sync shared backend settings?',
      message:
        'Copy shared backend settings from main to existing linked identity backends? This does not sync watch history or create, delete, or rematch identities.',
      confirmText: 'Sync Backends',
    });

    if (true !== status || disposed || isLoading.value || loadError.value) {
      return;
    }

    const response = await request('/identities/provision/sync-backends', {
      method: 'POST',
      body: JSON.stringify({ dry_run: false }),
    });
    const result = await parse_api_response<
      GenericResponse & {
        updated_count?: number;
        skipped_count?: number;
        failed_count?: number;
      }
    >(response);

    if (disposed) {
      return;
    }
    if ('error' in result) {
      throw new Error(result.error.message);
    }
    if (!response.ok) {
      throw new Error(`Backend settings sync failed (${response.status}).`);
    }

    const failedCount = Number(result.failed_count ?? 0);
    const details = [
      result.info.message,
      `Updated: ${Number(result.updated_count ?? 0)}.`,
      `Skipped: ${Number(result.skipped_count ?? 0)}.`,
      `Failed: ${failedCount}.`,
    ].join(' ');

    notification(
      0 < failedCount ? 'warning' : 'success',
      0 < failedCount ? 'Some backend settings were not synced' : 'Backend settings synced',
      details,
    );
    await loadContent();
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Backend settings not synced', message);
    }
  } finally {
    if (!disposed) {
      isSyncing.value = false;
    }
  }
};

markAddIdentityClean();

onMounted(() => void loadContent());
onBeforeUnmount(() => {
  disposed = true;
  pendingIdentity = null;
  loadId++;
  loadController?.abort();
});
</script>
