<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span id="env_page_title" class="title is-4">Environment Variables</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" v-tooltip="'Add New Variable'" @click="toggleForm = !toggleForm">
              <span class="icon">
                <i class="fas fa-add"></i>
              </span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click="loadContent">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">
          This page allow you alter the environment variables that are used to configure the application.
        </span>
      </div>
    </div>

    <div class="column is-12" v-if="toggleForm">
      <form id="env_add_form" @submit.prevent="addVariable">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-unselectable is-justify-center">Manage Environment Variable</p>
          </header>
          <div class="card-content">
            <div class="field">
              <label class="label is-unselectable" for="form_key">Environment key</label>
              <div class="control has-icons-left">
                <div class="select is-fullwidth">
                  <select v-model="form_key" id="form_key" @change="keyChanged">
                    <option value="" disabled>Select Key</option>
                    <option v-for="env in envs" :key="env.key" :value="env.key">
                      {{ env.key }}
                    </option>
                  </select>
                </div>
                <div class="icon is-left">
                  <i class="fas fa-key"></i>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="label is-unselectable" for="form_value">Environment value</label>
              <div class="control has-icons-left">
                <template v-if="'bool' === form_type">
                  <input id="form_value" type="checkbox" class="switch is-success"
                         :checked="fixBool(form_value)" @change="form_value = !fixBool(form_value)">
                  <label for="form_value">
                    <template v-if="fixBool(form_value)">On (True)</template>
                    <template v-else>Off (False)</template>
                  </label>
                </template>
                <template v-else-if=" 'int' === form_type ">
                  <input class="input" id="form_value" type="number" placeholder="Value" v-model="form_value"
                         pattern="[0-9]*" inputmode="numeric">
                  <div class="icon is-small is-left">
                    <i class="fas fa-font"></i>
                  </div>
                </template>
                <template v-else>
                  <input class="input" id="form_value" type="text" placeholder="Value" v-model="form_value">
                  <div class="icon is-small is-left"><i class="fas fa-font"></i></div>
                </template>
                <p class="help" v-html="getHelp(form_key)"></p>
              </div>
            </div>
          </div>
          <div class="card-footer">
            <div class="card-footer-item">
              <button class="button is-fullwidth is-primary" type="submit" :disabled="!form_key || '' === form_value">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-save"></i></span>
                  <span>Save</span>
                </span>
              </button>
            </div>
            <div class="card-footer-item">
              <button class="button is-fullwidth is-danger" type="button"
                      @click="form_key=null; form_value=null; toggleForm=false">
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

    <div class="column is-12" v-if="envs">
      <div class="columns is-multiline">
        <div class="column is-4" v-for="env in filteredRows(envs)" :key="env.key">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-unselectable">
                <span class="has-tooltip is-clickable" v-tooltip="env.description">{{ env.key }}</span>
              </p>
              <span class="card-header-icon" v-if="env.mask" @click="env.mask = false" v-tooltip="'Unmask the value'">
                <span class="icon"><i class="fas fa-unlock"></i></span>
              </span>
            </header>
            <div class="card-content">
              <div class="content">
                <p v-if="'bool' === env.type">
                  <span class="icon-text">
                    <span class="icon">
                      <i class="fas fa-toggle-on has-text-primary" v-if="fixBool(env.value)"></i>
                      <i class="fas fa-toggle-off" v-else></i>
                    </span>
                    <span>{{ fixBool(env.value) ? 'On (True)' : 'Off (False)' }}</span>
                  </span>
                </p>
                <p v-else class="is-text-overflow is-clickable is-unselectable"
                   :class="{ 'is-masked': env.mask, 'is-unselectable': env.mask }"
                   @click="(e) => e.target.classList.toggle('is-text-overflow')">
                  {{ env.value }}</p>
              </div>
            </div>
            <footer class="card-footer">
              <div class="card-footer-item">
                <button class="button is-primary is-fullwidth" @click="editEnv(env)">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-edit"></i></span>
                    <span>Edit</span>
                  </span>
                </button>
              </div>
              <div class="card-footer-item">
                <button class="button is-fullwidth is-warning" @click="copyText(env.value)">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-copy"></i></span>
                    <span>Copy</span>
                  </span>
                </button>
              </div>
              <div class="card-footer-item">
                <button class="button is-fullwidth is-danger" @click="deleteEnv(env)">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-trash"></i></span>
                    <span>Delete</span>
                  </span>
                </button>
              </div>
            </footer>
          </div>
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="envs && show_page_tips">
      <Message title="Tips" message_class="has-background-info-90 has-text-dark">
        <button class="delete" @click="show_page_tips=false"></button>
        <div class="content">
          <ul>
            <li>
              Some variables values are masked, to unmask them click on icon <i class="fa fa-unlock"></i>.
            </li>
            <li>
              Some values are too large to fit into the view, clicking on the value will show the full value.
            </li>
            <li>
              These environment variables are loaded from the <code>{{ file }}</code> file.
            </li>
            <li>
              To add a new variable click on the <i class="fa fa-add"></i> button.
            </li>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import {awaitElement, copyText, notification} from '~/utils/index.js'
import {useStorage} from '@vueuse/core'

useHead({title: 'Environment Variables'})

const envs = ref([])
const toggleForm = ref(false)
const form_key = ref('')
const form_value = ref()
const form_type = ref()
const show_page_tips = useStorage('show_page_tips', true)

const file = ref('.env')

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
  if (!confirm(`Are you sure you want to delete the environment variable '${env.key}'?`)) {
    return
  }

  const response = await request(`/system/env/${env.key}`, {method: 'DELETE'})

  if (response.ok) {
    envs.value = envs.value.filter(i => i.key !== env.key)
    notification('success', 'Success', `Environment variable ${env.key} successfully deleted.`, 5000)
  }
}

const addVariable = async () => {
  const key = form_key.value.toUpperCase()

  if (!key.startsWith('WS_')) {
    notification('error', 'Error', 'Key must start with WS_',)
    return
  }

  // -- check if value is empty or the same
  if ('' === form_value.value) {
    notification('error', 'Error', 'Value cannot be empty.', 5000)
    return
  }

  const data = envs.value.filter(i => i.key === key)
  if (data.length > 0 && data[0].value === form_value.value) {
    return cancelForm();
  }

  const response = await request(`/system/env/${key}`, {
    method: 'POST',
    body: JSON.stringify({value: form_value.value})
  })

  if (304 === response.status) {
    return cancelForm();
  }

  const json = await response.json()

  if (!response.ok) {
    notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
    return
  }

  envs.value[envs.value.findIndex(i => i.key === key)] = json

  notification('success', 'Success', 'Environment variable successfully updated.', 5000)
  return cancelForm();
}

const editEnv = (env) => {
  form_key.value = env.key
  form_value.value = env.value
  form_type.value = env.type
  toggleForm.value = true
}

const cancelForm = () => {
  form_key.value = ''
  form_value.value = null
  form_type.value = null
  toggleForm.value = false
}

watch(toggleForm, (value) => {
  if (!value) {
    form_key.value = ''
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
  form_type.value = (data.length > 0) ? data[0].type : 'string'
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
    text += ` Expects: <code>${data[0].type}</code>`
  }

  return (data[0].deprecated) ? `<strong><code class="is-strike-through"">Deprecated</code></strong> - ${text}` : text
}

const fixBool = (value) => [true, 'true', '1'].includes(value)

const filteredRows = (rows) => rows.filter(i => i.value !== undefined);
</script>
