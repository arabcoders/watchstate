<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-map"></i></span>
          Add Custom GUID
        </span>
        <div class="is-hidden-mobile">
          <span class="subtitle"></span>
        </div>
      </div>
      <div class="column is-12">
        <form id="page_form" @submit.prevent="addNewGuid">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-unselectable is-justify-center">Add Custom GUID</p>
            </header>

            <div class="card-content">

              <div class="field">
                <label class="label is-unselectable" for="form_guid_name">Name</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_guid_name" type="text" v-model="form.name" placeholder="guid_foobar">
                  <div class="icon is-small is-left"><i class="fas fa-passport"></i></div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>The internal GUID reference name. The name must starts with <code>guid</code>, followed by
                    <code>_</code>, <code>lower case [a-z]</code>, <code>0-9</code>, <code>no space</code>.
                    For example, <code>guid_imdb</code>.
                  </span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable" for="form_description">Description</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_description" type="text" v-model="form.description"
                         placeholder="This GUID is based on ... db reference">
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
                <label class="label is-unselectable" for="form_validation_pattern">Regex validation pattern</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_validation_pattern" type="text" v-model="form.validator.pattern"
                         placeholder="/^[0-9\\/]+$/i">
                  <div class="icon is-small is-left"><i class="fas fa-check"></i></div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>
                    A Valid regular expression to check the value GUID value. To test your patterns, you can use this
                    website
                    <NuxtLink target="_blank" to="https://regex101.com/#php73" v-text="'regex101.com'"/>
                    .
                  </span>
                </p>
              </div>
              <div class="field">
                <label class="label is-unselectable" for="form_validation_example">Value example</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_validation_example" type="text" v-model="form.validator.example"
                         placeholder="(number)">
                  <div class="icon is-small is-left"><i class="fas fa-ear-deaf"></i></div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>The example to show when invalid value was checked. For example, <code>(number)</code>. For
                    information purposes only.</span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable">
                  Correct values.
                  <NuxtLink class="has-text-primary" @click="form.validator.tests.valid.push('')" v-text="'Add'"/>
                </label>
                <div class="columns is-multiline">
                  <template v-for="(_, index) in form.validator.tests.valid" :key="`valid-${index}`">
                    <div class="column is-11">
                      <div class="control has-icons-left">
                        <input class="input" type="text" :id="`valid-${index}`"
                               v-model="form.validator.tests.valid[index]">
                        <div class="icon is-small is-left"><i class="fas fa-check"></i></div>
                      </div>
                    </div>
                    <div class="column">
                      <button class="button is-danger" type="button"
                              @click="form.validator.tests.valid.splice(index, 1)"
                              :disabled="index < 1 || form.validator.tests.valid < 1">
                        <span class="icon"><i class="fas fa-trash"></i></span>
                      </button>
                    </div>
                  </template>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>
                    The values added here must match the pattern defined above. Example: <code>123</code>.
                    Additionally, the pattern also must support <code>/</code> being part of the value. as we used it
                    for relative GUIDs. The <code>(number)/1/1</code> refers to a relative GUID.
                    There must be a minimum of 1 correct value.
                  </span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable">
                  Incorrect values.
                  <NuxtLink class="has-text-danger" @click="form.validator.tests.invalid.push('')" v-text="'Add'"/>
                </label>
                <div class="columns is-multiline">
                  <template v-for="(_, index) in form.validator.tests.invalid" :key="`valid-${index}`">
                    <div class="column is-11">
                      <div class="control has-icons-left">
                        <input class="input" type="text" :id="`invalid-${index}`"
                               v-model="form.validator.tests.invalid[index]">
                        <div class="icon is-small is-left"><i class="fas fa-check"></i></div>
                      </div>
                    </div>
                    <div class="column">
                      <button class="button is-danger" type="button"
                              @click="form.validator.tests.invalid.splice(index, 1)"
                              :disabled="index < 1 || form.validator.tests.invalid < 1">
                        <span class="icon"><i class="fas fa-trash"></i></span>
                      </button>
                    </div>
                  </template>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>GUID values with should not match the pattern defined above. Example: <code>abc</code>. There
                    must be a minimum of 1 incorrect value.</span>
                </p>
              </div>
            </div>

            <div class="card-footer">
              <div class="card-footer-item">
                <button class="button is-fullwidth is-primary" type="submit" :disabled="false === validForm || isSaving"
                        :class="{'is-loading':isSaving}">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-save"></i></span>
                    <span>Save</span>
                  </span>
                </button>
              </div>
              <div class="card-footer-item">
                <button class="button is-fullwidth is-danger" type="button" @click="navigateTo('/custom')">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-cancel"></i></span>
                    <span>Cancel</span>
                  </span>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import {notification, stringToRegex} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message'

useHead({title: 'Add Custom GUID'})

const empty_form = {
  name: '',
  type: 'string',
  description: '',
  validator: {
    pattern: '/^[0-9\\\\/]+$/i',
    example: '(number)',
    tests: {
      valid: ['1234567', '1234567/1/1'],
      invalid: ['1234567a', 'a1234567']
    }
  }
}
const show_page_tips = useStorage('show_page_tips', true)
const form = ref(JSON.parse(JSON.stringify(empty_form)))
const guids = ref([])
const isSaving = ref(false)

onMounted(async () => {
  try {
    const response = await request('/system/guids')
    guids.value = await response.json()
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  }
})

const addNewGuid = async () => {
  if (!validForm.value) {
    notification('error', 'Error', 'Invalid form data.', 5000)
    return
  }

  let data = form.value

  data.name = data.name.trim();

  if (data.name.toLowerCase() !== data.name) {
    notification('error', 'Error', `GUID name must be lowercase.`, 5000)
    return
  }

  if (false === stringToRegex('/^[a-z0-9_]+$/').test(data.name)) {
    notification('error', 'Error', `GUID name must be in ASCII, rules are [lower case, a-z, 0-9, no space] starts with guid_`, 5000)
    return
  }

  if (data.name.includes(' ')) {
    notification('error', 'Error', `GUID name must not contain spaces.`, 5000)
    return
  }

  if (!data.name.startsWith('guid_')) {
    notification('error', 'Error', `GUID name must start with 'guid_'.`, 5000)
    return
  }


  data.type = data.type.trim().toLowerCase();
  if (!['string'].includes(data.type)) {
    notification('error', 'Error', `Invalid GUID type.`, 5000)
    return
  }

  try {
    toRaw(guids.value).forEach(g => {
      const name = data.name.split('_')[1]
      if (g.guid === name) {
        throw new Error(`GUID with name '${data.name}' already exists.`)
      }
    })
  } catch (e) {
    notification('error', 'Error', `${e}`, 5000)
    return false
  }

  try {
    const validator = stringToRegex(data.validator.pattern);

    for (let i = 0; i < data.validator.tests.valid.length; i++) {
      if (!validator.test(data.validator.tests.valid[i])) {
        notification('error', 'Error', `Correct value '${i}' '${data.validator.tests.valid[i]}' did not match '${data.validator.pattern}'.`, 5000)
        return false
      }
      if (!validator.test(data.validator.tests.valid[i] + '/1')) {
        notification('error', 'Error', `Correct value '${i}' with relative info '${data.validator.tests.valid[i] + '/1'}' did not match '${data.validator.pattern}'.`, 5000)
        return false
      }
    }

    for (let i = 0; i < data.validator.tests.invalid.length; i++) {
      const invalid = data.validator.tests.invalid[i]
      if (validator.test(data.validator.tests.invalid[i])) {
        notification('error', 'Error', `Incorrect value '${i}' '${invalid}' matched '${data.validator.pattern}'.`, 5000)
        return false
      }
    }

  } catch (e) {
    notification('error', 'Error', `Invalid regex pattern.`, 5000)
    return false
  }

  isSaving.value = true

  try {
    const response = await request('/system/guids/custom', {
      method: 'PUT',
      body: JSON.stringify(data)
    })

    const json = await parse_api_response(response)

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }

    notification('success', 'Success', 'Successfully added new GUID.', 5000)
    await navigateTo('/custom')
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  } finally {
    isSaving.value = false
  }
}

const validForm = computed(() => {
  const data = form.value

  if (!data.name || !data.type || !data.description) {
    return false
  }

  if (!data.validator.pattern || !data.validator.example) {
    return false
  }

  if (!data.validator.tests.valid.length || !data.validator.tests.invalid.length) {
    return false
  }

  return !(!data.validator.tests.valid[0] || !data.validator.tests.invalid[0]);
})
</script>
