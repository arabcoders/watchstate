<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server"/>&nbsp;</span>
          <NuxtLink to="/backends" v-text="'Backends'"/>
          -
          <NuxtLink :to="'/backend/' + id" v-text="id"/>
          : Edit
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped"></div>
        </div>

        <div class="is-hidden-mobile">
          <span class="subtitle">Edit the backend settings.</span>
        </div>
      </div>

      <div class="column is-12" v-if="isLimitedToken">
        <Message title="For your information" message_class="has-background-warning-90 has-text-dark"
                 icon="fas fa-info-circle">
          <p>
            This backend is using accesstoken instead of API keys, And this method untested and may not work as
            expected. Please make sure you know what you are doing. Simple operations like <strong>Import</strong>,
            <strong>Export</strong> should work fine.
          </p>
          <p>
            How the access token interact with the rest of the API is undefined and untested by us. Please use with
            caution. If you notice any issue, please report it to us.
          </p>
        </Message>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message message_class="is-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
                 message="Loading backend settings. Please wait..."/>
      </div>

      <div v-else class="column is-12">
        <form id="backend_edit_form" @submit.prevent="saveContent">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title">
                Edit Backend:&nbsp;<u class="has-text-danger">{{ api_user }}</u>@{{ backend.name }}</p>
            </header>

            <div class="card-content">
              <div class="field">
                <label class="label">Local User</label>
                <div class="control has-icons-left">
                  <input type="text" class="input is-capitalized" :value="api_user" required readonly disabled>
                  <div class="icon is-left"><i class="fas fa-user"/></div>
                </div>
                <p class="help is-unselectable">The local user which this backend is associated with.</p>
              </div>

              <div class="field">
                <label class="label">Name</label>
                <div class="control has-icons-left">
                  <input class="input" type="text" v-model="backend.name" required readonly disabled>
                  <div class="icon is-left"><i class="fas fa-id-badge"/></div>
                </div>
                <p class="help is-unselectable">The backend name in WatchState.</p>
              </div>

              <div class="field">
                <label class="label">Type</label>
                <div class="control has-icons-left">
                  <input class="input" type="text" v-model="backend.type" readonly disabled>
                  <div class="icon is-left">
                    <i class="fas fa-server"/>
                  </div>
                </div>
                <p class="help is-unselectable">Backend Type.</p>
              </div>

              <div class="field">
                <label class="label">URL</label>
                <div class="field-body">
                  <div class="field">
                    <div class="field has-addons">
                      <div class="control is-expanded has-icons-left">
                        <div class="select is-fullwidth" v-if="servers.length > 0">
                          <select v-model="backend.url" class="is-capital" @change="updateIdentifier" required>
                            <option value="" disabled>Select Server</option>
                            <option v-for="server in servers" :key="server.uuid" :value="server.uri">
                              {{ server.name }} - {{ server.uri }}
                            </option>
                          </select>
                        </div>
                        <input class="input" type="text" v-model="backend.url" v-else required>
                        <div class="icon is-left"><i class="fas fa-link"/></div>
                      </div>
                      <div class="control" v-if="servers.length > 0">
                        <button class="button is-primary" type="button" :disabled="serversLoading" @click="getServers">
                          <span class="icon"><i class="fa"
                                                :class="{'fa-spinner fa-spin': serversLoading,'fa-refresh' : !serversLoading }"/></span>
                          <span class="is-hidden-mobile">Reload</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
                <p class="help">
                  <template v-if="servers.length < 1">
                    Enter the URL of the backend. For example
                    <strong>http://192.168.8.100:{{ 'plex' === backend.type ? '32400' : '8096' }}</strong>.
                  </template>
                  <template v-else>Select the server you want to use.</template>
                </p>
              </div>

              <div class="field">
                <label class="label">
                  <template v-if="'plex' !== backend.type">API Key</template>
                  <template v-else>X-Plex-Token</template>
                </label>
                <div class="field-body">
                  <div class="field">
                    <div class="field has-addons">
                      <div class="control is-expanded has-icons-left">
                        <input class="input" v-model="backend.token" required
                               :type="false === exposeToken ? 'password' : 'text'">
                        <div class="icon is-left"><i class="fas fa-key"/></div>
                      </div>
                      <div class="control">
                        <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
                                v-tooltip="'Toggle token'">
                          <span class="icon"><i class="fas" :class="exposeToken ? 'fa-eye-slash' : 'fa-eye'"/></span>
                        </button>
                      </div>
                    </div>
                    <p class="help">
                      <template v-if="'plex' === backend.type">
                        Enter the <strong>X-Plex-Token</strong>.
                        <NuxtLink target="_blank"
                                  to="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/"
                                  v-text="'Visit This article for more information.'"/>
                      </template>
                      <template v-else>
                        You can generate a new API key from <strong>Dashboard > Settings > API Keys</strong>.
                      </template>
                    </p>
                  </div>
                </div>
              </div>

              <div class="field">
                <label class="label">Backend Unique ID</label>
                <div class="field-body">
                  <div class="field has-addons">
                    <div class="control is-expanded has-icons-left">
                      <input type="text" class="input is-fullwidth" v-model="backend.uuid" required
                             :disabled="isLimitedToken">
                      <div class="icon is-left">
                        <i class="fas fa-cloud" v-if="!uuidLoading"/>
                        <i class="fas fa-spinner fa-pulse" v-else/>
                      </div>
                    </div>
                    <div class="control" v-if="!isLimitedToken">
                      <button class="button is-primary" type="button" :disabled="uuidLoading" @click="getUUid">
                        <span class="icon"><i class="fa"
                                              :class="{'fa-spinner fa-spin': uuidLoading,'fa-refresh' : !uuidLoading }"/></span>
                        <span class="is-hidden-mobile">Reload</span>
                      </button>
                    </div>
                  </div>
                </div>
                <p class="help">
                  <span v-if="'plex' === backend.type">
                    The backend unique ID is random string generated on server setup, In Plex case it used to inquiry
                    about the users associated with the server to generate limited <strong>X-Plex-Token</strong> for
                    them.
                    It used by webhooks as a filter to match the backend. in-case you are member of multiple servers.
                  </span>
                  <span v-else>
                    The backend unique ID is a random string generated on server setup. It is used to identify the
                    backend uniquely. This is used for webhook matching and filtering.
                  </span>
                </p>
              </div>

              <div class="field">
                <label class="label">{{ users.length > 0 ? 'User' : 'User ID' }}</label>
                <div class="field-body">
                  <div class="field has-addons">
                    <div class="control is-expanded has-icons-left">
                      <div class="select is-fullwidth" v-if="users.length > 0">
                        <select v-model="backend.user" class="is-capitalized" :disabled="isLimitedToken">
                          <option v-for="user in users" :key="'uid-' + user.id" :value="user.id">
                            {{ user.name }}
                          </option>
                        </select>
                      </div>
                      <input class="input is-fullwidth" type="text" v-model="backend.user" v-else>
                      <div class="icon is-left">
                        <i class="fas fa-user-tie" v-if="!usersLoading"/>
                        <i class="fas fa-spinner fa-pulse" v-else/>
                      </div>
                    </div>
                    <div class="control" v-if="!isLimitedToken">
                      <button class="button is-primary" type="button" :disabled="usersLoading" @click="getUsers">
                        <span class="icon"><i class="fa"
                                              :class="{'fa-spinner fa-spin': usersLoading,'fa-refresh' : !usersLoading }"/></span>
                        <span class="is-hidden-mobile">Reload</span>
                      </button>
                    </div>
                  </div>
                </div>
                <p class="help">
                  <span v-if="'plex' === backend.type">
                    Plex doesn't use standard API practice for identifying users. They use <strong>X-Plex-Token</strong>
                    to identify the user. The list can only be populated if the user is admin or has
                    <strong>ADMIN_TOKEN</strong> set in additional options.
                  </span>
                  <span v-else>
                    Which user should this backend configuration use? The User will determine the data we get from
                    the backend. And for webhook matching and filtering.
                  </span>
                </p>
              </div>

              <div class="field" v-if="backend.import">
                <label class="label" for="backend_import">Import play and progress updates from this backend?</label>
                <div class="control">
                  <input id="backend_import" type="checkbox" class="switch is-success" v-model="backend.import.enabled">
                  <label for="backend_import" class="is-unselectable">
                    {{ backend.import.enabled ? 'Yes' : 'No' }}
                  </label>
                </div>
                <p class="help is-bold has-text-danger">
                  <span class="icon"><i class="fas fa-info-circle"/></span>
                  Get play state and progress from this backend.
                </p>
              </div>

              <div class="field" v-if="backend.import && !backend.import.enabled">
                <label class="label" for="backend_import_metadata">Import metadata from this backend?</label>
                <div class="control">
                  <input id="backend_import_metadata" type="checkbox" class="switch is-success"
                         v-model="backend.options.IMPORT_METADATA_ONLY">
                  <label for="backend_import_metadata" class="is-unselectable">
                    {{ backend.options.IMPORT_METADATA_ONLY ? 'Yes' : 'No' }}
                  </label>
                </div>
                <p class="help has-text-danger is-bold">
                  <span class="icon"><i class="fas fa-info-circle"/></span>
                  As you have disabled the state import, you should enable this option for efficient and fast updates
                  to this backend.
                </p>
              </div>

              <div class="field" v-if="backend.export">
                <label class="label" for="backend_export">Send play and progress updates to this backend?</label>
                <div class="control">
                  <input id="backend_export" type="checkbox" class="switch is-success" v-model="backend.export.enabled">
                  <label for="backend_export" class="is-unselectable">
                    {{ backend.export.enabled ? 'Yes' : 'No' }}
                  </label>
                </div>
                <p class="help is-bold has-text-danger">
                  <span class="icon"><i class="fas fa-info-circle"/></span>
                  The backend will not receive any data from WatchState if this is disabled.
                </p>
              </div>

              <div class="field" v-if="backend.webhook">
                <label class="label" for="webhook_match_user">Enable match user for webhook?</label>
                <div class="control">
                  <input id="webhook_match_user" type="checkbox" class="switch is-success"
                         v-model="backend.webhook.match.user">
                  <label for="webhook_match_user" class="is-unselectable">
                    {{ backend.webhook.match.user ? 'Yes' : 'No' }}
                  </label>
                </div>
                <p class="help">
                  Check webhook payload for user id match. if it does not match, the payload will be ignored.
                </p>
              </div>

              <div class="field" v-if="backend.webhook">
                <label class="label" for="webhook_match_uuid">Enable match backend id for webhook?</label>
                <div class="control">
                  <input id="webhook_match_uuid" type="checkbox" class="switch is-success"
                         v-model="backend.webhook.match.uuid">
                  <label for="webhook_match_uuid">
                    {{ backend.webhook.match.uuid ? 'Yes' : 'No' }}
                  </label>
                </div>
                <p class="help">
                  Check webhook payload for backend unique id. if it does not match, the payload will be ignored.
                </p>
              </div>

              <hr>

              <div class="field">
                <label class="label is-clickable is-unselectable" @click="showOptions = !showOptions">
                  <span class="icon-text">
                    <span class="icon">
                      <i v-if="showOptions" class="fas fa-arrow-up"/>
                      <i v-else class="fas fa-arrow-down"/>
                    </span>
                    <span>Additional options...</span>
                  </span>
                </label>
                <p class="help is-unselectable">
                  These are advanced options. Please only change them, if you are told to do so by the developers.
                </p>
                <template v-if="showOptions">
                  <div class="columns is-multiline is-mobile">
                    <template v-for="_option in flatOptionPaths" :key="'bo-'+_option">
                      <div class="column is-5">
                        <input type="text" class="input" :value="_option" readonly disabled>
                        <p class="help is-unselectable">
                          <span class="icon has-text-info">
                            <i class="fas fa-info-circle" :class="{ 'fa-bounce': newOptions[_option] }"/>
                          </span>
                          {{ option_describe(_option) }}
                        </p>
                      </div>
                      <div class="column is-6">
                        <input type="text" class="input" :value="option_get(_option)"
                               @input="e => option_set(_option, e.target.value)" required>
                      </div>
                      <div class="column is-1">
                        <button class="button is-danger" @click.prevent="removeOption(_option)">
                          <span class="icon"><i class="fas fa-trash"/></span>
                        </button>
                      </div>
                    </template>
                  </div>
                  <div class="columns is-multiline is-mobile">
                    <div class="column is-12">
                      <span class="icon-text">
                        <span class="icon"><i class="fas fa-plus"/></span>
                        <span>Add new option</span>
                      </span>
                    </div>
                    <div class="column is-5">
                      <div class="select is-fullwidth">
                        <select v-model="selectedOption">
                          <option value="">Select Option</option>
                          <option v-for="option in filteredOptions(optionsList)" :key="'opt-' + option.key"
                                  :value="option.key">
                            {{ option.key }}
                          </option>
                        </select>
                      </div>
                    </div>
                    <div class="column is-6">
                      {{ selectedOptionHelp }}
                    </div>
                    <div class="column is-1">
                      <button class="button is-primary" @click.prevent="addOption">
                        <span class="icon">
                          <i class="fas fa-add"/>
                        </span>
                      </button>
                    </div>
                  </div>
                </template>
              </div>
            </div>
            <div class="card-footer">
              <button class="button card-footer-item is-fullwidth is-primary" type="submit">
                <span class="icon"><i class="fas fa-save"/></span>
                <span>Save Settings</span>
              </button>
              <NuxtLink class="card-footer-item button is-fullwidth is-danger" :to="`/backend/${id}`">
                <span class="icon"><i class="fas fa-cancel"/></span>
                <span>Cancel changes</span>
              </NuxtLink>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import '~/assets/css/bulma-switch.css'
import {notification} from '~/utils/index'
import Message from '~/components/Message.vue'
import {useStorage} from "@vueuse/core"
import request from "~/utils/request.js"

const id = useRoute().params.backend
const redirect = useRoute().query?.redirect ?? `/backend/${id}`

const backend = ref({
  name: '',
  type: '',
  url: '',
  token: '',
  uuid: '',
  user: '',
  import: {enabled: false},
  export: {enabled: false},
  webhook: {match: {user: false, uuid: false}},
  options: {}
})

const showOptions = ref(false)
const isLoading = ref(true)
const users = ref([])
const supported = ref([])
const usersLoading = ref(false)
const uuidLoading = ref(false)
const optionsList = ref([])
const selectedOption = ref('')
const newOptions = ref({})
const exposeToken = ref(false)
const servers = ref([])
const serversLoading = ref(false)
const isLimitedToken = computed(() => Boolean(backend.value.options?.is_limited_token))
const api_user = useStorage('api_user', 'main')

const selectedOptionHelp = computed(() => {
  const option = optionsList.value.find(v => v.key === selectedOption.value)
  return option ? option.description : ''
});

useHead({title: 'Backends - Edit: ' + id})

const loadContent = async () => {
  supported.value = await (await request('/system/supported')).json()

  const content = await request(`/backend/${id}`)
  let json = await content.json()

  if (!json?.options || typeof json.options !== 'object') {
    json.options = {}
  }

  backend.value = json;

  if ('plex' === backend.value.type) {
    await getServers()
  }

  await getUsers()

  isLoading.value = false
}

const saveContent = async () => {
  const json_text = toRaw(backend.value)

  const flat = {}
  flatOptionPaths.value.forEach(path => flat[path] = option_get(path))

  if (Object.keys(flat).length > 0) {
    json_text.options = flat
  }

  try {
    const response = await request(`/backend/${id}`, {
      method: 'PUT',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(json_text)
    })

    const json = await response.json()
    if (200 !== response.status) {
      notification('error', 'Error', `Failed to save backend settings. (${json.error.code}: ${json.error.message}).`)
      return
    }

    notification('success', 'Success', `Successfully updated '${id}' settings.`)
    const to = !redirect.startsWith('/') ? `/backend/${id}` : redirect
    await navigateTo({path: to})
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const removeOption = async (key) => {
  if (newOptions.value[key]) {
    delete newOptions.value[key]
    delete backend.value.options[key]
    return
  }

  if (!confirm(`Are you sure you want to remove this option '${key}'?`)) {
    return
  }

  const response = await request(`/backend/${id}/option/options.${key}`, {method: 'DELETE'})

  if (!response.ok) {
    const json = await response.json()
    notification('error', 'Error', `Failed to remove the option. (${json.error.code}: ${json.error.message}).`)
    return
  }

  notification('success', 'Information', `Option [${key}] removed successfully.`)
  delete backend.value.options[key]
}

const addOption = async () => {
  if (!selectedOption.value) {
    notification('error', 'Error', 'Please select an option to add.')
    return
  }

  backend.value.options = backend.value.options || {}
  option_set(selectedOption.value, '')
  newOptions.value[selectedOption.value] = true
  selectedOption.value = ''
}

const getUUid = async () => {
  const required_values = ['type', 'token', 'url'];

  if (required_values.some(v => !backend.value[v])) {
    notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    return
  }

  uuidLoading.value = true

  const response = await request(`/backends/uuid/${backend.value.type}`, {
    method: 'POST',
    body: JSON.stringify({
      token: backend.value.token,
      url: backend.value.url
    })
  })

  const json = await response.json()
  uuidLoading.value = false

  if (!response.ok) {
    notification('error', 'Error', 'Failed to get the UUID from the backend.')
    return
  }

  backend.value.uuid = json.identifier
}

const getUsers = async (showAlert = true) => {
  const required_values = ['type', 'token', 'url', 'uuid'];

  if (required_values.some(v => !backend.value[v])) {
    if (showAlert) {
      notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    }
    return
  }

  usersLoading.value = true

  let data = {
    token: backend.value.token,
    url: backend.value.url,
    uuid: backend.value.uuid,
    user: backend.value.user,
    options: {},
  };

  ['ADMIN_TOKEN', 'plex_guest_user', 'PLEX_USER_PIN', 'is_limited_token'].forEach(v => {
    if (backend.value.options && backend.value.options[v]) {
      data.options[v] = backend.value.options[v]
    }
  })

  const response = await request(`/backends/users/${backend.value.type}?tokens=1`, {
    method: 'POST',
    body: JSON.stringify(data)
  })

  const json = await response.json()

  usersLoading.value = false

  if (200 !== response.status) {
    notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    return
  }

  users.value = json
}

watch(showOptions, async value => {
  if (!value) {
    return
  }
  if (optionsList.value.length > 0) {
    return
  }

  const response = await request(`/backends/spec`)
  const json = await response.json()
  json.forEach(v => {
    if (false === v.key.startsWith('options.')) {
      return
    }
    v['key'] = v.key.replace('options.', '')
    optionsList.value.push(v)
  })
});

const filteredOptions = options => {
  if (!options) {
    return []
  }
  return options.filter(v => !backend.value.options[v.key] && !newOptions.value[v.key])
}

const getServers = async () => {
  if ('plex' !== backend.value.type) {
    return
  }

  if (!backend.value.token) {
    notification('error', 'Error', `Token is required to get list of servers.`)
    return
  }

  serversLoading.value = true

  let data = {
    token: backend.value.token,
    url: window.location.origin,
  };

  if (backend.value?.options && backend.value.options?.ADMIN_TOKEN) {
    data.options = {
      ADMIN_TOKEN: backend.value.options.ADMIN_TOKEN
    }
  }

  const response = await request(`/backends/discover/${backend.value.type}`, {
    method: 'POST',
    body: JSON.stringify(data)
  })

  serversLoading.value = false

  const json = await response.json()

  if (200 !== response.status) {
    notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    return
  }

  servers.value = json
}

const updateIdentifier = async () => {
  backend.value.uuid = servers.value.find(s => s.uri === backend.value.url).identifier
  await getUsers()
}

watch(() => backend.value.user, async () => {
  if (users.value.length < 1 || 'plex' !== backend.value.type) {
    return
  }

  // -- get token for the user
  users.value.forEach(u => {
    if (u.id !== backend.value.user) {
      return
    }

    if (u?.guest) {
      backend.value.options.plex_external_user = true
    } else {
      if (backend.value.options?.plex_external_user) {
        delete backend.value.options.plex_external_user
      }
    }

    backend.value.options.plex_user_name = u.name
    backend.value.options.plex_user_uuid = u.uuid


    if (!u?.token) {
      notification('error', 'Error', `User token not found`)
      return
    }

    backend.value.token = u.token
  })
})

const flattenOptions = (obj, prefix = '') => {
  const out = []

  for (const [key, val] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key

    if (Array.isArray(val)) {
      if (val.length === 0) {
        continue
      }
      out.push(path)
      continue
    }

    if (val !== null && typeof val === 'object') {
      if (Object.keys(val).length === 0) {
        continue
      }
      out.push(...flattenOptions(val, path))
      continue
    }

    out.push(path)
  }

  return out
}

const flatOptionPaths = computed(() => flattenOptions(backend.value.options))

const option_get = path => path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), backend.value.options)
const option_set = (path, value) => {
  const keys = path.split('.')
  const last = keys.pop()
  let target = backend.value.options
  for (const k of keys) {
    if (target[k] == null || typeof target[k] !== 'object' || Array.isArray(target[k])) {
      target[k] = {}
    }
    target = target[k]
  }

  target[last] = value
}

const option_describe = path => {
  const item = optionsList.value.find((v) => v.key === path)
  return item ? item.description : ''
}

onMounted(async () => await loadContent())

</script>
