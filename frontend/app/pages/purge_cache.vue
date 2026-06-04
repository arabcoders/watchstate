<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="space-y-1">
      <div
        class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
      >
        <UIcon :name="pageShell.icon" class="size-4" />
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <span>{{ pageShell.pageLabel }}</span>
      </div>
    </div>

    <UAlert
      v-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Error"
      :description="`${error.error.code}: ${error.error.message}`"
      :close="{
        onClick: () => {
          error = null;
        },
      }"
    />

    <UAlert
      v-if="isPurging"
      color="warning"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Cache purge in progress"
      description="Removing cached runtime data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UCard class="border border-default/70 shadow-sm" :ui="panelCardUi">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
              <h1 class="text-base font-semibold text-highlighted">System Cache Purge</h1>
              <UBadge color="error" variant="soft">Irreversible</UBadge>
              <UBadge color="warning" variant="soft">All users</UBadge>
            </div>

            <p class="text-sm leading-6 text-default">Clear cached data for every user.</p>
          </div>
        </div>
      </template>

      <template #body></template>

      <template #footer>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <UButton
            color="error"
            variant="solid"
            size="sm"
            icon="i-lucide-trash-2"
            :loading="isPurging"
            :disabled="isPurging"
            @click="purgeCache"
          >
            Purge cache
          </UButton>
        </div>
      </template>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { GenericError, GenericResponse } from '~/types';

useHead({ title: 'Purge Cache' });

const pageShell = requireTopLevelPageShell('purge-cache');

const route = useRoute();
const error = ref<GenericError | null>(null);
const isPurging = ref<boolean>(false);

const panelCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const purgeCache = async (): Promise<void> => {
  const { status } = await useDialog().confirmDialog({
    title: 'Confirm cache purge',
    message:
      'This will clear cached WatchState runtime data for every user. Do you want to continue?',
    confirmText: 'Purge cache',
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  isPurging.value = true;
  error.value = null;

  try {
    const response = await request('/system/cache', { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);

    if ('purge_cache' !== route.name) {
      return;
    }

    if ('error' in json) {
      error.value = json;
      return;
    }

    if (true !== response.ok) {
      error.value = { error: { code: response.status, message: response.statusText } };
      return;
    }

    notification('success', 'Success', json.info?.message ?? 'System Cache has been purged.');
    await navigateTo('/');

    try {
      useSessionCache().clear();
    } catch {}
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : 'Unexpected error';
    error.value = { error: { code: 500, message } };
  } finally {
    isPurging.value = false;
  }
};
</script>
