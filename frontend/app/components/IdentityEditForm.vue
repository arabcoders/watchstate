<template>
  <div class="space-y-6">
    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading identity configuration"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="!hasConfig"
      color="error"
      variant="soft"
      icon="i-lucide-circle-alert"
      title="Identity configuration could not be loaded"
      :description="errorForm.message"
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          @click="() => void loadContent()"
        >
          Retry
        </UButton>
      </template>
    </UAlert>

    <template v-else>
      <form :id="formId" class="space-y-6" @submit.prevent="saveContent">
        <UCard class="shadow-sm" :ui="cardUi">
          <div class="space-y-5">
            <UFormField name="command_input">
              <template #label>
                <div class="flex items-center gap-2">
                  <UIcon name="i-lucide-terminal" class="size-4 text-toned" />
                  <span>Mini command</span>
                </div>
              </template>

              <div class="flex gap-2">
                <UInput
                  v-model="commandInput"
                  icon="i-lucide-terminal"
                  class="min-w-0 flex-1"
                  placeholder="s/backend1.import.enabled/false/"
                  :disabled="isSaving"
                  @keydown.enter.prevent.stop="applyMiniCommand"
                />

                <UButton
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-play"
                  :disabled="isSaving || !commandInput.trim()"
                  @click="applyMiniCommand"
                >
                  Run
                </UButton>
              </div>

              <p class="mt-2 text-sm text-toned">
                Use <code>s/path/value/</code> or <code>d/path/</code>. Escape literal
                <code>/</code> as <code>\/</code>.
              </p>

              <p v-if="commandError" class="mt-2 text-sm text-error">
                {{ commandError }}
              </p>
            </UFormField>

            <UAlert
              v-if="errorForm.message"
              color="error"
              variant="soft"
              icon="i-lucide-triangle-alert"
              title="Identity configuration error"
              :close="{
                onClick: () => {
                  errorForm.message = '';
                },
              }"
            >
              <template #description>
                <div class="space-y-2 text-sm text-default">
                  <p class="font-semibold">{{ errorForm.message }}</p>
                  <ul
                    v-if="errorForm.details && errorForm.details.length > 0"
                    class="list-disc space-y-1 pl-5"
                  >
                    <li v-for="(detail, index) in errorForm.details" :key="index">{{ detail }}</li>
                  </ul>
                </div>
              </template>
            </UAlert>

            <UAlert
              color="warning"
              variant="soft"
              icon="i-lucide-triangle-alert"
              title=""
              description="Do not change backend names. Indexes and data use these names as keys; changing them can cause data loss."
            />

            <UFormField label="JSON configuration" name="config_content">
              <textarea
                v-model="configContent"
                rows="20"
                :disabled="isSaving"
                placeholder="Enter server configuration in JSON format..."
                class="min-h-96 w-full rounded-md border border-default bg-elevated/30 px-3 py-2 font-mono text-sm text-default outline-none transition focus:border-primary"
                @blur="formatJSON"
              />
            </UFormField>

            <div class="flex justify-end">
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-braces"
                :disabled="isSaving"
                @click="formatJSON"
              >
                Format
              </UButton>
            </div>
          </div>
        </UCard>
      </form>

      <UCard class="shadow-sm" :ui="tipsCardUi">
        <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
          <li>
            Each backend should have: name, type, url, token, uuid, user, import, export, and
            options. Format: <code>{ "backend_name": { ... }, ... }</code>
          </li>
          <li class="text-error">
            Edit this configuration only as a last resort. Mistakes can break the identity's backend
            configurations or cause data loss.
          </li>
        </ul>
      </UCard>

      <Teleport :to="actionsTarget" defer>
        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
          <UButton
            :form="formId"
            type="submit"
            color="primary"
            variant="solid"
            size="sm"
            icon="i-lucide-save"
            :loading="isSaving"
            :disabled="isSaving || !hasConfig"
          >
            Save
          </UButton>
        </div>
      </Teleport>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { UAlert, UButton, UCard, UFormField, UIcon, UInput } from '#components';
import { useDirtyState } from '~/composables/useDirtyState';
import { notification, parse_api_response, request } from '~/utils';
import { applyCommand, parseCommand } from '~/utils/jsonCommand';
import type { GenericError, JsonObject } from '~/types';

const props = withDefaults(
  defineProps<{
    identityId: string;
    actionsTarget: string;
  }>(),
  {},
);

const emit = defineEmits<{
  saved: [];
  'dirty-change': [dirty: boolean];
}>();

const isLoading = ref<boolean>(false);
const formId = useId();
const isSaving = ref<boolean>(false);
const hasConfig = ref(false);
const configContent = ref<string>('');
const errorForm = ref<{
  message: string;
  details?: Array<string>;
}>({
  message: '',
  details: [],
});
const commandInput = ref<string>('');
const commandError = ref<string>('');
let loadId = 0;
let disposed = false;
let loadController: AbortController | null = null;
let saveController: AbortController | null = null;

const id = computed<string>(() => props.identityId);
const dirtySource = computed(() => ({
  configContent: configContent.value,
  commandInput: commandInput.value,
}));
const { isDirty, markClean } = useDirtyState(dirtySource);

const cardUi = {
  body: 'p-5',
};

const tipsCardUi = {
  body: 'p-5',
};

type IdentitySaveError = GenericError & {
  errors?: Array<string>;
};

const resetEditorState = (): void => {
  configContent.value = '';
  commandInput.value = '';
  commandError.value = '';
  errorForm.value = {
    message: '',
    details: [],
  };
};

const loadContent = async (): Promise<void> => {
  if (disposed || !id.value) {
    return;
  }

  loadController?.abort();
  saveController?.abort();
  const controller = new AbortController();
  loadController = controller;
  const generation = ++loadId;
  const identityId = id.value;
  resetEditorState();
  hasConfig.value = false;
  isLoading.value = true;
  isSaving.value = false;

  try {
    const response = await request(`/identities/${identityId}`, { signal: controller.signal });
    const data = await parse_api_response<JsonObject>(response);

    if (disposed || generation !== loadId || identityId !== id.value) {
      return;
    }

    if ('error' in data) {
      const errorData = data as GenericError;
      throw new Error(errorData.error.message);
    }
    if (!response.ok) {
      throw new Error(`Identity configuration request failed (${response.status}).`);
    }

    configContent.value = JSON.stringify(data, null, 2);
    hasConfig.value = true;
    markClean();
    emit('dirty-change', false);
  } catch (error: unknown) {
    if (disposed || generation !== loadId || controller.signal.aborted) {
      return;
    }

    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Identity configuration unavailable', message);
    errorForm.value.message = message;
  } finally {
    if (!disposed && generation === loadId) {
      isLoading.value = false;
    }
  }
};

const applyMiniCommand = async (): Promise<void> => {
  if (disposed || isSaving.value || !hasConfig.value) {
    return;
  }

  commandError.value = '';

  try {
    if (0 === commandInput.value.trim().length) {
      commandError.value = 'Command cannot be empty.';
      return;
    }

    let obj: JsonObject;
    try {
      obj = JSON.parse(configContent.value) as JsonObject;
    } catch {
      commandError.value = 'Fix the invalid JSON before applying commands.';
      return;
    }

    const parsed = parseCommand(commandInput.value);
    if (!parsed.ok) {
      commandError.value = parsed.error;
      notification('error', 'Invalid command', parsed.error);
      return;
    }

    const result = applyCommand(obj, parsed.command);
    if (!result.ok) {
      commandError.value = result.error;
      notification('error', 'Failed to apply command', result.error);
      return;
    }

    configContent.value = JSON.stringify(result.obj, null, 2);
    commandInput.value = '';
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    commandError.value = message;
    notification('error', 'Command not applied', message);
  }
};

const saveContent = async (): Promise<void> => {
  if (disposed || isLoading.value || isSaving.value || !hasConfig.value) {
    return;
  }

  const generation = loadId;
  const identityId = id.value;
  const controller = new AbortController();
  saveController = controller;
  errorForm.value.message = '';
  errorForm.value.details = [];
  isSaving.value = true;

  try {
    let data: JsonObject;

    try {
      data = JSON.parse(configContent.value) as JsonObject;
    } catch {
      errorForm.value.message = 'Invalid JSON. Check the syntax.';
      return;
    }

    const response = await request(`/identities/${identityId}`, {
      method: 'PUT',
      signal: controller.signal,
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data),
    });

    const result = await parse_api_response<JsonObject>(response);

    if (disposed || generation !== loadId || identityId !== id.value) {
      return;
    }

    if ('error' in result) {
      const errorResult = result as IdentitySaveError;
      errorForm.value.message = errorResult.error.message;
      if (errorResult.errors && Array.isArray(errorResult.errors)) {
        errorForm.value.details = errorResult.errors;
      }
      return;
    }
    if (!response.ok) {
      throw new Error(`Identity configuration update failed (${response.status}).`);
    }

    notification(
      'success',
      'Identity configuration saved',
      `Backend configuration updated for identity '${identityId}'`,
    );
    markClean();
    emit('dirty-change', false);
    emit('saved');
  } catch (error: unknown) {
    if (disposed || generation !== loadId || controller.signal.aborted) {
      return;
    }

    const message = error instanceof Error ? error.message : 'Unexpected error';
    errorForm.value.message = `Failed to save configuration. ${message}`;
  } finally {
    if (!disposed && generation === loadId) {
      isSaving.value = false;
    }
  }
};

const formatJSON = (): void => {
  if (disposed || isSaving.value || !hasConfig.value || 0 === configContent.value.trim().length) {
    return;
  }

  try {
    const data = JSON.parse(configContent.value);
    configContent.value = JSON.stringify(data, null, 2);
    errorForm.value.message = '';
    errorForm.value.details = [];
  } catch {
    // user may still be editing invalid JSON
  }
};

watch(isDirty, (value: boolean) => emit('dirty-change', value));

watch(
  () => props.identityId,
  async (value: string, oldValue: string | undefined) => {
    if (!value || oldValue === value) {
      return;
    }

    await loadContent();
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  disposed = true;
  loadId++;
  loadController?.abort();
  saveController?.abort();
});
</script>
