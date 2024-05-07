<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink href="/backends">Backends</NuxtLink>
        - Edit:
        <NuxtLink :href="'/backend/' + id">{{ id }}</NuxtLink>
      </span>

      <div class="is-pulled-right">
        <div class="field is-grouped"></div>
      </div>
    </div>

    <div class="column is-12" v-if="isLoading">
      <Message message_class="is-info" title="Information">
        <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
        <span>Loading backend settings, please wait...</span>
      </Message>
    </div>
    <div v-else class="column is-12">
      <form id="backend_edit_form" @submit.prevent="saveContent">
        <div class="box">

          <div class="field">
            <label class="label">Name</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.name" required readonly disabled>
              <div class="icon is-small is-left">
                <i class="fas fa-user"></i>
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
              <div class="icon is-small is-left">
                <i class="fas fa-globe"></i>
              </div>
            </div>
          </div>

          <div class="field">
            <label class="label">URL</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.url" required>
              <div class="icon is-small is-left">
                <i class="fas fa-link"></i>
              </div>
              <p class="help">
                Enter the URL of the backend.
                <a v-if="'plex' === backend.type" href="javascript:void(0)">Get associated servers with token. NYI</a>
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
              <div class="icon is-small is-left">
                <i class="fas fa-key"></i>
              </div>
              <p class="help">
                <template v-if="'plex'===backend.type">
                  Enter the <code>X-Plex-Token</code>. <a target="_blank"
                                                          href="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/">
                  Visit This article for more information
                </a>.
                </template>
                <template v-else>
                  Generate a new API token from <code>Dashboard > Settings > API Keys</code>.
                </template>
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend Unique ID</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.uuid" required>
              <div class="icon is-small is-left">
                <i class="fas fa-server" v-if="!uuidLoading"></i>
                <i class="fas fa-spinner fa-pulse" v-else></i>
              </div>
              <p class="help">
                The Unique identifier for the backend.
                <a href="javascript:void(0)" @click="getUUid">Get from the backend.</a>
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label">Backend User ID</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth" v-if="users.length>0">
                <select v-model="backend.user" class="is-capitalized">
                  <option v-for="user in users" :key="'uid-'+user.id" :value="user.id">
                    {{ user.name }}
                  </option>
                </select>
              </div>
              <input class="input" type="text" v-model="backend.user" v-else>
              <div class="icon is-small is-left">
                <i class="fas fa-user-tie" v-if="!usersLoading"></i>
                <i class="fas fa-spinner fa-pulse" v-else></i>
              </div>
              <p class="help">
                The user ID of the backend.
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

          <div class="field">
            <label class="label" @click="showOptions = !showOptions">
              <span class="icon-text">
                <span class="icon">
                  <i v-if="showOptions" class="fas fa-arrow-up"></i>
                  <i v-else class="fas fa-arrow-down"></i>
                </span>
                <span>Optional options</span>
              </span>
            </label>
            <div class="columns is-multiline is-mobile" v-if="showOptions && backend.options">
              <template v-for="(val, key) in backend.options" :key="'bo-'+key">
                <div class="column is-5">
                  <input type="text" class="input" :value="key" readonly disabled>
                </div>
                <div class="column is-6">
                  <input type="text" class="input" v-model="backend.options[key]">
                </div>
                <div class="column is-1">
                  <button class="button is-danger" @click.prevent="removeOption(key)">
                    <span class="icon is-small">
                      <i class="fas fa-trash"></i>
                    </span>
                  </button>
                </div>
              </template>
            </div>
          </div>

          <div class="field has-text-right">
            <div class="control">
              <button class="button is-primary" type="submit">
                <span class="icon is-small">
                  <i class="fas fa-save"></i>
                </span>
                <span>Save Settings</span>
              </button>
            </div>
          </div>

        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import {notification} from "~/utils/index.js";

const id = useRoute().params.backend
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

useHead({title: 'Backends - Edit: ' + id})

const loadContent = async () => {
  const content = await request(`/backend/${id}`)
  backend.value = await content.json()

  await getUsers()

  isLoading.value = false
}

const saveContent = async () => {
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

  notification('success', 'Information', `Backend settings saved successfully.`)

}

const removeOption = async (key) => {
  if (!confirm(`Are you sure you want to remove this option [${key}]?`)) {
    return
  }

  delete backend.value.options[key]

  const response = await request(`/backend/${id}/option/${key}`, {method: 'DELETE'})
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
  };

  if (backend.value.options && backend.value.options.ADMIN_TOKEN) {
    data.options = {
      ADMIN_TOKEN: backend.value.options.ADMIN_TOKEN
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

onMounted(async () => await loadContent())

</script>