<template>
  <div class="space-y-6">
    <section class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #kicker>
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <NuxtLink to="/backends" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
          <span>/</span>
          <NuxtLink :to="`/backend/${id}`" class="hover:text-primary normal-case tracking-normal">{{
            id
          }}</NuxtLink>
          <span>/</span>
          <span class="text-highlighted normal-case tracking-normal">Delete</span>
        </template>
      </PageHeader>

      <div>
        <h1 class="text-2xl font-semibold text-highlighted">Delete Backend</h1>
        <p class="mt-1 text-sm text-toned">Delete backend configuration and data.</p>
      </div>

      <UAlert
        v-if="isDeleting"
        color="warning"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Deleting..."
        description="Delete operation is in progress. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading"
        description="Loading data. Please wait..."
        :ui="{ icon: 'animate-spin' }"
      />

      <UAlert
        v-else-if="error"
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Error"
        :description="`${error.error.code}: ${error.error.message}`"
        close
        @update:open="(open) => (false === open ? navigateTo('/backends') : null)"
      />

      <template v-else>
        <UAlert
          color="warning"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Confirmation is required"
        >
          <template #description>
            <div class="space-y-4 text-sm text-default">
              <p>
                Are you sure you want to delete the backend <code>{{ type }}: {{ id }}</code>
                configuration and all its records?
              </p>

              <div>
                <p class="mb-2 font-semibold text-highlighted">
                  This operation will do the following
                </p>
                <ul class="list-disc space-y-1 pl-5">
                  <li>Remove records metadata that references the given backend.</li>
                  <li>Run data integrity check to remove no longer used records.</li>
                  <li>Update <code>servers.yaml</code> file and remove backend configuration.</li>
                </ul>
              </div>

              <p>There is no undo operation. This action is irreversible.</p>
            </div>
          </template>
        </UAlert>

        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
          <UButton
            color="neutral"
            variant="soft"
            size="sm"
            icon="i-lucide-arrow-left"
            @click="navigateTo('/backends')"
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
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { navigateTo, useRoute } from '#app';
import PageHeader from '~/components/PageHeader.vue';
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
    title: 'Last Chance!',
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
      'Success',
      `Backend '${id}' has been deleted. Deleted References: ${data.deleted.references} records: ${data.deleted.records}`,
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
