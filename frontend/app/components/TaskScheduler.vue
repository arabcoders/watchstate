<template>
  <section
    v-if="props.forceShow || false === status?.status || loadError"
    aria-label="Task scheduler"
    class="space-y-3"
  >
    <div class="ws-card flex flex-wrap items-center justify-between gap-3 p-4">
      <div class="min-w-0 space-y-1">
        <div class="flex flex-wrap items-center gap-2">
          <UIcon name="i-lucide-cpu" class="size-4 shrink-0 text-toned" />
          <h2 class="text-sm font-semibold text-highlighted">Task scheduler</h2>
          <UBadge :color="statusBadge.color" variant="soft" size="xs" :icon="statusBadge.icon">
            {{ statusBadge.label }}
          </UBadge>
        </div>
      </div>

      <div class="flex shrink-0 flex-wrap items-center gap-2">
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          @click="loadContent"
        >
          Refresh
        </UButton>

        <UButton
          v-if="status?.restartable"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-power"
          :loading="isRestarting"
          @click="restart"
        >
          Restart
        </UButton>
      </div>
    </div>

    <UAlert
      v-if="loadError || false === status?.status"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      :title="loadError ? 'Scheduler status unavailable' : 'Task scheduler is not running'"
      :description="loadError || status?.message"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { UAlert, UBadge, UButton, UIcon } from '#components';
import { useDialog } from '~/composables/useDialog';
import type { SchedulerStatus } from '~/types';
import { notification, parse_api_response, request } from '~/utils';

const emit = defineEmits<{
  (e: 'update', status: SchedulerStatus): void;
}>();

const props = withDefaults(
  defineProps<{
    /** Force show the scheduler status */
    forceShow?: boolean;
  }>(),
  {
    forceShow: false,
  },
);

let timer: ReturnType<typeof setTimeout> | null = null;
let disposed = false;
const isLoading = ref<boolean>(false);
const isRestarting = ref<boolean>(false);
const status = ref<SchedulerStatus | null>(null);
const loadError = ref('');
const statusBadge = computed(() => {
  if (loadError.value) {
    return { label: 'Unavailable', icon: 'i-lucide-circle-alert', color: 'error' as const };
  }
  if (null === status.value) {
    return { label: 'Checking', icon: 'i-lucide-loader-circle', color: 'neutral' as const };
  }

  return status.value.status
    ? { label: 'Running', icon: 'i-lucide-circle-check', color: 'success' as const }
    : { label: 'Stopped', icon: 'i-lucide-circle-stop', color: 'error' as const };
});

const loadContent = async (): Promise<void> => {
  if (disposed || isLoading.value) {
    return;
  }

  try {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }

    isLoading.value = true;
    const response = await request('/system/scheduler');
    const json = await parse_api_response<SchedulerStatus>(response);

    if (disposed) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (!response.ok) {
      throw new Error(`Scheduler request failed (${response.status}).`);
    }

    loadError.value = '';
    status.value = json;
    emit('update', json);
  } catch (error: unknown) {
    if (!disposed) {
      loadError.value = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', 'Scheduler status unavailable', loadError.value);
    }
  } finally {
    if (!disposed) {
      isLoading.value = false;
      timer = setTimeout(loadContent, 60000);
    }
  }
};

const restart = async (): Promise<void> => {
  if (disposed || isRestarting.value) {
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: 'Restart the task scheduler?',
    confirmText: 'Restart',
    confirmColor: 'warning',
  });

  if (disposed || true !== confirmStatus) {
    return;
  }

  try {
    isRestarting.value = true;
    const response = await request('/system/scheduler/restart', { method: 'POST' });
    const json = await parse_api_response<SchedulerStatus>(response);

    if (disposed) {
      return;
    }
    if ('error' in json) {
      throw new Error(json.error.message);
    }

    notification(200 === response.status ? 'success' : 'error', '', json.message);

    if (200 !== response.status) {
      return;
    }

    loadError.value = '';
    status.value = json;
    emit('update', json);
  } catch (error: unknown) {
    if (!disposed) {
      const message = error instanceof Error ? error.message : 'Unexpected request error';
      notification('error', '', message);
    }
  } finally {
    isRestarting.value = false;
  }
};

onMounted(() => void loadContent());

onBeforeUnmount(() => {
  disposed = true;
  if (timer) {
    clearTimeout(timer);
    timer = null;
  }
});
</script>
