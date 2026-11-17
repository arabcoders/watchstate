<template>
  <main :aria-busy="isLoading || isDeleting" class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      page-label="Delete identity"
      description="Remove this identity's local data & backend configurations from WatchState."
    >
      <template #kicker>
        <NuxtLink to="/identities" class="hover:text-primary">Identities</NuxtLink>
        <span>/</span>
        <span class="wrap-anywhere normal-case tracking-normal">{{ id }}</span>
      </template>
    </PageHeader>

    <UAlert
      v-if="isDeleting"
      color="warning"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Deleting identity"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading identity"
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-else>
      <UAlert
        v-if="error"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Identity unavailable"
        :description="`${error.error.code}: ${error.error.message}`"
        :close="{ onClick: () => void navigateTo('/identities') }"
      />

      <UAlert
        v-else-if="'main' === id"
        color="error"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Action is not permitted"
        :close="{ onClick: () => void navigateTo('/identities') }"
      >
        <template #description>
          <p class="text-sm text-default">
            The <strong>main</strong> identity cannot be deleted as it is the primary identity.
          </p>
        </template>
      </UAlert>

      <template v-else>
        <UAlert
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Identity deletion is permanent"
        >
          <template #description>
            <p class="wrap-anywhere">
              Delete identity <strong>{{ id }}</strong> and all its backend configurations? This
              action cannot be undone.
            </p>
          </template>
        </UAlert>

        <dl class="grid gap-6 md:grid-cols-2">
          <div class="space-y-2">
            <dt class="text-sm font-semibold text-highlighted">What changes</dt>
            <dd>
              <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
                <li class="wrap-anywhere">
                  All local data for identity <code>{{ id }}</code> is deleted.
                </li>
                <li v-if="0 < backends.length" class="wrap-anywhere">
                  {{ backends.length }} backend configuration{{
                    1 < backends.length ? 's are' : ' is'
                  }}
                  removed:
                  <span v-for="(backend, index) in backends" :key="backend">
                    <code>{{ backend }}</code
                    ><template v-if="index < backends.length - 1">, </template> </span
                  >.
                </li>
              </ul>
            </dd>
          </div>

          <div class="space-y-2">
            <dt class="text-sm font-semibold text-highlighted">What stays</dt>
            <dd>
              <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
                <li>Other identities' local data &amp; backend configurations.</li>
                <li>Media files, playlists &amp; watched status on your media servers.</li>
              </ul>
            </dd>
          </div>
        </dl>

        <div class="flex flex-wrap items-center justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            size="sm"
            icon="i-lucide-arrow-left"
            @click="
              () => {
                void navigateTo('/identities');
              }
            "
          >
            Back
          </UButton>

          <UButton
            color="error"
            variant="solid"
            size="sm"
            icon="i-lucide-trash-2"
            :loading="isDeleting"
            :disabled="isDeleting"
            @click="deleteIdentity()"
          >
            Delete identity
          </UButton>
        </div>
      </template>
    </template>
  </main>
</template>

<script setup lang="ts">
import { onMounted, nextTick, ref } from 'vue';
import { navigateTo, useRoute } from '#app';
import { NuxtLink, UAlert, UButton } from '#components';
import { useStorage } from '@vueuse/core';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';
import { useDialog } from '~/composables/useDialog';
import type { GenericError, IdentityListItem } from '~/types';

const id = useRoute().params.identity as string;
const pageShell = requireTopLevelPageShell('identities');
const error = ref<GenericError | null>(null);
const backends = ref<Array<string>>([]);
const isLoading = ref<boolean>(false);
const isDeleting = ref<boolean>(false);

const loadIdentity = async (): Promise<void> => {
  try {
    isLoading.value = true;

    const response = await request('/identities');
    const data = await parse_api_response<{ identities: Array<IdentityListItem> }>(response);

    if ('error' in data) {
      error.value = data;
      return;
    }

    const identity = data.identities.find((entry) => entry.identity === id);
    if (identity) {
      backends.value = identity.backends;
    } else {
      error.value = {
        error: { code: 404, message: 'Identity not found' },
      } as GenericError;
    }
  } catch (e: unknown) {
    error.value = {
      error: { code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred' },
    } as GenericError;
  } finally {
    isLoading.value = false;
  }
};

const deleteIdentity = async (): Promise<void> => {
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Delete permanently?',
    message: `This action is irreversible. Delete identity '${id}' data?`,
    confirmText: 'Delete identity',
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    isDeleting.value = true;

    const response = await request(`/identities/${id}`, { method: 'DELETE' });

    if (200 !== response.status) {
      error.value = await parse_api_response(response);
      return;
    }

    notification('success', 'Identity deleted', `Deleted '${id}'.`);

    const api_user = useStorage('api_user', 'main');
    if (api_user.value === id) {
      api_user.value = 'main';
      await nextTick();
    }

    await navigateTo('/identities');
  } catch (e: unknown) {
    error.value = {
      error: { code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred' },
    } as GenericError;
  } finally {
    isDeleting.value = false;
  }
};

onMounted(async (): Promise<void> => await loadIdentity());
</script>
