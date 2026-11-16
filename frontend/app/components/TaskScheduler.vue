<template>
  <UAlert
    v-if="!status.status || props.forceShow"
    :color="status.status ? 'success' : 'error'"
    variant="soft"
    :icon="status.status ? 'i-lucide-circle-check' : 'i-lucide-triangle-alert'"
    :title="`Task scheduler is ${status.status ? 'running' : 'not running'}.`"
    orientation="horizontal"
  >
    <template #description>
      <span>{{ status.message }}</span>
    </template>

    <template #actions>
      <div class="flex flex-wrap items-center gap-2">
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
          v-if="status.restartable"
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
    </template>
  </UAlert>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { UAlert, UButton } from '#components';
import { useDialog } from '~/composables/useDialog';
import { notification, request } from '~/utils';

const emit = defineEmits<{
  (e: 'update', status: { status: boolean; message: string; restartable: boolean }): void;
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
const isLoading = ref<boolean>(false);
const isRestarting = ref<boolean>(false);
const status = ref<{ status: boolean; message: string; restartable: boolean }>({
  status: true,
  message: 'Loading...',
  restartable: false,
});

const loadContent = async (): Promise<void> => {
  if (isLoading.value) {
    return;
  }

  try {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }

    isLoading.value = true;
    const response = await request('/system/scheduler');
    const json = await response.json();
    status.value = json;
    emit('update', json);
    timer = setTimeout(loadContent, 60000);
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
};

const restart = async (): Promise<void> => {
  if (isRestarting.value) {
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    message: 'Restart the task scheduler?',
    confirmText: 'Restart',
    confirmColor: 'warning',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    isRestarting.value = true;
    const response = await request('/system/scheduler/restart', { method: 'POST' });
    const json = await response.json();

    notification(
      200 === response.status ? 'success' : 'error',
      '',
      json.message ?? json.error?.message ?? '??',
    );

    if (200 !== response.status) {
      return;
    }

    status.value = json;
    emit('update', json);
  } catch (e) {
    console.error(e);
  } finally {
    isRestarting.value = false;
  }
};

onMounted(() => void loadContent());

onBeforeUnmount(() => {
  if (timer) {
    clearTimeout(timer);
    timer = null;
  }
});
</script>
