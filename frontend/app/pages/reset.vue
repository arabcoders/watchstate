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
      v-if="isResetting"
      color="warning"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Reset in progress"
      description="Removing local state and clearing sync markers. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UCard class="border border-default/70 shadow-sm" :ui="panelCardUi">
      <template #header>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
              <h1 class="text-base font-semibold text-highlighted">Local State Reset</h1>
              <UBadge color="error" variant="soft">Irreversible</UBadge>
              <UBadge color="warning" variant="soft">All users</UBadge>
            </div>

            <p class="text-sm leading-6 text-default">
              Remove local WatchState state for every configured user.
            </p>
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
            icon="i-lucide-rotate-ccw"
            :loading="isResetting"
            :disabled="isResetting"
            @click="resetSystem"
          >
            Perform local state reset
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
import type { GenericError } from '~/types';

useHead({ title: 'Reset' });

const pageShell = requireTopLevelPageShell('reset');

const route = useRoute();
const error = ref<GenericError | null>(null);
const isResetting = ref<boolean>(false);

const panelCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'px-4 pb-4 pt-0',
};

const resetSystem = async (): Promise<void> => {
  const { status } = await useDialog().confirmDialog({
    title: 'Confirm local state reset',
    message:
      'This will delete all local WatchState state for every user and cannot be undone. Do you want to continue?',
    confirmText: 'Reset local state',
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  isResetting.value = true;
  error.value = null;

  try {
    const response = await request('/system/reset', { method: 'DELETE' });
    const json = await parse_api_response<{ message?: string }>(response);

    if ('reset' !== route.name) {
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

    notification('success', 'Success', json.message ?? 'System has been successfully reset.');
    await navigateTo('/');

    try {
      useSessionCache().clear();
    } catch {}
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : 'Unexpected error';
    error.value = { error: { code: 500, message } };
  } finally {
    isResetting.value = false;
  }
};
</script>
