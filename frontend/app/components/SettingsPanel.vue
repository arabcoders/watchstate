<template>
  <USlideover
    :open="isOpen"
    title="WebUI settings"
    side="right"
    :dismissible="true"
    :overlay="true"
    :ui="slideoverUi"
    @update:open="(open) => !open && emit('close')"
  >
    <template #body>
      <div class="divide-y divide-default">
        <section class="space-y-5 pb-6">
          <h2 class="flex items-center gap-2 font-semibold text-highlighted">
            <UIcon name="i-lucide-image" class="size-5 text-toned" />
            Appearance
          </h2>

          <div class="flex items-center justify-between gap-3">
            <span class="text-sm font-medium text-highlighted">Show posters</span>
            <USwitch v-model="poster_enable" color="neutral" aria-label="Show posters" />
          </div>

          <div class="space-y-4">
            <div class="flex items-center justify-between gap-3">
              <span class="text-sm font-medium text-highlighted">Show header artwork</span>
              <USwitch v-model="bg_enable" color="neutral" aria-label="Show header artwork" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
              <label class="text-sm font-medium text-default" for="random_bg_opacity">
                Header artwork visibility
              </label>
              <code class="text-sm text-toned">{{ Math.round(bgVisibilityModel * 100) }}%</code>
            </div>

            <USlider
              id="random_bg_opacity"
              v-model="bgVisibilityModel"
              color="primary"
              :min="0"
              :max="0.4"
              :step="0.01"
            />

            <UButton
              v-if="bg_enable"
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-refresh-cw"
              @click="emit('force_bg_reload')"
            >
              Reload artwork
            </UButton>
          </div>
        </section>

        <section class="space-y-4 py-6">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="flex items-center gap-2 font-semibold text-highlighted">
              <UIcon name="i-lucide-calendar-clock" class="size-5 text-toned" />
              Date format
            </h2>
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-rotate-ccw"
              @click="resetTooltipDateFormat"
            >
              Reset
            </UButton>
          </div>

          <UFormField id="tooltip_date_format" label="Tooltip format" name="tooltip_date_format">
            <UInput
              v-model="tooltip_date_format"
              icon="i-lucide-calendar-clock"
              class="w-full"
              :placeholder="DEFAULT_TOOLTIP_DATE_FORMAT"
            />
          </UFormField>

          <div class="space-y-1 text-sm text-toned">
            <p>
              Default:
              <code>{{ DEFAULT_TOOLTIP_DATE_FORMAT }}</code>
            </p>
            <p>
              Preview:
              <code>{{ tooltipDatePreview }}</code>
            </p>
          </div>
        </section>

        <section class="space-y-5 pt-6">
          <h2 class="flex items-center gap-2 font-semibold text-highlighted">
            <UIcon name="i-lucide-user-round" class="size-5 text-toned" />
            Account
          </h2>

          <form :id="passwordFormId" class="space-y-4" @submit.prevent="change_password">
            <input
              type="text"
              class="hidden"
              name="username"
              autocomplete="username"
              :value="username"
            />

            <UFormField id="current_password" label="Current password" name="current_password">
              <UInput
                v-model="user.current_password"
                type="password"
                icon="i-lucide-lock"
                :disabled="isLoading"
                class="w-full"
                placeholder="Current password"
                autocomplete="current-password"
                required
              />
            </UFormField>

            <UFormField id="new_password" label="New password" name="new_password">
              <UInput
                v-model="user.new_password"
                type="password"
                icon="i-lucide-lock"
                :disabled="isLoading"
                class="w-full"
                placeholder="New password"
                autocomplete="new-password"
                required
              />
            </UFormField>

            <UFormField
              id="new_password_confirm"
              label="Confirm new password"
              name="new_password_confirm"
            >
              <UInput
                v-model="user.new_password_confirm"
                type="password"
                icon="i-lucide-lock"
                :disabled="isLoading"
                class="w-full"
                autocomplete="new-password"
                placeholder="Confirm new password"
                required
              />
            </UFormField>
          </form>
        </section>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          icon="i-lucide-user-round-x"
          class="justify-center"
          :disabled="isLoading"
          @click="invalidate_sessions"
        >
          Invalidate sessions
        </UButton>

        <UButton
          type="submit"
          :form="passwordFormId"
          color="primary"
          variant="solid"
          icon="i-lucide-key-round"
          class="justify-center"
          :loading="isLoading"
          :disabled="isLoading"
        >
          Change password
        </UButton>
      </div>
    </template>
  </USlideover>
</template>

<script setup lang="ts">
import moment from 'moment';
import { computed, ref, useId } from 'vue';
import { UButton, UFormField, UIcon, UInput, USlideover, USlider, USwitch } from '#components';
import { navigateTo } from '#app';
import { useStorage } from '@vueuse/core';
import { useDialog } from '~/composables/useDialog';
import { useAuth } from '~/composables/useAuth';
import type { GenericError, GenericResponse } from '~/types';
import {
  DEFAULT_TOOLTIP_DATE_FORMAT,
  TOOLTIP_DATE_FORMAT,
  notification,
  parse_api_response,
  request,
} from '~/utils';

withDefaults(
  defineProps<{
    isOpen?: boolean;
  }>(),
  {
    isOpen: false,
  },
);

const emit = defineEmits<{
  (e: 'close' | 'force_bg_reload'): void;
}>();

const { username } = useAuth();
const passwordFormId = useId();

const bg_enable = useStorage<boolean>('bg_enable', true);
const poster_enable = useStorage<boolean>('poster_enable', true);
const bg_opacity = useStorage<number>('bg_opacity', 0.95);
const bgVisibilityModel = computed<number>({
  get: () => Number((1 - Number(bg_opacity.value)).toFixed(2)),
  set: (value) => {
    bg_opacity.value = Number((1 - Number(value)).toFixed(2));
  },
});
const tooltip_date_format = TOOLTIP_DATE_FORMAT;

const tooltipDatePreview = computed(() => moment().format(tooltip_date_format.value));

const slideoverUi = {
  content: 'ws-settings-panel w-full sm:max-w-2xl',
  body: 'p-4 sm:p-5',
};

const defaultValues = () => ({
  current_password: '',
  new_password: '',
  new_password_confirm: '',
});

const user = ref<{
  current_password: string;
  new_password: string;
  new_password_confirm: string;
}>(defaultValues());

const isLoading = ref<boolean>(false);

const resetTooltipDateFormat = (): void => {
  tooltip_date_format.value = DEFAULT_TOOLTIP_DATE_FORMAT;
};

const change_password = async (): Promise<void> => {
  if (
    !user.value.current_password ||
    !user.value.new_password ||
    !user.value.new_password_confirm
  ) {
    notification('Error', 'Error', 'All fields are required.', 2000);
    return;
  }

  if (user.value.new_password !== user.value.new_password_confirm) {
    notification('Error', 'Error', 'New passwords do not match.', 2000);
    return;
  }

  try {
    isLoading.value = true;
    const response = await request('/system/auth/change_password', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        new_password: user.value.new_password,
        current_password: user.value.current_password,
      }),
    });
    const json = await parse_api_response<GenericResponse>(response);
    if ('error' in json) {
      const errorJson = json as GenericError;
      notification('Error', 'Error', errorJson.error.message, 2000);
      return;
    }
    if (200 !== response.status) {
      notification('Error', 'Error', 'Failed to change password.', 2000);
      return;
    }
    notification('Success', 'Success', json.info.message);
    user.value = defaultValues();
  } finally {
    isLoading.value = false;
  }
};

const invalidate_sessions = async (): Promise<void> => {
  const { status } = await useDialog().confirmDialog({
    title: 'Invalidate all sessions?',
    message: 'Everyone, including you, will be signed out.',
    confirmText: 'Invalidate sessions',
    confirmColor: 'error',
  });

  if (true !== status) {
    return;
  }

  try {
    isLoading.value = true;
    const response = await request('/system/auth/sessions', { method: 'DELETE' });
    const json = await parse_api_response<GenericResponse>(response);
    if ('error' in json) {
      const errorJson = json as GenericError;
      notification('Error', 'Error', errorJson.error.message, 2000);
      return;
    }
    if (200 !== response.status) {
      notification('Error', 'Error', 'Failed to invalidate sessions.', 2000);
      return;
    }
    notification('Success', 'Success', json.info.message);
    const token = useStorage<string | null>('token', null);
    token.value = null;
    await navigateTo('/auth');
  } finally {
    isLoading.value = false;
  }
};
</script>
