<template>
  <div class="space-y-4">
    <UFormField label="Browse as" name="identity">
      <USelect
        id="identity"
        v-model="selectedIdentity"
        :items="identities"
        icon="i-lucide-user"
        class="w-full"
        :disabled="isLoading || isSwitching"
      />
    </UFormField>

    <UAlert
      color="neutral"
      variant="subtle"
      icon="i-lucide-users"
      description="Each identity has its own backend settings & watch history."
    />

    <Teleport :to="actionsTarget" defer>
      <div class="flex flex-col justify-end gap-2 sm:flex-row">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          :disabled="!selectedIdentity || isLoading"
          :loading="isSwitching"
          @click="reloadPage"
        >
          Reload
        </UButton>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { navigateTo, useRouter } from '#app';
import { UAlert, UButton, UFormField, USelect } from '#components';
import { useStorage } from '@vueuse/core';
import { notification, parse_api_response, request } from '~/utils';
import type { IdentityListItem } from '~/types';

defineProps<{ actionsTarget: string }>();

const api_user = useStorage<string>('api_user', 'main');
const router = useRouter();
const selectedIdentity = ref(api_user.value);
const identities = ref<Array<string>>(['main']);
const isLoading = ref<boolean>(true);
const isSwitching = ref(false);

const emitter = defineEmits<{
  (e: 'close'): void;
}>();

onMounted(async (): Promise<void> => {
  try {
    const response = await request('/identities');
    const json = await parse_api_response<{ identities: Array<IdentityListItem> }>(response);

    if ('error' in json) {
      notification('error', 'Identities unavailable', json.error.message);
      identities.value = [api_user.value];
      return;
    }

    json.identities.forEach((identity) => {
      const name = identity.identity;
      if (!identities.value.includes(name)) {
        identities.value.push(name);
      }
    });
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Identities unavailable', message);
  } finally {
    isLoading.value = false;
  }
});

watch(api_user, (identity) => {
  selectedIdentity.value = identity;
});

const reloadPage = async (): Promise<void> => {
  if (isSwitching.value) {
    return;
  }

  const identity = selectedIdentity.value;
  isSwitching.value = true;
  emitter('close');

  try {
    if ('/' === router.currentRoute.value.path) {
      api_user.value = identity;
      window.location.reload();
      return;
    }

    // Keep requests on the current identity until unsaved-change guards allow leaving.
    await navigateTo('/');
    if ('/' !== router.currentRoute.value.path) {
      selectedIdentity.value = api_user.value;
      return;
    }

    api_user.value = identity;
  } finally {
    isSwitching.value = false;
  }
};
</script>
