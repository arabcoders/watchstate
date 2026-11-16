<template>
  <main :aria-busy="isResetting" class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="Delete WatchState's local records without changing your media servers."
    />

    <UAlert
      v-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Reset failed"
      :description="`${error.error.code}: ${error.error.message}`"
      :close="{
        onClick: () => {
          error = null;
        },
      }"
    />

    <UAlert
      v-if="isResetting"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Reset in progress"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Local records are permanently deleted"
      description="Let queued work finish first. Events held in the Redis queue are also removed."
    />

    <dl class="grid gap-6 md:grid-cols-2">
      <div class="space-y-2">
        <dt class="text-sm font-semibold text-highlighted">What changes</dt>
        <dd>
          <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
            <li>
              All local database records are deleted, including play state, playlists, events &amp;
              reports.
            </li>
            <li>Import &amp; export sync dates are cleared.</li>
            <li>Cached data is cleared.</li>
          </ul>
        </dd>
      </div>

      <div class="space-y-2">
        <dt class="text-sm font-semibold text-highlighted">What stays</dt>
        <dd>
          <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
            <li>Identity &amp; backend settings.</li>
            <li>Media files, playlists &amp; watched status on your media servers.</li>
          </ul>
        </dd>
      </div>
    </dl>

    <div class="flex justify-end">
      <UButton
        color="error"
        variant="solid"
        size="sm"
        icon="i-lucide-rotate-ccw"
        :loading="isResetting"
        :disabled="isPending"
        @click="resetSystem"
      >
        Reset local state
      </UButton>
    </div>
  </main>
</template>

<script setup lang="ts">
import { onScopeDispose, ref } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { UAlert, UButton } from '#components';
import PageHeader from '~/components/PageHeader.vue';
import { useDialog } from '~/composables/useDialog';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { GenericError } from '~/types';

useHead({ title: 'Reset' });

const pageShell = requireTopLevelPageShell('reset');

const route = useRoute();
const error = ref<GenericError | null>(null);
const isResetting = ref(false);
const isPending = ref(false);
let disposed = false;

onScopeDispose(() => {
  disposed = true;
});

const resetSystem = async (): Promise<void> => {
  if (isPending.value || disposed || 'reset' !== route.name) {
    return;
  }

  isPending.value = true;

  try {
    const { status } = await useDialog().confirmDialog({
      title: 'Reset local state?',
      message:
        'Delete local history, playlists, events, reports, and other local records for every identity? Sync dates and cached data will also be cleared, including Redis-queued events. Your media servers and backend connections are unchanged. This cannot be undone.',
      confirmText: 'Reset local state',
      confirmColor: 'error',
    });

    if (true !== status || disposed || 'reset' !== route.name) {
      return;
    }

    isResetting.value = true;
    error.value = null;

    const response = await request('/system/reset', { method: 'DELETE' });
    const json = await parse_api_response<{ message?: string }>(response);

    if (disposed || 'reset' !== route.name) {
      return;
    }

    if ('error' in json) {
      error.value = json;
      notification('error', 'Reset failed', `${json.error.code}: ${json.error.message}`);
      return;
    }

    if (true !== response.ok) {
      error.value = { error: { code: response.status, message: response.statusText } };
      notification('error', 'Reset failed', `${response.status}: ${response.statusText}`);
      return;
    }

    notification('success', 'Success', json.message ?? 'Local state has been reset.');

    try {
      useSessionCache().clear();
    } catch {}

    try {
      await navigateTo('/');
    } catch (cause: unknown) {
      if (!disposed) {
        const message = cause instanceof Error ? cause.message : 'Unexpected navigation error';
        notification('error', 'Could not open Overview', message);
      }
    }
  } catch (e: unknown) {
    if (disposed || 'reset' !== route.name) {
      return;
    }

    const message = e instanceof Error ? e.message : 'Unexpected error';
    error.value = { error: { code: 500, message } };
    notification('error', 'Reset failed', message);
  } finally {
    isResetting.value = false;
    isPending.value = false;
  }
};
</script>
