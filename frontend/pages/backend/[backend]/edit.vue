<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
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
          This backend is using accesstoken instead of API keys, And this method untested and may not work as expected.
          Please make sure you know what you are doing. Simple operations like <code>Import</code>, <code>Export</code>
          should work fine.
        </p>
        <p>
          How the access token interact with the rest of the API is undefined and untested by us. Please use with
          caution. If you notice any issue, please report it to us.
        </p>
      </Message>
    </div>

    <div class="column is-12" v-if="isLoading">
      <Message message_class="is-background-info-90 has-text-dark" title="Loading"
               icon="fas fa-spinner fa-spin" message="Loading backend settings. Please wait..."/>
    </div>

    <div v-else class="column is-12">
      <form id="backend_edit_form" @submit.prevent="saveContent">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-justify-center">Edit Backend - {{ backend.name }}</p>
          </header>

          <div class="card-content">
            <div class="field">
              <label class="label">Name</label>
              <div class="control has-icons-left">
                <input class="input" type="text" v-model="backend.name" required readonly disabled>
                <div class="icon is-left">
                  <i class="fas fa-id-badge"></i>
                </div>
                <p class="help">
                  Choose a unique name for this backend. You cannot change it later. Backend name must be in <code>lower
                  case a-z, 0-9 and _</code> only.
                </p>
              </div>
            </div>

            <div class="field">
              <label class="label">Type</label>
              <div class="control has-icons-left">
                <input class="input" type="text" v-model="backend.type" readonly disabled>
                <div class="icon is-left">
                  <i class="fas fa-server"></i>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="label">URL</label>
              <div class="control has-icons-left">
                <div class="select is-fullwidth" v-if="servers.length > 0">
                  <select v-model="backend.url" class="is-capital" @change="updateIdentifier" required>
                    <option value="" disabled>Select Server</option>
                    <option v-for="server in servers" :key="server.uuid" :value="server.uri">
                      {{ server.name }} - {{ server.uri }}
                    </option>
                  </select>
                </div>
                <input class="input" type="text" v-model="backend.url" v-else required>
                <div class="icon is-left">
                  <i class="fas fa-link"></i>
                </div>
                <p class="help">
                  <template v-if="servers.length<1">
                    Enter the URL of the backend. For example
                    <code v-if="'plex' === backend.type">http://192.168.8.11:32400</code>
                    <code v-else>http://192.168.8.100:8096</code>
                    .
                  </template>
                  <template v-else>
                    Those are the servers associated with the Plex Token. Select the server you want to use.
                  </template>
                </p>
              </div>
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
                      <div class="icon is-left">
                        <i class="fas fa-key"></i>
                      </div>
                    </div>
                    <div class="control">
                      <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
                              v-tooltip="'Toggle token'">
                        <span class="icon" v-if="!exposeToken"><i class="fas fa-eye"></i></span>
                        <span class="icon" v-else><i class="fas fa-eye-slash"></i></span>
                      </button>
                    </div>
                  </div>
                  <p class="help">
                    <template v-if="'plex'===backend.type">
                      Enter the <code>X-Plex-Token</code>.
                      <NuxtLink target="_blank"
                                to="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/"
                                v-text="'Visit This article for more information.'"/>
                    </template>
                    <template v-else>
                      You can generate a new API key from <code>Dashboard > Settings > API Keys</code>.
                    </template>
                  </p>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="label">Backend Unique ID</label>
              <div class="control has-icons-left">
                <input class="input" type="text" v-model="backend.uuid" required :disabled="isLimitedToken">
                <div class="icon is-left">
                  <i class="fas fa-cloud" v-if="!uuidLoading"></i>
                  <i class="fas fa-spinner fa-pulse" v-else></i>
                </div>
                <p class="help">
                  <span v-if="'plex' === backend.type">
                    The backend unique ID is random string generated on server setup, In Plex case it used to inquiry
                    about the users associated with the server to generate limited <code>X-Plex-Token</code> for them.
                    It
                    used by webhooks as a filter to match the backend. in-case you are member of multiple servers.
                  </span>
                  <span v-else>
                    The backend unique ID is a random string generated on server setup. It is used to identify the
                    backend
                    uniquely. This is used for webhook matching and filtering.
                  </span>
                  <NuxtLink @click="getUUid" v-if="!isLimitedToken" v-text="'Get from the backend.'"/>
                </p>
              </div>
            </div>

            <div class="field">
              <label class="label">
                <template v-if="users.length>0">Associated User</template>
                <template v-else>User ID</template>
              </label>
              <div class="control has-icons-left">
                <div class="select is-fullwidth" v-if="users.length>0">
                  <select v-model="backend.user" class="is-capitalized" :disabled="isLimitedToken">
                    <option v-for="user in users" :key="'uid-'+user.id" :value="user.id">
                      {{ user.name }}
                    </option>
                  </select>
                </div>
                <input class="input" type="text" v-model="backend.user" v-else>
                <div class="icon is-left">
                  <i class="fas fa-user-tie" v-if="!usersLoading"></i>
                  <i class="fas fa-spinner fa-pulse" v-else></i>
                </div>
                <p class="help">
                  <span v-if="'plex' === backend.type">
                    Plex doesn't use standard API practice for identifying users. They use <code>X-Plex-Token</code> to
                    identify the user. The user selected here will only be used for webhook matching and filtering.
                  </span>
                  <span v-else>
                    Which <code>{{ ucFirst(backend.type) }}</code> user should this backend use? The User ID will
                    determine the
                    data we get from the backend. And for webhook matching and filtering.
                  </span>
                  This tool is meant for single user use.
                  <a href="javascript:void(0)" @click="getUsers" v-if="!isLimitedToken">
                    Retrieve User ids from backend.
                  </a>
                </p>
              </div>
            </div>

            <div class="field" v-if="backend.import">
              <label class="label" for="backend_import">Import data from this backend?</label>
              <div class="control">
                <input id="backend_import" type="checkbox" class="switch is-success" v-model="backend.import.enabled">
                <label for="backend_import">Enable</label>
                <p class="help">
                  Import means to get the data from the backend and store it in the database.
                </p>
              </div>
            </div>

            <div class="field" v-if="backend.import && !backend.import.enabled">
              <label class="label" for="backend_import_metadata">Import metadata only from this backend?</label>
              <div class="control">
                <input id="backend_import_metadata" type="checkbox" class="switch is-success"
                       v-model="backend.options.IMPORT_METADATA_ONLY">
                <label for="backend_import_metadata">Enable</label>
                <p class="help has-text-danger">
                  To efficiently push changes to the backend we need relation map and this require
                  us to get metadata from the backend. You have Importing disabled, as such this option
                  allow us to import this backend metadata without altering your play state.
                </p>
              </div>
            </div>

            <div class="field" v-if="backend.export">
              <label class="label" for="backend_export">Export data to this backend?</label>
              <div class="control">
                <input id="backend_export" type="checkbox" class="switch is-success" v-model="backend.export.enabled">
                <label for="backend_export">Enable</label>
                <p class="help">
                  Export means to send the data from the database to this backend.
                </p>
              </div>
            </div>

            <div class="field" v-if="backend.webhook">
              <label class="label" for="webhook_match_user">Webhook match user</label>
              <div class="control">
                <input id="webhook_match_user" type="checkbox" class="switch is-success"
                       v-model="backend.webhook.match.user">
                <label for="webhook_match_user">Enable</label>
                <p class="help">
                  Check webhook payload for user id match. if it does not match, the payload will be ignored.
                </p>
              </div>
            </div>

            <div class="field" v-if="backend.webhook">
              <label class="label" for="webhook_match_uuid">Webhook match backend id</label>
              <div class="control">
                <input id="webhook_match_uuid" type="checkbox" class="switch is-success"
                       v-model="backend.webhook.match.uuid">
                <label for="webhook_match_uuid">Enable</label>
                <p class="help">
                  Check webhook payload for backend unique id. if it does not match, the payload will be ignored.
                </p>
              </div>
            </div>

            <div class="field">
              <label class="label is-clickable" @click="showOptions = !showOptions">
                <span class="icon-text">
                  <span class="icon">
                    <i v-if="showOptions" class="fas fa-arrow-up"></i>
                    <i v-else class="fas fa-arrow-down"></i>
                  </span>
                  <span>Additional options...</span>
                </span>
              </label>
              <template v-if="showOptions">
                <div class="columns is-multiline is-mobile">
                  <template v-for="(val, key) in backend?.options" :key="'bo-'+key">
                    <div class="column is-5">
                      <input type="text" class="input" :value="key" readonly disabled>
                      <p class="help is-unselectable">
                        <span class="icon has-text-info">
                          <i class="fas fa-info-circle" :class="{'fa-bounce': newOptions[key]}"></i>
                        </span>
                        {{ optionsList.find(v => v.key === key)?.description }}
                      </p>
                    </div>
                    <div class="column is-6">
                      <input type="text" class="input" v-model="backend.options[key]" required>
                    </div>
                    <div class="column is-1">
                      <button class="button is-danger" @click.prevent="removeOption(key)">
                        <span class="icon">
                          <i class="fas fa-trash"></i>
                        </span>
                      </button>
                    </div>
                  </template>
                </div>
                <div class="columns is-multiline is-mobile">
                  <div class="column is-12">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-plus"></i></span>
                      <span>Add new option</span>
                    </span>
                  </div>
                  <div class="column is-5">
                    <div class="select is-fullwidth">
                      <select v-model="selectedOption">
                        <option value="">Select Option</option>
                        <option v-for="option in filteredOptions(optionsList)"
                                :key="'opt-'+option.key" :value="option.key">
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
                        <i class="fas fa-add"></i>
                      </span>
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>
          <div class="card-footer">
            <button class="button card-footer-item is-fullwidth is-primary" type="submit">
              <span class="icon"><i class="fas fa-save"></i></span>
              <span>Save Settings</span>
            </button>
            <NuxtLink class="card-footer-item button is-fullwidth is-danger" :to="`/backend/${id}`">
              <span class="icon"><i class="fas fa-cancel"></i></span>
              <span>Cancel changes</span>
            </NuxtLink>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import {notification, ucFirst} from '~/utils/index.js'
import {ref} from "vue";
import Message from "~/components/Message.vue";

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
const usersLoading = ref(false)
const uuidLoading = ref(false)
const optionsList = ref([])
const selectedOption = ref('')
const newOptions = ref({})
const exposeToken = ref(false)
const servers = ref([])
const serversLoading = ref(false)
const isLimitedToken = computed(() => Boolean(backend.value.options?.is_limited_token))

const selectedOptionHelp = computed(() => {
  const option = optionsList.value.find(v => v.key === selectedOption.value)
  return option ? option.description : ''
});

useHead({title: 'Backends - Edit: ' + id})

const loadContent = async () => {
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
  try {
    const response = await request(`/backend/${id}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(backend.value)
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

  if (!confirm(`Are you sure you want to remove this option [${key}]?`)) {
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
  if (backend.value.options.length < 1) {
    backend.value.options = {[selectedOption.value]: ''}
  } else {
    backend.value.options[selectedOption.value] = ''
  }

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
    user: backend.value.user
  };

  if (backend.value.options && backend.value.options?.ADMIN_TOKEN) {
    data.options = {
      ADMIN_TOKEN: backend.value.options.ADMIN_TOKEN
    }
  }
  if (backend.value.options && backend.value.options?.is_limited_token) {
    data.options = {
      is_limited_token: Boolean(backend.value.options.is_limited_token)
    }
  }

  const response = await request(`/backends/users/${backend.value.type}`, {
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

watch(showOptions, async (value) => {
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

const filteredOptions = (options) => {
  if (!options) {
    return []
  }
  return options.filter(v => !backend.value.options[v.key] && !newOptions.value[v.key])
}


const getServers = async () => {
  if ('plex' !== backend.value.type || servers.value.length > 0) {
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

onMounted(async () => await loadContent())

</script>
