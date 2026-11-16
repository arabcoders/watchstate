<template>
  <main :aria-busy="isPurging" class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader
      v-bind="pageShell"
      description="Clear cached data without resetting local history."
    />

    <UAlert
      v-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Cache purge failed"
      :description="`${error.error.code}: ${error.error.message}`"
      :close="{
        onClick: () => {
          error = null;
        },
      }"
    />

    <UAlert
      v-if="isPurging"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Cache purge in progress"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Let queued work finish first"
      description="If you use the Redis event queue, purging also removes queued events."
    />

    <dl class="grid gap-6 md:grid-cols-2">
      <div class="space-y-2">
        <dt class="text-sm font-semibold text-highlighted">What changes</dt>
        <dd>
          <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
            <li>Shared cached data is cleared.</li>
          </ul>
        </dd>
      </div>

      <div class="space-y-2">
        <dt class="text-sm font-semibold text-highlighted">What stays</dt>
        <dd>
          <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-toned">
            <li>Local play state, playlists, event history &amp; sync dates.</li>
            <li>Identity settings, backend settings &amp; media-server data.</li>
          </ul>
        </dd>
      </div>
    </dl>

    <div class="flex justify-end">
      <UButton
        color="error"
        variant="solid"
        size="sm"
        icon="i-lucide-trash-2"
        :loading="isPurging"
        :disabled="isPending"
        @click="purgeCache"
      >
        Purge cache
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
import type { GenericError, GenericResponse } from '~/types';

useHead({ title: 'Purge Cache' });

const pageShell = requireTopLevelPageShell('purge-cache');

const route = useRoute();
const error = ref<GenericError | null>(null);
const isPurging = ref(false);
const isPending = ref(false);
let disposed = false;

onScopeDispose(() => {
  disposed = true;
});

const purgeCache = async (): Promise<void> => {
  if (isPending.value || disposed || 'purge_cache' !== route.name) {
    return;
  }

  isPending.value = true;

  try {
    const { status } = await useDialog().confirmDialog({
      title: 'Purge cache?',
      message:
        'Purge shared cached data for every identity? Events in the Redis queue will be discarded. Local history, sync dates, and backend settings will be kept.',
      confirmText: 'Purge cache',
      confirmColor: 'error',
    });

    if (true !== status || disposed || 'purge_cache' !== route.name) {
      return;
    }

    isPurging.value = true;
    error.value = null;

    const response = await request('/system/cache', { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);

    if (disposed || 'purge_cache' !== route.name) {
      return;
    }

    if ('error' in json) {
      error.value = json;
      notification('error', 'Cache purge failed', `${json.error.code}: ${json.error.message}`);
      return;
    }

    if (true !== response.ok) {
      error.value = { error: { code: response.status, message: response.statusText } };
      notification('error', 'Cache purge failed', `${response.status}: ${response.statusText}`);
      return;
    }

    notification('success', 'Success', json.info?.message ?? 'Cache has been purged.');

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
    if (disposed || 'purge_cache' !== route.name) {
      return;
    }

    const message = e instanceof Error ? e.message : 'Unexpected error';
    error.value = { error: { code: 500, message } };
    notification('error', 'Cache purge failed', message);
  } finally {
    isPurging.value = false;
    isPending.value = false;
  }
};
</script>
