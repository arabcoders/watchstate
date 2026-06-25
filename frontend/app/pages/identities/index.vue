<template>
  <div class="space-y-6">
    <PageHeader v-bind="pageShell">
      <template #actions>
        <UButton
          v-if="identities.length > 0"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isSyncing"
          :disabled="isLoading || isSyncing"
          @click="syncBackends"
          label="Sync Backends"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-users-round"
          to="/identities/provision"
          label="Match & Provision"
        />

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-plus"
          :disabled="isLoading"
          @click="openAddIdentityForm"
          label="Add Identity"
        />

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
      </template>
    </PageHeader>

    <UModal
      :open="toggleForm"
      title="Add Identity"
      :ui="addIdentityModalUi"
      @update:open="handleAddIdentityOpenChange"
    >
      <template #body>
        <form v-if="toggleForm" class="space-y-4" @submit.prevent="addIdentity">
          <UAlert
            v-if="formError"
            color="error"
            variant="soft"
            icon="i-lucide-triangle-alert"
            title="Error"
            :close="{
              onClick: () => {
                formError = null;
              },
            }"
            :description="formError"
          />

          <UFormField
            label="Identity name"
            name="identity"
            description="Identity name must be unique and only contain lowercase letters (a-z), numbers (0-9), and underscores (_)."
          >
            <UInput
              v-model="newIdentityName"
              type="text"
              required
              icon="i-lucide-user"
              class="w-full"
              placeholder="Enter identity name (lowercase a-z, 0-9, _)"
            />
          </UFormField>

          <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-x"
              @click="() => void cancelAddIdentity()"
            >
              Cancel
            </UButton>

            <UButton
              type="submit"
              color="primary"
              variant="solid"
              size="sm"
              icon="i-lucide-circle-check"
              :loading="isAdding"
              :disabled="isAdding"
            >
              Add Identity
            </UButton>
          </div>
        </form>
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
          :identity-id="editIdentityId"
          @close="() => void requestCloseEditIdentity()"
          @saved="() => void handleIdentityEdited()"
          @dirty-change="(dirty) => (editIdentityDirty = dirty)"
        />
      </template>
    </UModal>

    <UAlert
      v-if="identities.length < 1 && isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading identities. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="identities.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-info"
      title="No Identities Found"
    >
      <template #description>
        <div class="flex flex-wrap items-center gap-2 text-sm text-default">
          <span>No identities found.</span>
          <UButton
            color="primary"
            variant="link"
            size="sm"
            class="px-0"
            @click="openAddIdentityForm"
          >
            Add a new identity
          </UButton>
        </div>
      </template>
    </UAlert>

    <div v-else class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="identity in identities"
        :key="identity.identity"
        class="h-full shadow-sm"
        :ui="identityCardUi"
      >
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex items-center gap-2">
              <UIcon name="i-lucide-user" class="size-4 shrink-0 text-toned" />
              <UTooltip :text="String(ucFirst(identity.identity))">
                <h2 class="truncate text-base font-semibold">
                  {{ ucFirst(identity.identity) }}
                </h2>
              </UTooltip>
            </div>

            <div class="flex items-center gap-2">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-settings"
                @click="openEditIdentity(identity.identity)"
                label="Edit"
              />

              <UButton
                v-if="identity.identity !== 'main'"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-trash-2"
                :to="`/identities/${identity.identity}/delete?redirect=/identities`"
                label="Delete"
              />
            </div>
          </div>
        </template>

        <div class="space-y-3 text-sm text-default">
          <div v-if="identity.backends.length > 0" class="flex flex-wrap gap-2">
            <button
              v-for="backend in identity.backends"
              :key="backend"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default hover:bg-elevated/60"
              @click="identityLink(identity.identity, `/backend/${backend}`)"
            >
              <UIcon name="i-lucide-server" class="size-3.5 shrink-0 text-toned" />
              {{ backend }}
            </button>
          </div>

          <UBadge v-else color="warning" variant="soft" icon="i-lucide-triangle-alert"
            >No backends configured</UBadge
          >
        </div>
      </UCard>
    </div>

    <UCard v-if="identities.length > 0" :ui="tipsCardUi">
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
        <li>The <strong>main</strong> identity is the primary identity and cannot be deleted.</li>
        <li>Each identity can have their own set of backends configured independently.</li>
        <li>
          Use <code>Sync Backends</code> here to safely propagate shared backend configuration
          changes from the main identity without creating, deleting, or rematching identities.
        </li>
        <li>
          Server configurations are validated against the system specification before saving. While
          this may help prevent misconfigurations, it's recommended to double-check configurations
          manually. The validation is not foolproof and may miss certain issues.
        </li>
      </ul>
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useStorage } from '@vueuse/core';
import IdentityEditForm from '~/components/IdentityEditForm.vue';
import PageHeader from '~/components/PageHeader.vue';
import { useDirtyCloseGuard } from '~/composables/useDirtyCloseGuard';
import { useDirtyState } from '~/composables/useDirtyState';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request, ucFirst } from '~/utils';
import type { GenericResponse, IdentityListItem } from '~/types';

useHead({ title: 'Identity Management' });

const pageShell = requireTopLevelPageShell('identities');

const route = useRoute();
const identities = ref<Array<IdentityListItem>>([]);
const toggleForm = ref<boolean>(false);
const editIdentityOpen = ref<boolean>(false);
const editIdentityId = ref<string>('');
const editIdentityDirty = ref<boolean>(false);
const isLoading = ref<boolean>(false);
const isSyncing = ref<boolean>(false);
const isAdding = ref<boolean>(false);
const newIdentityName = ref<string>('');
const formError = ref<string | null>(null);
const show_page_tips = useStorage('show_page_tips', true);
const addIdentityDirtySource = computed(() => ({
  identity: newIdentityName.value.trim().toLowerCase(),
}));
const { isDirty: isAddIdentityDirty, markClean: markAddIdentityClean } =
  useDirtyState(addIdentityDirtySource);

const addIdentityModalUi = {
  content: 'max-w-xl',
  body: 'p-4 sm:p-5',
};

const editIdentityModalUi = {
  content: 'max-w-6xl',
  body: 'p-4 sm:p-5',
};

const identityCardUi = {
  header: 'p-4',
  body: 'p-4',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const resetAddIdentityForm = (): void => {
  newIdentityName.value = '';
  formError.value = null;
  markAddIdentityClean();
};

const { handleOpenChange: handleAddIdentityOpenChange, requestClose: requestCloseAddIdentity } =
  useDirtyCloseGuard(toggleForm, {
    dirty: isAddIdentityDirty,
    onDiscard: async () => {
      resetAddIdentityForm();
    },
  });

const { handleOpenChange: handleEditIdentityOpenChange, requestClose: requestCloseEditIdentity } =
  useDirtyCloseGuard(editIdentityOpen, {
    dirty: editIdentityDirty,
    onDiscard: async () => {
      editIdentityDirty.value = false;
      editIdentityId.value = '';
    },
  });

const openAddIdentityForm = (): void => {
  resetAddIdentityForm();
  toggleForm.value = true;
};

const openEditIdentity = (identityId: string): void => {
  editIdentityDirty.value = false;
  editIdentityId.value = identityId;
  editIdentityOpen.value = true;
};

const loadContent = async (): Promise<void> => {
  identities.value = [];
  isLoading.value = true;

  try {
    const response = await request('/identities');
    const json = await parse_api_response<{ identities: Array<IdentityListItem> }>(response);

    if ('identities' !== route.name) {
      return;
    }

    if ('error' in json) {
      notification('error', 'Error', `Failed to load identities. ${json.error.message}`);
      return;
    }

    identities.value = json.identities || [];
    useHead({ title: 'Identity Management' });
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Failed to load identities. ${error.message}`);
  } finally {
    isLoading.value = false;
  }
};

const addIdentity = async (): Promise<void> => {
  if (true === isAdding.value) {
    return;
  }

  formError.value = null;
  const identity = newIdentityName.value.trim().toLowerCase();

  if (0 === identity.length) {
    formError.value = 'Please enter an identity name';
    return;
  }

  isAdding.value = true;

  try {
    const response = await request('/identities', {
      method: 'POST',
      body: JSON.stringify({ identity }),
    });
    const result = await parse_api_response<GenericResponse>(response);

    if (!response.ok && 'error' in result) {
      formError.value = result.error?.message || 'Failed to create identity';
      return;
    }

    notification('success', 'Success', `Identity '${identity}' created successfully`);
    resetAddIdentityForm();
    toggleForm.value = false;
    await loadContent();
  } catch (e: unknown) {
    const error = e as Error;
    formError.value = `Failed to create identity. ${error.message}`;
  } finally {
    isAdding.value = false;
  }
};

const cancelAddIdentity = async (): Promise<void> => {
  await requestCloseAddIdentity();
};

const handleIdentityEdited = async (): Promise<void> => {
  editIdentityDirty.value = false;
  editIdentityOpen.value = false;
  editIdentityId.value = '';
  await loadContent();
};

const identityLink = async (identity: string, url: string): Promise<void> => {
  const api_user = useStorage('api_user', 'main');
  api_user.value = identity || 'main';
  await nextTick();
  await navigateTo(url);
};

const syncBackends = async (): Promise<void> => {
  if (true === isSyncing.value) {
    return;
  }

  isSyncing.value = true;

  try {
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

    if ('error' in result) {
      notification('error', 'Error', `Failed to sync backends. ${result.error.message}`);
      return;
    }

    const failedCount = Number(result.failed_count ?? 0);
    const level = failedCount > 0 ? 'warning' : 'success';
    const title = failedCount > 0 ? 'Warning' : 'Success';
    const details = [
      result.info.message,
      `Updated: ${Number(result.updated_count ?? 0)}`,
      `Skipped: ${Number(result.skipped_count ?? 0)}`,
      `Failed: ${failedCount}`,
    ].join(' ');

    notification(level, title, details);
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Failed to sync backends. ${message}`);
  } finally {
    isSyncing.value = false;
  }
};

markAddIdentityClean();

onMounted(() => loadContent());
</script>
