<template>
  <main :aria-busy="isLoading || isDeleting" class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      page-label="Delete backend"
      description="Remove this backend's configuration & local references from WatchState."
    >
      <template #kicker>
        <NuxtLink to="/backends" class="hover:text-primary">Backends</NuxtLink>
        <span>/</span>
        <NuxtLink
          :to="`/backend/${id}`"
          class="wrap-anywhere hover:text-primary normal-case tracking-normal"
        >
          {{ id }}
        </NuxtLink>
      </template>
    </PageHeader>

    <UAlert
      v-if="isDeleting"
      color="warning"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Deleting backend"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading backend"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Backend unavailable"
      :description="`${error.error.code}: ${error.error.message}`"
      close
      @update:open="(open) => (false === open ? navigateTo('/backends') : null)"
    />

    <template v-else>
      <UAlert
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Backend deletion is permanent"
      >
        <template #description>
          <p class="wrap-anywhere">
            Delete backend <strong>{{ id }}</strong> ({{ type }})? This action cannot be undone.
          </p>
        </template>
      </UAlert>

      <dl class="grid gap-6 md:grid-cols-2">
        <div class="space-y-2">
          <dt class="text-sm font-semibold text-highlighted">What changes</dt>
          <dd>
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
              <li>Record metadata that references this backend is removed.</li>
              <li>Local records with no remaining backend metadata are deleted.</li>
              <li>This backend's configuration is removed.</li>
            </ul>
          </dd>
        </div>

        <div class="space-y-2">
          <dt class="text-sm font-semibold text-highlighted">What stays</dt>
          <dd>
            <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
              <li>Local records that still have metadata from another backend.</li>
              <li>Other backend configurations.</li>
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
              void navigateTo('/backends');
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
          @click="deleteBackend()"
        >
          Delete backend
        </UButton>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { navigateTo, useRoute } from '#app';
import { NuxtLink, UAlert, UButton } from '#components';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';
import type { Backend, GenericError } from '~/types';

const id = useRoute().params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const error = ref<GenericError | null>(null);
const type = ref<string>('');
const isLoading = ref<boolean>(false);
const isDeleting = ref<boolean>(false);

const loadBackend = async (): Promise<void> => {
  try {
    isLoading.value = true;
    const response = await request(`/backend/${id}`);
    const data = await parse_api_response<Backend>(response);

    if ('error' in data) {
      error.value = data;
      return;
    }

    type.value = data.type;
  } catch (e) {
    error.value = {
      error: { code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred' },
    } as GenericError;
  } finally {
    isLoading.value = false;
  }
};

const deleteBackend = async (): Promise<void> => {
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Delete permanently?',
    message: `This action is irreversible. Are you sure you want to delete the backend '${id}'?`,
    confirmColor: 'error',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    isDeleting.value = true;

    const response = await request(`/backend/${id}`, { method: 'DELETE' });
    const data = await parse_api_response<{ deleted: { references: number; records: number } }>(
      response,
    );

    if ('error' in data) {
      error.value = data;
      return;
    }

    notification(
      'success',
      'Backend deleted',
      `Deleted '${id}': ${data.deleted.references} references and ${data.deleted.records} records.`,
    );
    await navigateTo('/backends');
  } catch (e) {
    error.value = {
      error: { code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred' },
    } as GenericError;
  } finally {
    isDeleting.value = false;
  }
};

onMounted(async (): Promise<void> => await loadBackend());
</script>
