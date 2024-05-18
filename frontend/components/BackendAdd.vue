<template>
  <Message title="Important Information" message_class="has-background-warning-80 has-text-dark">
    <div class="content is-bold">
      <ul>
        <li>
          WatchState is single user tool. It doesn't support syncing multiple users play state.
          <NuxtLink target="_blank" v-text="'Visit this link'"
                    href="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#is-there-support-for-multi-user-setup"/>
          to learn more.
        </li>
        <li>
          If you are adding new backend that is fresh and doesn't have your current watch state, you should turn off
          import and enable only metadata import at the start to prevent overriding your current play state.
          <NuxtLink
              href="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#my-new-backend-overriding-my-old-backend-state--my-watch-state-is-not-correct"
              target="_blank" v-text="'Visit this link'"/>
          to learn more.
        </li>
      </ul>
    </div>
  </Message>
  <form id="backend_add_form" @submit.prevent="addBackend" @change="changeStage">
    <div class="card">
      <div class="card-header">
        <p class="card-header-title is-justify-center">Add Backend</p>
      </div>

      <div class="card-content">
        <div class="field">
          <label class="label">Type</label>
          <div class="control has-icons-left">
            <div class="select is-fullwidth">
              <select v-model="backend.type" class="is-capitalized" required>
                <option v-for="type in supported" :key="'type-'+type" :value="type">
                  {{ type }}
                </option>
              </select>
            </div>
            <div class="icon is-left">
              <i class="fas fa-server"></i>
            </div>
            <p class="help">
              Select the type of backend you want to add. Supported backends are: <code>{{
                supported.join(', ')
              }}</code>.
            </p>
          </div>
        </div>

        <div class="field">
          <label class="label">Name</label>
          <div class="control has-icons-left">
            <input class="input" type="text" v-model="backend.name" required>
            <div class="icon is-left">
              <i class="fas fa-user"></i>
            </div>
            <p class="help">
              Choose a unique name for this backend. You cannot change it later. Backend name must be in <code>lower
              case a-z, 0-9 and _</code> only.
            </p>
          </div>
        </div>

        <div class="field">
          <label class="label">
            <template v-if="'plex' !== backend.type">API Token</template>
            <template v-else>X-Plex-Token</template>
          </label>
          <div class="control has-icons-left">
            <input class="input" type="text" v-model="backend.token" required>
            <div class="icon is-left">
              <i class="fas fa-key"></i>
            </div>
            <p class="help">
              <template v-if="'plex'===backend.type">
                Enter the <code>X-Plex-Token</code>.
                <NuxtLink target="_blank" href="https://support.plex.tv/articles/204059436">
                  Visit This article for more information.
                </NuxtLink>
              </template>
              <template v-else>
                Generate a new API token from <code>Dashboard > Settings > API Keys</code>.
              </template>
            </p>
          </div>
        </div>

        <div class="field" v-if="'plex' === backend.type && stage >=1">
          <label class="label">Plex Server URL</label>
          <div class="control has-icons-left">
            <div class="select is-fullwidth" v-if="servers.length > 0">
              <select v-model="backend.url" class="is-capital" @change="updateIdentifier" required>
                <option v-for="server in servers" :key="'server-'+server.uuid" :value="server.uri">
                  {{ server.name }} - {{ server.address }}
                </option>
              </select>
            </div>
            <input class="input" type="text" v-model="backend.url" v-else required>
            <div class="icon is-left">
              <i class="fas fa-link" v-if="!serversLoading"></i>
              <i class="fas fa-spinner fa-pulse" v-else></i>

            </div>
            <p class="help">
              <template v-if="servers.length<1">
                Enter the URL of the backend. For example <code>http://localhost:32400</code>.&nbsp;
              </template>
              <a href="javascript:void(0)" @click="getServers">Attempt to discover servers associated with the token</a>.
            </p>
          </div>
        </div>

        <div class="field" v-if="'plex' !== backend.type">
          <label class="label">URL</label>
          <div class="control has-icons-left">
            <input class="input" type="text" v-model="backend.url" required>
            <div class="icon is-left">
              <i class="fas fa-link"></i>
            </div>
            <p class="help">
              Enter the URL of the backend. For example <code>http://localhost:32400</code>.
            </p>
          </div>
        </div>

        <template v-if="stage >= 2">
          <div class="field" v-if="'plex' !== backend.type">
            <label class="label">Unique Identifier</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.uuid" required>
              <div class="icon is-left">
                <i class="fas fa-server" v-if="!uuidLoading"></i>
                <i class="fas fa-spinner fa-pulse" v-else></i>
              </div>
              <p class="help">
                The backend unique ID is a random string generated on server setup. It is used to identify the backend
                uniquely. This is used for webhook matching and filtering.
                <a href="javascript:void(0)" @click="getUUid">Load automatically.</a>
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
                <select v-model="backend.user" class="is-capitalized">
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
                <a href="javascript:void(0)" @click="getUsers">
                  Retrieve User ids from backend.
                </a>
              </p>
            </div>
          </div>

          <div class="field" v-if="backend.import">
            <label class="label" for="backend_import">Import data from this backend</label>
            <div class="control">
              <input id="backend_import" type="checkbox" class="switch is-success" v-model="backend.import.enabled">
              <label for="backend_import">Enable</label>
              <p class="help">
                Import means to get the data from the backend and store it in the database.
              </p>
            </div>
          </div>

          <div class="field" v-if="backend.import && !backend.import.enabled">
            <label class="label" for="backend_import_metadata">Import metadata only from from this backend?</label>
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
            <label class="label" for="backend_export">Export data to this backend</label>
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
        </template>
      </div>

      <div class="card-footer">
        <div class="card-footer-item">
          <button class="button is-fullwidth is-primary" type="submit">
            <span class="icon"><i class="fas fa-plus"></i></span>
            <span>Add Backend</span>
          </button>
        </div>
      </div>
    </div>
  </form>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import {notification, ucFirst} from '~/utils/index.js'

const emit = defineEmits(['addBackend'])

const props = defineProps({
  backends: {
    type: Array,
    required: true
  }
})

const backend = ref({
  name: '',
  type: 'plex',
  url: '',
  token: '',
  uuid: '',
  user: '',
  import: {
    enabled: false
  },
  export: {
    enabled: false
  },
  webhook: {
    match: {
      user: false,
      uuid: false
    }
  },
  options: {}
})
const users = ref([])
const supported = ref([])
const servers = ref([])

const stage = ref(0)
const usersLoading = ref(false)
const uuidLoading = ref(false)
const serversLoading = ref(false)

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
  const required_values = ['type', 'token', 'url', 'uuid']

  if (required_values.some(v => !backend.value[v])) {
    if (showAlert) {
      required_values.forEach(v => {
        if (!backend.value[v]) {
          notification('error', 'Error', `Please fill the required field: ${v}.`)
        }
      })
    }
    return
  }

  usersLoading.value = true

  let data = {
    token: backend.value.token,
    url: backend.value.url,
    uuid: backend.value.uuid,
  };

  if (backend.value.options && backend.value.options.ADMIN_TOKEN) {
    data.options = {
      ADMIN_TOKEN: backend.value.options.ADMIN_TOKEN
    }
  }

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
  backend.value.user = users.value[0].id
}

watch(stage, async (value) => {
  if (value >= 1 && 'plex' === backend.value.type && servers.value.length < 1) {
    await getServers()
  }

  if (value >= 2) {
    if ('plex' !== backend.value.type && !backend.value.uuid) {
      await getUUid();
    }

    if (users.value.length < 1) {
      await getUsers()
    }
  }
})

onMounted(async () => {
  const response = await request('/system/supported')
  supported.value = await response.json()
  backend.value.type = supported.value[0]
})

const changeStage = async () => {
  const required = ['name', 'type', 'token']

  if ('plex' !== backend.value.type) {
    required.push('url')
  }

  if (required.some(v => !backend.value[v])) {
    stage.value = 0
    return
  }

  if (props.backends.find(b => b.name === backend.value.name)) {
    notification('error', 'Error', `Backend with name ${backend.value.name} already exists.`)
    stage.value = 0
    return
  }

  if ('plex' === backend.value.type && !backend.value.uuid) {
    stage.value = 1
    return
  }

  stage.value = 2
}

const addBackend = async () => {
  const required_values = ['name', 'type', 'token', 'url', 'uuid', 'user'];

  if (required_values.some(v => !backend.value[v])) {
    required_values.forEach(v => {
      if (!backend.value[v]) {
        notification('error', 'Error', `Please fill the required field: ${v}.`)
      }
    })
    return
  }

  if ('plex' === backend.value.type) {
    let token = users.value.find(u => u.id === backend.value.user).token
    if (token !== backend.value.token) {
      backend.value.options.ADMIN_TOKEN = backend.value.token;
      backend.value.token = token
    }
  }

  const response = await request(`/backends/`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(backend.value)
  })

  const json = await response.json()
  if (response.status >= 400) {
    notification('error', 'Error', `Failed to Add backend. (${json.error.code}: ${json.error.message}).`)
    return false
  }

  notification('success', 'Information', `Backend ${backend.value.name} added successfully.`)
  emit('addBackend', backend)
  return true
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
  backend.value.url = servers.value[0].uri
  backend.value.uuid = servers.value[0].identifier
  await changeStage()
}

const updateIdentifier = async () => {
  backend.value.uuid = servers.value.find(s => s.uri === backend.value.url).identifier
  await getUsers()
}

</script>
