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
        <form id="page_form" @submit.prevent="addIgnoreRule">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-unselectable is-justify-center">Add Custom GUID</p>
            </header>

            <div class="card-content">

              <div class="field">
                <label class="label is-unselectable" for="form_ignore_id">Name</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_guid_name" type="text" v-model="form.name" placeholder="guid_foobar">
                  <div class="icon is-small is-left"><i class="fas fa-passport"></i></div>
                </div>
                <p class="help">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>All GUIDs names must start with <code>guid_</code>. For example,
                      <code>guid_foobar</code>. You cannot use the same name as an existing GUID.
                    </span>
                  </span>
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
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>We currently only support <code>string</code> type.</span>
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
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>GUID description, For information purposes only.</span>
                  </span>
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
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>
                      A Valid regular expression to check the value GUID value. To test your patterns, you can use this
                      website
                      <NuxtLink target="_blank" to="https://regex101.com/#php73" v-text="'regex101.com'"/>
                      .
                    </span>
                  </span>
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
                        <input class="input" type="text" v-model="form.validator.tests.valid[index]">
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
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>
                      The values added here must match the pattern defined above. Example: <code>123</code>.
                      Additionally, the pattern also must support <code>/</code> being part of the value. as we used it
                      for relative GUIDs. There must be a minimum of 1 correct value.
                    </span>
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
                        <input class="input" type="text" v-model="form.validator.tests.invalid[index]">
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
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>GUID values with should not match the pattern defined above. Example: <code>abc</code>. There
                      must be a minimum of 1 incorrect value.</span>
                  </span>
                </p>
              </div>
            </div>

            <div class="card-footer">
              <div class="card-footer-item">
                <button class="button is-fullwidth is-primary" type="submit" :disabled="false === checkForm">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-save"></i></span>
                    <span>Save</span>
                  </span>
                </button>
              </div>
              <div class="card-footer-item">
                <button class="button is-fullwidth is-danger" type="button" @click="cancelForm">
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
  type: '',
  description: '',
  validator: {pattern: '', example: '', tests: {valid: [''], invalid: ['']}}
}
const show_page_tips = useStorage('show_page_tips', true)

const items = ref([])
const form = ref(JSON.parse(JSON.stringify(empty_form)))
const guids = ref([])

onMounted(async () => {
  try {
    const response = await request('/system/guids')
    guids.value = await response.json()
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  }
})

const addIgnoreRule = async () => {
  const val = guids.value.find(g => g.guid === form.value.db)
  if (val && val?.validator && val.validator.pattern) {
    if (!stringToRegex(val.validator.pattern).test(form.value.id)) {
      notification('error', 'Error', `Invalid GUID value, must match the pattern: '${val.validator.pattern}'. Example ${val.validator.example}`, 5000)
      return
    }
  }

  try {
    const response = await request(`/ignore`, {
      method: 'POST',
      body: JSON.stringify(form.value)
    })

    const json = await response.json()

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }

    items.value.push(json)

    notification('success', 'Success', 'Successfully added new ignore rule.', 5000)
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  }
}

const checkForm = computed(() => {
  const {id, type, backend, db} = form.value
  return '' !== id && '' !== type && '' !== backend && '' !== db
})
</script>
