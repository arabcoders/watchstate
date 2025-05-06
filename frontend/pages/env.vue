<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-cogs"></i></span>
          Environment variables
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="toggleFilter || query">
              <input type="search" v-model.lazy="query" class="input" id="filter"
                placeholder="Filter displayed content">
              <span class="icon is-left"><i class="fas fa-filter" /></span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter = !toggleFilter">
                <span class="icon"><i class="fas fa-filter" /></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Add new variable'" @click="toggleForm = !toggleForm"
                :disabled="isLoading">
                <span class="icon">
                  <i class="fas fa-add"></i>
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading || toggleForm"
                :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"></i></span>
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

      <div class="column is-12" v-if="!toggleForm && filteredRows.length < 1">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
          icon="fas fa-spinner fa-spin" message="Loading data. Please wait..." />
        <Message v-else message_class="has-background-warning-90 has-text-dark"
          :title="query ? 'No results' : 'Information'" icon="fas fa-info-circle">
          <p v-if="query">
            No environment variables found matching <strong>{{ query }}</strong>. Please try a different filter.
          </p>
          <p v-else>
            No environment variables configured yet. Click on the
            <i @click="toggleForm = true" class="is-clickable fa fa-add"></i> button to add a new variable.
          </p>
        </Message>
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
                      <option v-for="item in items" :key="`opt-${item.key}`" :value="item.key">
                        {{ item.key }}
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
                    <input id="form_value" type="checkbox" class="switch is-success" :checked="fixBool(form_value)"
                      @change="form_value = !fixBool(form_value)">
                    <label for="form_value">
                      <template v-if="fixBool(form_value)">On (True)</template>
                      <template v-else>Off (False)</template>
                    </label>
                  </template>
                  <template v-else-if="'int' === form_type">
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
                  <div>
                    <p class="help" v-html="getHelp(form_key)"></p>
                  </div>
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
      <div v-else class="column is-12" v-if="filteredRows">
        <div class="columns is-multiline">
          <div class="column" v-for="item in filteredRows" :key="item.key"
            :class="{ 'is-4': !item?.danger, 'is-12': item.danger }">
            <div class="card" :class="{ 'is-danger': item?.danger }">
              <header class="card-header">
                <p class="card-header-title is-unselectable">
                  <template v-if="item?.danger">
                    <span class="title is-5 ">
                      <span class="icon" v-tooltip="'This option is considered dangerous.'">
                        <i class="has-text-danger fas fa-exclamation-triangle"></i>&nbsp;
                      </span> {{ item.key }}
                    </span>
                  </template>
                  <template v-else>
                    <span class="has-tooltip is-clickable" v-tooltip="item.description">
                      {{ item.key }}
                    </span>
                  </template>
                </p>
                <span class="card-header-icon" v-if="item.mask" @click="item.mask = false"
                  v-tooltip="'Unmask the value'">
                  <span class="icon"><i class="fas fa-unlock"></i></span>
                </span>
              </header>
              <div class="card-content">
                <div class="content">
                  <p v-if="'bool' === item.type">
                    <span class="icon-text">
                      <span class="icon">
                        <i class="fas fa-toggle-on has-text-primary" v-if="fixBool(item.value)"></i>
                        <i class="fas fa-toggle-off" v-else></i>
                      </span>
                      <span>{{ fixBool(item.value) ? 'On (True)' : 'Off (False)' }}</span>
                    </span>
                  </p>
                  <p v-else class="is-text-overflow is-clickable is-unselectable"
                    :class="{ 'is-masked': item.mask, 'is-unselectable': item.mask }"
                    @click="(e) => e.target.classList.toggle('is-text-overflow')">
                    {{ item.value }}</p>

                  <p v-if="item?.danger" class="title is-5 has-text-danger">
                    {{ item.description }}
                  </p>
                </div>
              </div>
              <footer class="card-footer">
                <div class="card-footer-item">
                  <button class="button is-primary is-fullwidth" @click="editEnv(item)">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-edit"></i></span>
                      <span>Edit</span>
                    </span>
                  </button>
                </div>
                <div class="card-footer-item">
                  <button class="button is-fullwidth is-warning" @click="copyText(item.value)">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-copy"></i></span>
                      <span>Copy</span>
                    </span>
                  </button>
                </div>
                <div class="card-footer-item">
                  <button class="button is-fullwidth is-danger" @click="deleteEnv(item)">
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

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
          @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>Some variables values are masked, to unmask them click on icon <i class="fa fa-unlock"></i>.</li>
            <li>Some values are too large to fit into the view, clicking on the value will show the full value.</li>
            <li>These values are loaded from the <code>{{ file }}</code> file.</li>
            <li>To add a new variable click on the <i class="fa fa-add"></i> button.</li>
            <li>Environment variables with <span class="has-text-danger">red borders</span> and <i
                class="fas fa-exclamation-triangle"></i> icon are considered
              dangerous. Please be careful when editing them.
            </li>
          </ul>
        </Message>
      </div>

    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import { awaitElement, copyText, notification } from '~/utils/index'
import { useStorage } from '@vueuse/core'
import Message from '~/components/Message'

const route = useRoute()

useHead({ title: 'Environment Variables' })

const items = ref([])
const toggleForm = ref(false)
const form_key = ref('')
const form_value = ref()
const form_type = ref()
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(true)
const file = ref('.env')
const query = ref(route.query.filter ?? '')
const toggleFilter = ref(false)
watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = ''
  }
});

const loadContent = async () => {
  const route = useRoute()
  try {
    isLoading.value = true
    items.value = []
    const response = await request('/system/env')
    const json = await response.json()
    items.value = json.data
    if (json.file) {
      file.value = json.file
    }
    if (route.query.edit) {
      let item = items.value.find(i => i.key === route.query.edit)
      if (item && route.query?.value && !item?.value) {
        item.value = route.query.value
      }
      editEnv(item)
    }
  } catch (e) {
    notification('error', 'Error', e.message, 5000)
  } finally {
    isLoading.value = false
  }
}

const deleteEnv = async (env) => {
  if (!confirm(`Delete '${env.key}'?`)) {
    return
  }

  try {
    const response = await request(`/system/env/${env.key}`, { method: 'DELETE' })

    if (200 !== response.status) {
      let json
      try {
        json = await response.json()
      } catch (e) {
        json = { error: { code: response.status, message: response.statusText } }
      }
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }

    items.value = items.value.filter(i => {
      const state = i.key !== env.key
      if (true === state) {
        delete i.value
      }
      return state;
    })
    notification('success', 'Success', `Environment variable '${env.key}' successfully deleted.`, 5000)
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
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

  try {
    const response = await request(`/system/env/${key}`, {
      method: 'POST',
      body: JSON.stringify({ value: form_value.value })
    })

    if (304 === response.status) {
      return cancelForm()
    }

    const json = await response.json()
    if (useRoute().name !== 'env') {
      return
    }

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }

    items.value[items.value.findIndex(i => i.key === key)] = json

    notification('success', 'Success', `Environment variable '${key}' successfully updated.`, 5000)
    return cancelForm()
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  }
}

const editEnv = env => {
  form_key.value = env.key
  form_value.value = env.value

  if (typeof env.value === 'undefined' && 'bool' === env.type) {
    form_value.value = false
  }

  form_type.value = env.type
  toggleForm.value = true
  if (!useRoute().query.edit) {
    useRouter().push({ 'path': '/env', query: { 'edit': env.key } })
  }
}

const cancelForm = async () => {
  const route = useRoute()
  form_key.value = ''
  form_value.value = null
  form_type.value = null
  toggleForm.value = false
  if (route.query?.callback) {
    await navigateTo({ path: route.query.callback })
    return
  }

  if (route.query?.edit || route.query?.value) {
    await useRouter().push({ path: '/env' })
  }
}

watch(toggleForm, async value => {
  if (!value) {
    await cancelForm()
  } else {
    awaitElement('#env_page_title', (_, el) => el.scrollIntoView({ behavior: 'smooth' }))
  }
})

const keyChanged = () => {
  if (!form_key.value) {
    return
  }

  let data = items.value.filter(i => i.key === form_key.value)
  form_value.value = (data.length > 0) ? data[0].value : ''
  form_type.value = (data.length > 0) ? data[0].type : 'string'
  nextTick(() => {
    if (typeof form_value.value === 'undefined' && 'bool' === form_type.value) {
      form_value.value = false
    }
  });
  useRouter().push({ 'path': '/env', query: { 'edit': form_key.value } })
}

const getHelp = key => {
  if (!key) {
    return ''
  }

  let data = items.value.filter(i => i.key === key)
  if (0 === data.length) {
    return ''
  }

  let text = `${data[0].description}`

  if (data[0]?.danger) {
    text = `<span class="has-text-danger title is-5"> <i class="has-text-warning fas fa-exclamation-triangle fa-bounce"></i> ${text}</span>`
  }

  if (data[0]?.type) {
    text += ` Expects: <code>${data[0].type}</code>`
  }

  return data[0]?.deprecated ? `<strong><code class="is-strike-through"">Deprecated</code></strong> - ${text}` : text
}

const fixBool = v => [true, 'true', '1'].includes(v)

const filteredRows = computed(() => {
  if (!query.value) {
    return items.value.filter(i => i.value !== undefined)
  }

  return items.value.filter(i => i.key.toLowerCase().includes(query.value.toLowerCase())).filter(i => i.value !== undefined)
})

const stateCallBack = async e => {
  if (!e.state && !e.detail) {
    return
  }
  const route = useRoute()
  if (!route.query?.edit) {
    await cancelForm()
    return
  }

  let item = items.value.find(i => i.key === route.query.edit)
  if (item && route.query?.value && !item?.value) {
    item.value = route.query.value
  }

  editEnv(item)
}

onMounted(async () => {
  await loadContent()
  window.addEventListener('popstate', stateCallBack)
})

onUnmounted(() => window.removeEventListener('popstate', stateCallBack))
</script>
