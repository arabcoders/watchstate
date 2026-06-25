<template>
  <form id="custom_guid_form" class="space-y-5" @submit.prevent="addNewGuid">
    <div class="space-y-2">
      <label for="form_guid_name" class="text-sm font-medium text-highlighted">Name</label>
      <UInput
        id="form_guid_name"
        v-model="form.name"
        type="text"
        icon="i-lucide-id-card"
        class="w-full"
        placeholder="foobar"
      />
      <p class="text-sm leading-6 text-toned">
        The internal GUID reference name. The rules are lower case <code>[a-z]</code>,
        <code>0-9</code>, no space. For example, <code>guid_imdb</code>. The guid name will be
        automatically prefixed with <code>guid_</code>.
      </p>
    </div>

    <div class="space-y-2">
      <label for="form_description" class="text-sm font-medium text-highlighted">Description</label>
      <UInput
        id="form_description"
        v-model="form.description"
        type="text"
        icon="i-lucide-mail-open"
        class="w-full"
        placeholder="This GUID is based on ... db reference"
      />
      <p class="text-sm leading-6 text-toned">GUID description, for information purposes only.</p>
    </div>

    <div class="space-y-2">
      <label for="form_select_type" class="text-sm font-medium text-highlighted">Type</label>
      <USelect
        id="form_select_type"
        v-model="form.type"
        :items="typeItems"
        value-key="value"
        placeholder="Select Type"
        icon="i-lucide-settings"
        class="w-full"
      />
      <p class="text-sm leading-6 text-toned">We currently only support string type.</p>
    </div>

    <div class="space-y-2">
      <label for="form_validation_pattern" class="text-sm font-medium text-highlighted">
        Regex validation pattern
      </label>
      <UInput
        id="form_validation_pattern"
        v-model="form.validator.pattern"
        type="text"
        icon="i-lucide-circle-check"
        class="w-full"
        placeholder="/^[0-9\\/]+$/i"
      />
      <p class="text-sm leading-6 text-toned">
        A valid regular expression to check the GUID value. To test your patterns, you can use
        <ULink href="https://regex101.com" target="_blank" class="text-primary" external>
          regex101.com.
        </ULink>
      </p>
    </div>

    <div class="space-y-2">
      <label for="form_validation_example" class="text-sm font-medium text-highlighted">
        Value example
      </label>
      <UInput
        id="form_validation_example"
        v-model="form.validator.example"
        type="text"
        icon="i-lucide-pencil-ruler"
        class="w-full"
        placeholder="(number)"
      />
      <p class="text-sm leading-6 text-toned">
        The example to show when invalid value was checked. For example, <code>(number)</code>. For
        information purposes only.
      </p>
    </div>

    <UCard class="shadow-sm" :ui="nestedCardUi">
      <template #header>
        <div class="space-y-2">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <h2 class="text-sm font-semibold text-highlighted">Correct values</h2>
            </div>

            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-plus"
              @click="form.validator.tests.valid.push('')"
            >
              Add
            </UButton>
          </div>

          <p class="text-sm text-toned">
            The values added here must match the pattern defined above.
          </p>
        </div>
      </template>

      <div class="space-y-3">
        <div
          v-for="(_, index) in form.validator.tests.valid"
          :key="`valid-${index}`"
          class="flex gap-2 flex-row"
        >
          <UInput
            :id="`valid-${index}`"
            v-model="form.validator.tests.valid[index]"
            type="text"
            icon="i-lucide-circle-check"
            class="flex-1"
          />

          <UButton
            type="button"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            class="justify-center sm:shrink-0"
            :disabled="index < 1 || form.validator.tests.valid.length < 1"
            @click="form.validator.tests.valid.splice(index, 1)"
          >
            <span class="hidden sm:inline">Remove</span>
          </UButton>
        </div>

        <p class="text-sm text-toned">
          Example: <code>123</code>. Additionally, the pattern also must support
          <code>/</code> being part of the value. as we used it for relative GUIDs. The
          <code>(number)/1/1</code>
          refers to a relative GUID. There must be a minimum of 1 correct value.
        </p>
      </div>
    </UCard>

    <UCard class="shadow-sm" :ui="nestedCardUi">
      <template #header>
        <div class="space-y-2">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <h2 class="text-sm font-semibold text-highlighted">Incorrect values</h2>
            </div>

            <UButton
              type="button"
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-plus"
              @click="form.validator.tests.invalid.push('')"
            >
              Add
            </UButton>
          </div>

          <p class="text-sm text-toned">
            GUID values with should not match the pattern defined above.
          </p>
        </div>
      </template>

      <div class="space-y-3">
        <div
          v-for="(_, index) in form.validator.tests.invalid"
          :key="`invalid-${index}`"
          class="flex gap-2 flex-row"
        >
          <UInput
            :id="`invalid-${index}`"
            v-model="form.validator.tests.invalid[index]"
            type="text"
            icon="i-lucide-circle-x"
            class="flex-1"
          />

          <UButton
            type="button"
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-trash-2"
            class="justify-center sm:shrink-0"
            :disabled="index < 1 || form.validator.tests.invalid.length < 1"
            @click="form.validator.tests.invalid.splice(index, 1)"
          >
            <span class="hidden sm:inline">Remove</span>
          </UButton>
        </div>

        <p class="text-sm text-toned">
          Example: <code>abc</code>. There must be a minimum of 1 incorrect value.
        </p>
      </div>
    </UCard>

    <div class="flex w-full gap-2 flex-row justify-end">
      <UButton
        type="button"
        color="neutral"
        variant="outline"
        size="sm"
        icon="i-lucide-x"
        class="justify-center"
        @click="emit('cancel')"
      >
        Cancel
      </UButton>

      <UButton
        type="submit"
        color="primary"
        variant="solid"
        size="sm"
        icon="i-lucide-save"
        class="justify-center"
        :loading="isSaving"
        :disabled="false === validForm || isSaving"
      >
        Save
      </UButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useDirtyState } from '~/composables/useDirtyState';
import { request, notification, stringToRegex, parse_api_response } from '~/utils';
import type { GenericError, GenericResponse, GuidProvider } from '~/types';

type SelectItem = {
  label: string;
  value: string;
};

type GuidFormData = {
  name: string;
  type: string;
  description: string;
  validator: {
    pattern: string;
    example: string;
    tests: {
      valid: Array<string>;
      invalid: Array<string>;
    };
  };
};

const emit = defineEmits<{
  (e: 'cancel' | 'saved'): void;
  (e: 'dirty-change', dirty: boolean): void;
}>();

const defaultData = (): GuidFormData => ({
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

const form = ref<GuidFormData>(defaultData());
const guids = ref<Array<GuidProvider>>([]);
const isSaving = ref<boolean>(false);
const dirtySource = computed(() => form.value);
const { isDirty, markClean } = useDirtyState(dirtySource);

const nestedCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const typeItems = computed<Array<SelectItem>>(() => [{ label: 'String', value: 'string' }]);

onMounted(async (): Promise<void> => {
  try {
    const response = await request('/system/guids');
    const data = await parse_api_response<Array<GuidProvider>>(response);

    if ('error' in data) {
      notification('error', 'Error', data.error.message);
      return;
    }

    guids.value = data;
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`, 5000);
  }

  markClean();
});

const addNewGuid = async (): Promise<void> => {
  if (!validForm.value) {
    notification('error', 'Error', 'Invalid form data.', 5000);
    return;
  }

  const data = form.value;

  data.name = data.name.trim();

  if (data.name.toLowerCase() !== data.name) {
    notification('error', 'Error', 'GUID name must be lowercase.', 5000);
    return;
  }

  if (false === stringToRegex('/^[a-z0-9_]+$/').test(data.name)) {
    notification(
      'error',
      'Error',
      'GUID name must be in ASCII, rules are [lower case, a-z, 0-9, no space] starts with guid_',
      5000,
    );
    return;
  }

  if (data.name.includes(' ')) {
    notification('error', 'Error', 'GUID name must not contain spaces.', 5000);
    return;
  }

  data.type = data.type.trim().toLowerCase();
  if (!['string'].includes(data.type)) {
    notification('error', 'Error', 'Invalid GUID type.', 5000);
    return;
  }

  try {
    for (const g of guids.value) {
      if (g.guid === data.name) {
        notification('error', 'Error', `GUID with name '${data.name}' already exists.`, 5000);
        return;
      }
    }
  } catch (e: unknown) {
    const error = e as Error;
    notification('error', 'Error', `${error}`, 5000);
    return;
  }

  try {
    const validator = stringToRegex(data.validator.pattern);

    for (let i = 0; i < data.validator.tests.valid.length; i++) {
      const validValue = data.validator.tests.valid[i];
      if (!validValue || !validator.test(validValue)) {
        notification(
          'error',
          'Error',
          `Correct value '${i}' '${validValue}' did not match '${data.validator.pattern}'.`,
          5000,
        );
        return;
      }
      if (!validator.test(validValue + '/1')) {
        notification(
          'error',
          'Error',
          `Correct value '${i}' with relative info '${validValue + '/1'}' did not match '${data.validator.pattern}'.`,
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
          'Error',
          `Incorrect value '${i}' '${invalidValue}' matched '${data.validator.pattern}'.`,
          5000,
        );
        return;
      }
    }
  } catch {
    notification('error', 'Error', 'Invalid regex pattern.', 5000);
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
      const errorJson = json as GenericError;
      notification('error', 'Error', `${errorJson.error.code}: ${errorJson.error.message}`, 5000);
      return;
    }

    if (!response.ok) {
      notification('error', 'Error', `Request failed (${response.status})`, 5000);
      return;
    }

    notification('success', 'Success', 'Successfully added new GUID.', 5000);
    form.value = defaultData();
    markClean();
    emit('dirty-change', false);
    emit('saved');
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unexpected error';
    notification('error', 'Error', `Request error. ${message}`, 5000);
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
</script>
