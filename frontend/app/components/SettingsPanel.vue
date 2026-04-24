<template>
  <USlideover
    :open="isOpen"
    side="right"
    :dismissible="true"
    :overlay="true"
    :ui="slideoverUi"
    @update:open="(open) => !open && emit('close')"
  >
    <template #header>
      <div class="flex w-full items-start gap-3">
        <div class="min-w-0 flex-1">
          <p class="text-base font-semibold text-highlighted">WebUI Settings</p>
          <p class="text-sm text-toned">Adjust interface behavior.</p>
        </div>

        <UButton
          color="neutral"
          variant="ghost"
          size="sm"
          square
          icon="i-lucide-x"
          class="ml-auto shrink-0 sm:hidden"
          @click="emit('close')"
        />
      </div>
    </template>

    <template #body>
      <div class="space-y-5">
        <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-settings" class="size-5 text-toned" />
              <span class="font-semibold text-highlighted">Password & Sessions</span>
            </div>
          </template>

          <form class="space-y-4">
            <input
              type="text"
              class="hidden"
              name="username"
              autocomplete="username"
              :value="username"
            />

            <UFormField label="Current password" name="current_password">
              <UInput
                id="current_password"
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

            <UFormField label="New password" name="new_password">
              <UInput
                id="new_password"
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

            <UFormField label="Confirm new password" name="new_password_confirm">
              <UInput
                id="new_password_confirm"
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

            <div class="flex flex-col gap-2 sm:flex-row">
              <UButton
                type="button"
                color="primary"
                variant="solid"
                icon="i-lucide-key-round"
                class="w-full justify-center"
                :loading="isLoading"
                :disabled="isLoading"
                @click="change_password"
              >
                Change Password
              </UButton>

              <UButton
                type="button"
                color="neutral"
                variant="outline"
                icon="i-lucide-user-round-x"
                class="w-full justify-center"
                :disabled="isLoading"
                @click="invalidate_sessions"
              >
                Invalidate Sessions
              </UButton>
            </div>
          </form>
        </UCard>

        <UCard class="border border-default/70 shadow-sm" :ui="sectionCardUi">
          <template #header>
            <div class="flex items-center gap-2">
              <UIcon name="i-lucide-circle-help" class="size-5 text-toned" />
              <span class="font-semibold text-highlighted">WebUI Look & Feel</span>
            </div>
          </template>

          <div class="space-y-5">
            <div class="rounded-md border border-default bg-elevated/30 px-3 py-3">
              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-sm font-medium text-highlighted">Show posters</div>
                  <p class="mt-1 text-sm text-toned">
                    {{
                      poster_enable
                        ? 'Poster artwork is currently visible.'
                        : 'Poster artwork is currently hidden.'
                    }}
                  </p>
                </div>

                <USwitch v-model="poster_enable" color="neutral" />
              </div>
            </div>

            <div class="space-y-3 rounded-md border border-default bg-elevated/30 p-4">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p class="font-medium text-default">Backgrounds from backends</p>
                  <p class="text-sm text-toned">Use backend fanart as page background.</p>
                </div>

                <UButton
                  v-if="bg_enable"
                  type="button"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  icon="i-lucide-refresh-cw"
                  @click="emit('force_bg_reload')"
                >
                  Reload
                </UButton>
              </div>

              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="text-sm font-medium text-highlighted">Backgrounds enabled</div>
                  <p class="mt-1 text-sm text-toned">
                    {{
                      bg_enable
                        ? 'Fanart backgrounds are active.'
                        : 'Fanart backgrounds are disabled.'
                    }}
                  </p>
                </div>

                <USwitch v-model="bg_enable" color="neutral" />
              </div>
            </div>

            <div class="space-y-3 rounded-md border border-default bg-elevated/30 p-4">
              <div class="flex items-center justify-between gap-3">
                <label class="text-sm font-medium text-default" for="random_bg_opacity">
                  Background visibility
                </label>
                <code>{{ (1.0 - parseFloat(String(bg_opacity))).toFixed(2) }}</code>
              </div>

              <USlider
                id="random_bg_opacity"
                v-model="bg_opacity"
                color="primary"
                :min="0.6"
                :max="1"
                :step="0.01"
              />
            </div>

            <div class="space-y-3 rounded-md border border-default bg-elevated/30 p-4">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p class="font-medium text-default">Date format</p>
                  <p class="text-sm text-toned">Used to format timestamps across the WebUI.</p>
                </div>

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

              <UFormField label="Format" name="tooltip_date_format">
                <UInput
                  id="tooltip_date_format"
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
            </div>
          </div>
        </UCard>
      </div>
    </template>
  </USlideover>
</template>

<script setup lang="ts">
import moment from 'moment';
import { computed, ref } from 'vue';
import { navigateTo } from '#app';
import { useStorage } from '@vueuse/core';
import { useDialog } from '~/composables/useDialog';
import { useAuthStore } from '~/store/auth';
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

const { username } = useAuthStore();

const bg_enable = useStorage<boolean>('bg_enable', true);
const poster_enable = useStorage<boolean>('poster_enable', true);
const bg_opacity = useStorage<number>('bg_opacity', 0.95);
const tooltip_date_format = TOOLTIP_DATE_FORMAT;

const tooltipDatePreview = computed(() => moment().format(tooltip_date_format.value));

const slideoverUi = {
  content: 'ws-settings-panel w-full sm:max-w-2xl',
  body: 'p-4 sm:p-5',
};

const sectionCardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
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
    title: 'Invalidate All Sessions',
    message:
      'This will log out all users including yourself. You will need to log in again. Do you want to continue?',
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
