<template>
  <form :id="formId" class="space-y-5" @submit.prevent="addNewGuid">
    <fieldset :disabled="isSaving" class="min-w-0 space-y-5">
      <UFormField
        label="GUID name"
        name="name"
        description="Lowercase letters, digits, and underscores. The guid_ prefix is added automatically."
        required
      >
        <UInput
          v-model="form.name"
          type="text"
          icon="i-lucide-id-card"
          class="w-full"
          placeholder="regionaldb"
        />
      </UFormField>

      <UFormField label="Description" name="description" required>
        <UInput
          v-model="form.description"
          type="text"
          icon="i-lucide-text"
          class="w-full"
          placeholder="Regional movie database IDs"
        />
      </UFormField>

      <section aria-labelledby="guid-validation-heading" class="ws-card-padded-lg space-y-5">
        <h3 id="guid-validation-heading" class="text-sm font-semibold text-highlighted">
          Validation
        </h3>
        <UFormField label="Validation pattern" name="validator.pattern" required>
          <UInput
            v-model="form.validator.pattern"
            type="text"
            icon="i-lucide-circle-check"
            class="w-full font-mono"
            placeholder="/^[0-9\\/]+$/i"
          />
          <template #help>
            The regex must accept relative GUIDs such as <code>123/1/1</code>.
            <ULink
              href="https://regex101.com"
              target="_blank"
              rel="noopener noreferrer"
              class="text-primary"
              external
            >
              regex101.com </ULink
            >.
          </template>
        </UFormField>

        <UFormField
          label="Value example"
          name="validator.example"
          description="Shown when a GUID value fails validation."
          required
        >
          <UInput
            v-model="form.validator.example"
            type="text"
            icon="i-lucide-pencil-ruler"
            class="w-full"
            placeholder="(number)"
          />
        </UFormField>
        <section
          aria-labelledby="valid-values-heading"
          class="space-y-3 border-t border-default pt-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h4 id="valid-values-heading" class="text-sm font-semibold text-highlighted">
              Valid values
            </h4>
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-plus"
              @click="form.validator.tests.valid.push('')"
              label="Add value"
            />
          </div>
          <p class="text-sm text-toned">At least one value that the pattern accepts.</p>
          <div
            v-for="(_, index) in form.validator.tests.valid"
            :key="`valid-${index}`"
            class="flex items-center gap-2"
          >
            <UInput
              v-model="form.validator.tests.valid[index]"
              type="text"
              icon="i-lucide-circle-check"
              :aria-label="`Valid value ${index + 1}`"
              class="min-w-0 flex-1"
            />
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :disabled="1 > index || 1 > form.validator.tests.valid.length"
              @click="form.validator.tests.valid.splice(index, 1)"
              label="Remove"
            />
          </div>
        </section>

        <section
          aria-labelledby="invalid-values-heading"
          class="space-y-3 border-t border-default pt-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h4 id="invalid-values-heading" class="text-sm font-semibold text-highlighted">
              Invalid values
            </h4>
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-plus"
              @click="form.validator.tests.invalid.push('')"
              label="Add value"
            />
          </div>
          <p class="text-sm text-toned">At least one value that the pattern rejects.</p>
          <div
            v-for="(_, index) in form.validator.tests.invalid"
            :key="`invalid-${index}`"
            class="flex items-center gap-2"
          >
            <UInput
              v-model="form.validator.tests.invalid[index]"
              type="text"
              icon="i-lucide-circle-x"
              :aria-label="`Invalid value ${index + 1}`"
              class="min-w-0 flex-1"
            />
            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-trash-2"
              :disabled="1 > index || 1 > form.validator.tests.invalid.length"
              @click="form.validator.tests.invalid.splice(index, 1)"
              label="Remove"
            />
          </div>
        </section>
      </section>
    </fieldset>

    <Teleport defer :to="props.actionsTarget">
      <div class="flex w-full justify-end gap-2">
        <UButton
          type="submit"
          :form="formId"
          color="primary"
          variant="solid"
          size="sm"
          icon="i-lucide-save"
          :loading="isSaving"
          :disabled="false === validForm || isSaving"
          label="Save"
        />
      </div>
    </Teleport>
  </form>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, useId } from 'vue';
import { UButton, UFormField, UInput, ULink } from '#components';
import { useDirtyState } from '~/composables/useDirtyState';
import { request, notification, stringToRegex, parse_api_response } from '~/utils';
import type { CustomGUID, GenericResponse, GuidProvider } from '~/types';

const props = defineProps<{ actionsTarget: string }>();
const formId = useId();

const emit = defineEmits<{
  (e: 'saved'): void;
  (e: 'dirty-change' | 'saving-change', value: boolean): void;
}>();

const defaultData = (): CustomGUID => ({
  name: '',
  type: 'string',
  description: '',
  validator: {
    pattern: '/^[0-9\\/]+$/i',
    example: '(number)',
    tests: {
      valid: ['1234567', '1234567/1/1'],
      invalid: ['1234567a', 'a1234567'],
    },
  },
});

const form = ref<CustomGUID>(defaultData());
const guids = ref<Array<GuidProvider>>([]);
const isSaving = ref(false);
const dirtySource = computed(() => form.value);
const { isDirty, markClean } = useDirtyState(dirtySource);
markClean();

onMounted(async (): Promise<void> => {
  try {
    const response = await request('/system/guids');
    const data = await parse_api_response<Array<GuidProvider>>(response);

    if ('error' in data) {
      notification('error', 'Unable to load GUIDs', data.error.message);
      return;
    }

    guids.value = data;
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : 'Unexpected error';
    notification('error', 'Unable to load GUIDs', message, 5000);
  }
});

const addNewGuid = async (): Promise<void> => {
  if (isSaving.value) {
    return;
  }

  if (!validForm.value) {
    notification('error', 'GUID not added', 'Invalid form data.', 5000);
    return;
  }

  const data = form.value;
  data.name = data.name.trim();

  if (data.name.toLowerCase() !== data.name) {
    notification('error', 'GUID not added', 'GUID name must be lowercase.', 5000);
    return;
  }

  if (false === stringToRegex('/^[a-z0-9_]+$/').test(data.name)) {
    notification(
      'error',
      'GUID not added',
      'GUID names can contain only lowercase letters, digits, and underscores.',
      5000,
    );
    return;
  }

  if (data.name.includes(' ')) {
    notification('error', 'GUID not added', 'GUID name must not contain spaces.', 5000);
    return;
  }

  data.type = data.type.trim().toLowerCase();
  if (!['string'].includes(data.type)) {
    notification('error', 'GUID not added', 'Invalid GUID type.', 5000);
    return;
  }

  try {
    for (const g of guids.value) {
      if (g.guid === data.name) {
        notification(
          'error',
          'GUID not added',
          `GUID with name '${data.name}' already exists.`,
          5000,
        );
        return;
      }
    }
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : 'Unexpected error';
    notification('error', 'GUID not added', message, 5000);
    return;
  }

  try {
    const validator = stringToRegex(data.validator.pattern);

    for (let i = 0; i < data.validator.tests.valid.length; i++) {
      const validValue = data.validator.tests.valid[i];
      if (!validValue || !validator.test(validValue)) {
        notification(
          'error',
          'GUID not added',
          `Valid value '${i}' '${validValue}' did not match '${data.validator.pattern}'.`,
          5000,
        );
        return;
      }
      if (!validator.test(validValue + '/1')) {
        notification(
          'error',
          'GUID not added',
          `Valid value '${i}' with relative info '${validValue + '/1'}' did not match '${data.validator.pattern}'.`,
          5000,
        );
        return;
      }
    }

    for (let i = 0; i < data.validator.tests.invalid.length; i++) {
      const invalidValue = data.validator.tests.invalid[i];
      if (!invalidValue) {
        continue;
      }
      if (validator.test(invalidValue)) {
        notification(
          'error',
          'GUID not added',
          `Invalid value '${i}' '${invalidValue}' matched '${data.validator.pattern}'.`,
          5000,
        );
        return;
      }
    }
  } catch {
    notification('error', 'GUID not added', 'Invalid regex pattern.', 5000);
    return;
  }

  isSaving.value = true;

  try {
    const response = await request('/system/guids/custom', {
      method: 'PUT',
      body: JSON.stringify(data),
    });
    const json = await parse_api_response<GenericResponse>(response);

    if ('error' in json) {
      notification('error', 'GUID not added', `${json.error.code}: ${json.error.message}`, 5000);
      return;
    }

    if (!response.ok) {
      notification('error', 'GUID not added', `Request failed (${response.status})`, 5000);
      return;
    }

    notification('success', 'GUID added', `'${data.name}' was added.`, 5000);
    form.value = defaultData();
    markClean();
    isSaving.value = false;
    emit('dirty-change', false);
    emit('saved');
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'GUID not added', message, 5000);
  } finally {
    isSaving.value = false;
  }
};

const validForm = computed((): boolean => {
  const data = form.value;

  if (!data.name || !data.type || !data.description) {
    return false;
  }

  if (!data.validator.pattern || !data.validator.example) {
    return false;
  }

  if (!data.validator.tests.valid.length || !data.validator.tests.invalid.length) {
    return false;
  }

  return !(!data.validator.tests.valid[0] || !data.validator.tests.invalid[0]);
});

watch(isDirty, (value: boolean) => emit('dirty-change', value));
watch(isSaving, (value: boolean) => emit('saving-change', value), { flush: 'sync' });
</script>
