<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span id="env_page_title" class="title is-4">Environment Variables</span>

      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary is-light" v-tooltip="'Add New Variable'" @click="toggleForm = !toggleForm">
              <span class="icon">
                <i class="fas fa-add"></i>
              </span>
            </button>
          </p>
          <p class="control">
            <button class="button is-primary" @click="loadContent">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">
          These environment variables are loaded from the <code>{{ file }}</code> file.
        </span>
      </div>
    </div>

    <div class="column is-12" v-if="toggleForm">
      <form id="env_add_form" @submit.prevent="addVariable">
        <div class="field is-grouped">
          <div class="control has-icons-left">
            <div class="select is-fullwidth">
              <select v-model="form_key" id="form_key" @change="keyChanged">
                <option value="" disabled>Select Key</option>
                <option v-for="env in envs" :key="env.key" :value="env.key">
                  {{ env.key }}
                </option>
              </select>
            </div>
            <div class="icon is-small is-left">
              <i class="fas fa-key"></i>
            </div>
          </div>
          <div class="control is-expanded has-icons-left">
            <input class="input" id="form_value" type="text" placeholder="Value" v-model="form_value">
            <div class="icon is-small is-left">
              <i class="fas fa-font"></i>
            </div>
            <p class="help" v-html="getHelp(form_key)"></p>
          </div>

          <div class="control">
            <button class="button is-danger" type="button"
                    v-tooltip="'Cancel'" @click="form_key=null; form_value=null; toggleForm=false">
              <span class="icon"><i class="fas fa-cancel"></i></span>
            </button>
          </div>
          <div class="control">
            <button class="button is-primary" type="submit" :disabled="!form_key || !form_value">
              <span class="icon"><i class="fas fa-save"></i></span>
            </button>
          </div>
        </div>
      </form>
    </div>

    <div class="column is-12" v-if="envs">
      <div class="table-container">
        <table class="table is-fullwidth is-bordered is-striped is-hoverable has-text-centered">
          <thead>
          <tr>
            <th style="width: 25%;">Key</th>
            <th>Value</th>
            <th style="width: 10%;">Actions</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="env in filteredRows(envs)" :key="env.key">
            <td class="has-text-left">
              {{ env.key }}
              <div class="is-pulled-right" v-if="env.mask">
                <span class="icon is-small has-tooltip" v-tooltip="'The value of this key is masked.'">
                  <i class="fas fa-lock"></i>
                </span>
              </div>
            </td>
            <td class="has-text-left" :class="{ 'is-masked': env.mask, 'is-unselectable': env.mask }">
              {{ env.value }}
            </td>
            <td>
              <div class="field is-grouped" style="justify-content: center">
                <div class="control">
                  <button class="button is-small is-primary" @click="editEnv(env)">
                    <span class="icon">
                      <i class="fas fa-edit"></i>
                    </span>
                  </button>
                </div>
                <div class="control" v-if="copyAPI">
                  <button class="button is-small is-warning" @click="copyValue(env)">
                    <span class="icon">
                      <i class="fas fa-copy"></i>
                    </span>
                  </button>
                </div>
                <div class="control">
                  <button class="button is-small is-danger" @click="deleteEnv(env)">
                    <span class="icon">
                      <i class="fas fa-trash"></i>
                    </span>
                  </button>
                </div>
              </div>
            </td>
          </tr>
          </tbody>
        </table>
        <div class="is-hidden-mobile help">
          Some variables values are masked for security reasons. If you need to see the value, click on edit.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import {awaitElement, notification} from '~/utils/index.js'

useHead({title: 'Environment Variables'})

const envs = ref([])
const toggleForm = ref(false)
const form_key = ref('')
const form_value = ref(null)
const file = ref('.env')
const copyAPI = navigator.clipboard

const loadContent = async () => {
  envs.value = []
  const response = await request('/system/env')
  const json = await response.json();
  envs.value = json.data
  if (json.file) {
    file.value = json.file
  }
}

onMounted(() => loadContent())

const deleteEnv = async (env) => {
  if (!confirm(`Are you sure you want to delete the environment variable ${env.key}?`)) {
    return
  }

  const response = await request(`/system/env/${env.key}`, {method: 'DELETE'})

  if (response.ok) {
    envs.value = envs.value.filter(i => i.key !== env.key)
  }
}

const addVariable = async () => {
  const key = form_key.value.toUpperCase()

  if (!key.startsWith('WS_')) {
    notification('error', 'Error', 'Key must start with WS_',)
    return
  }

  const response = await request(`/system/env/${key}`, {
    method: 'POST',
    body: JSON.stringify({value: form_value.value})
  })

  if (response.ok) {
    await loadContent()
    form_key.value = null
    form_value.value = null
    toggleForm.value = false
  }
}

const editEnv = (env) => {
  form_key.value = env.key
  form_value.value = env.value
  toggleForm.value = true
}

const copyValue = (env) => navigator.clipboard.writeText(env.value)

watch(toggleForm, (value) => {
  if (!value) {
    form_key.value = null
    form_value.value = null
  } else {
    awaitElement('#env_page_title', (_, el) => el.scrollIntoView({
      behavior: 'smooth',
      block: 'start',
      inline: 'nearest'
    }))
  }
});

const keyChanged = () => {
  if (!form_key.value) {
    return
  }

  let data = envs.value.filter(i => i.key === form_key.value)
  form_value.value = (data.length > 0) ? data[0].value : ''
}

const getHelp = (key) => {
  if (!key) {
    return ''
  }

  let data = envs.value.filter(i => i.key === key)
  if (data.length === 0) {
    return ''
  }

  let text = `${data[0].description}`;

  if (data[0].type) {
    text += ` Expected value: <code>${('bool' === data[0].type) ? 'bool, 0, 1' : data[0].type}</code>`
  }

  return text;
}
const filteredRows = (rows) => rows.filter(i => i.value !== undefined);
</script>
