<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-map"></i></span>
          Add Custom GUID
        </span>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This custom GUID allows you to extend <code>WatchState</code> GUID parser with your
            custom GUIDs. Using this feature, You are able to use more metadata databases for
            references between the backends.
          </span>
        </div>
      </div>
      <div class="column is-12">
        <form id="page_form" @submit.prevent="addNewGuid">
          <div class="field">
            <label class="label is-unselectable" for="form_guid_name">Name</label>
            <div class="control has-icons-left">
              <input
                class="input"
                id="form_guid_name"
                type="text"
                v-model="form.name"
                placeholder="foobar"
              />
              <div class="icon is-small is-left"><i class="fas fa-passport"></i></div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span
                >The internal GUID reference name. The rules are <code>lower case [a-z]</code>,
                <code>0-9</code>, <code>no space</code>. For example, <code>guid_imdb</code>. The
                guid name will be automatically prefixed with <code>guid_</code>.
              </span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable" for="form_description">Description</label>
            <div class="control has-icons-left">
              <input
                class="input"
                id="form_description"
                type="text"
                v-model="form.description"
                placeholder="This GUID is based on ... db reference"
              />
              <div class="icon is-small is-left"><i class="fas fa-envelope-open-text"></i></div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>GUID description, For information purposes only.</span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable" for="form_select_type">Type</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select id="form_select_type" v-model="form.type">
                  <option value="" disabled>Select Type</option>
                  <option value="string">String</option>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-cog"></i>
              </div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>We currently only support <code>string</code> type.</span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable" for="form_validation_pattern"
              >Regex validation pattern</label
            >
            <div class="control has-icons-left">
              <input
                class="input"
                id="form_validation_pattern"
                type="text"
                v-model="form.validator.pattern"
                placeholder="/^[0-9\\/]+$/i"
              />
              <div class="icon is-small is-left"><i class="fas fa-check"></i></div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>
                A Valid regular expression to check the value GUID value. To test your patterns, you
                can use this website
                <NuxtLink target="_blank" to="https://regex101.com/#php73">regex101.com</NuxtLink>
                .
              </span>
            </p>
          </div>
          <div class="field">
            <label class="label is-unselectable" for="form_validation_example">Value example</label>
            <div class="control has-icons-left">
              <input
                class="input"
                id="form_validation_example"
                type="text"
                v-model="form.validator.example"
                placeholder="(number)"
              />
              <div class="icon is-small is-left"><i class="fas fa-ear-deaf"></i></div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span
                >The example to show when invalid value was checked. For example,
                <code>(number)</code>. For information purposes only.</span
              >
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable">
              Correct values.
              <NuxtLink class="has-text-primary" @click="form.validator.tests.valid.push('')"
                >Add</NuxtLink
              >
            </label>
            <div class="columns is-multiline">
              <template v-for="(_, index) in form.validator.tests.valid" :key="`valid-${index}`">
                <div class="column is-11">
                  <div class="control has-icons-left">
                    <input
                      class="input"
                      type="text"
                      :id="`valid-${index}`"
                      v-model="form.validator.tests.valid[index]"
                    />
                    <div class="icon is-small is-left"><i class="fas fa-check"></i></div>
                  </div>
                </div>
                <div class="column">
                  <button
                    class="button is-danger"
                    type="button"
                    @click="form.validator.tests.valid.splice(index, 1)"
                    :disabled="index < 1 || form.validator.tests.valid.length < 1"
                  >
                    <span class="icon"><i class="fas fa-trash"></i></span>
                  </button>
                </div>
              </template>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>
                The values added here must match the pattern defined above. Example:
                <code>123</code>. Additionally, the pattern also must support <code>/</code> being
                part of the value. as we used it for relative GUIDs. The
                <code>(number)/1/1</code> refers to a relative GUID. There must be a minimum of 1
                correct value.
              </span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable">
              Incorrect values.
              <NuxtLink class="has-text-danger" @click="form.validator.tests.invalid.push('')"
                >Add</NuxtLink
              >
            </label>
            <div class="columns is-multiline">
              <template v-for="(_, index) in form.validator.tests.invalid" :key="`valid-${index}`">
                <div class="column is-11">
                  <div class="control has-icons-left">
                    <input
                      class="input"
                      type="text"
                      :id="`invalid-${index}`"
                      v-model="form.validator.tests.invalid[index]"
                    />
                    <div class="icon is-small is-left"><i class="fas fa-check"></i></div>
                  </div>
                </div>
                <div class="column">
                  <button
                    class="button is-danger"
                    type="button"
                    @click="form.validator.tests.invalid.splice(index, 1)"
                    :disabled="index < 1 || form.validator.tests.invalid.length < 1"
                  >
                    <span class="icon"><i class="fas fa-trash"></i></span>
                  </button>
                </div>
              </template>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span
                >GUID values with should not match the pattern defined above. Example:
                <code>abc</code>. There must be a minimum of 1 incorrect value.</span
              >
            </p>
          </div>

          <div class="field is-grouped">
            <div class="control is-expanded">
              <button
                class="button is-fullwidth is-primary"
                type="submit"
                :disabled="false === validForm || isSaving"
                :class="{ 'is-loading': isSaving }"
              >
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-save"></i></span>
                  <span>Save</span>
                </span>
              </button>
            </div>
            <div class="control is-expanded">
              <button
                class="button is-fullwidth is-danger"
                type="button"
                @click="navigateTo('/custom')"
              >
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-cancel"></i></span>
                  <span>Cancel</span>
                </span>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useHead, navigateTo } from '#app';
import { request, notification, stringToRegex, parse_api_response } from '~/utils';
import type { GenericError, GenericResponse, GuidProvider } from '~/types';
import '~/assets/css/bulma-switch.css';

useHead({ title: 'Add Custom GUID' });

const defaultData = () =>
  ({
    name: '',
    type: 'string',
    description: '',
    validator: {
      pattern: '/^[0-9\\\\/]+$/i',
      example: '(number)',
      tests: {
        valid: ['1234567', '1234567/1/1'],
        invalid: ['1234567a', 'a1234567'],
      },
    },
  }) as {
    /** GUID name */
    name: string;
    /** GUID type */
    type: string;
    /** GUID description */
    description: string;
    /** Validation configuration */
    validator: {
      /** Regex pattern */
      pattern: string;
      /** Example value */
      example: string;
      /** Test cases */
      tests: {
        /** Valid test values */
        valid: Array<string>;
        /** Invalid test values */
        invalid: Array<string>;
      };
    };
  };

const form = ref(defaultData());
const guids = ref<Array<GuidProvider>>([]);
const isSaving = ref<boolean>(false);

onMounted(async (): Promise<void> => {
  try {
    const response = await request('/system/guids');
    const data = await parse_api_response<Array<GuidProvider>>(response);

    if ('error' in data) {
      notification('error', 'Error', data.error.message);
      return;
    }

    guids.value = data;
  } catch (e) {
    const error = e as Error;
    notification('error', 'Error', `Request error. ${error.message}`, 5000);
  }
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
  } catch (e) {
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
    await navigateTo('/custom');
  } catch (error) {
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
</script>
