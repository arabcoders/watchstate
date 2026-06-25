<template>
  <div class="space-y-6">
    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading identity configuration. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-else>
      <form id="identity_edit_form" class="space-y-6" @submit.prevent="saveContent">
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
                  @keydown.enter.prevent.stop="applyMiniCommand"
                />

                <UButton
                  color="primary"
                  variant="soft"
                  icon="i-lucide-play"
                  :disabled="!commandInput.trim()"
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
              title="Error"
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
              description="Do not edit the backend names, as indexing and data are keyed by them. This may lead to data loss."
            />

            <UFormField label="JSON configuration" name="config_content">
              <textarea
                v-model="configContent"
                rows="20"
                placeholder="Enter server configuration in JSON format..."
                class="min-h-96 w-full rounded-md border border-default bg-elevated/30 px-3 py-2 font-mono text-sm text-default outline-none transition focus:border-primary"
                @blur="formatJSON"
              />
            </UFormField>
          </div>

          <template #footer>
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
              <UButton
                type="button"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-braces"
                @click="formatJSON"
              >
                Format
              </UButton>

              <UButton
                type="button"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-x"
                @click="handleClose"
              >
                Cancel
              </UButton>

              <UButton
                type="submit"
                color="primary"
                variant="solid"
                size="sm"
                icon="i-lucide-save"
                :loading="isSaving"
                :disabled="isSaving"
              >
                Save
              </UButton>
            </div>
          </template>
        </UCard>
      </form>

      <UCard class="shadow-sm" :ui="tipsCardUi">
        <ul class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
          <li>
            Each backend should have: name, type, url, token, uuid, user, import, export, and
            options. Format: <code>{ "backend_name": { ... }, ... }</code>
          </li>
          <li class="text-error">
            Directly editing the config must only be done as last resort. Making mistakes may break
            the identity backend configurations, or lead to data loss.
          </li>
        </ul>
      </UCard>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useDirtyState } from '~/composables/useDirtyState';
import { notification, parse_api_response, request } from '~/utils';
import { applyCommand, parseCommand } from '~/utils/jsonCommand';
import type { GenericError, GenericResponse, JsonObject } from '~/types';

const props = withDefaults(
  defineProps<{
    identityId: string;
  }>(),
  {},
);

const emit = defineEmits<{
  close: [];
  saved: [];
  'dirty-change': [dirty: boolean];
}>();

const isLoading = ref<boolean>(false);
const isSaving = ref<boolean>(false);
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

const id = computed<string>(() => props.identityId);
const dirtySource = computed(() => ({
  configContent: configContent.value,
  commandInput: commandInput.value,
}));
const { isDirty, markClean } = useDirtyState(dirtySource);

const cardUi = {
  body: 'p-5',
  footer: 'border-t border-default px-5 py-4',
};

const tipsCardUi = {
  body: 'p-5',
};

type IdentitySaveError = GenericError & {
  errors?: Array<string>;
};

type IdentitySaveResponse = GenericResponse & {
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
  if (!id.value) {
    return;
  }

  resetEditorState();
  isLoading.value = true;

  try {
    const response = await request(`/identities/${id.value}`);
    const data = await parse_api_response<JsonObject>(response);

    if ('error' in data) {
      const errorData = data as GenericError;
      notification('error', 'Error', errorData.error.message);
      errorForm.value.message = errorData.error.message;
      return;
    }

    configContent.value = JSON.stringify(data, null, 2);
    markClean();
    emit('dirty-change', false);
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Failed to load identity configuration. ${message}`);
    errorForm.value.message = message;
  } finally {
    isLoading.value = false;
  }
};

const applyMiniCommand = async (): Promise<void> => {
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
      commandError.value = 'Current JSON is invalid. Please fix it before applying commands.';
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
    notification('error', 'Error', `Failed to apply command. ${message}`);
  }
};

const saveContent = async (): Promise<void> => {
  if (true === isSaving.value) {
    return;
  }

  errorForm.value.message = '';
  errorForm.value.details = [];
  isSaving.value = true;

  try {
    let data: JsonObject;

    try {
      data = JSON.parse(configContent.value) as JsonObject;
    } catch {
      errorForm.value.message = 'Invalid JSON format. Please check your syntax.';
      return;
    }

    const response = await request(`/identities/${id.value}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data),
    });

    const result = await parse_api_response<IdentitySaveResponse>(response);

    if ('error' in result) {
      const errorResult = result as IdentitySaveError;
      errorForm.value.message = errorResult.error.message;
      if (errorResult.errors && Array.isArray(errorResult.errors)) {
        errorForm.value.details = errorResult.errors;
      }
      return;
    }

    notification('success', 'Success', `Backend configuration updated for identity '${id.value}'`);
    markClean();
    emit('dirty-change', false);
    emit('saved');
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    errorForm.value.message = `Failed to save configuration. ${message}`;
  } finally {
    isSaving.value = false;
  }
};

const formatJSON = (): void => {
  if (0 === configContent.value.trim().length) {
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

const handleClose = (): void => {
  emit('close');
};

watch(isDirty, (value: boolean) => emit('dirty-change', value));

watch(
  () => props.identityId,
  async (value: string, oldValue: string | undefined) => {
    if (!value || value === oldValue) {
      return;
    }

    await loadContent();
  },
  { immediate: true },
);
</script>
