<template>
  <Message title="Important" message_class="has-background-warning-80 has-text-dark" icon="fas fa-info-circle">
    <ul>
      <li>
        If you are adding new backend that is fresh and doesn't have your current watch state, you should turn off
        import and enable only metadata import at the start to prevent overriding your current play state. Visit the
        following guide
        <NuxtLink to="/help/one-way-sync">
          <span class="icon"><i class="fas fa-circle-question"/></span> One-way sync
        </NuxtLink>
        to learn more.
      </li>
      <li v-if="api_user === 'main'">
        Do not add sub-users backends manually, after finishing the main user backends setup. Visit
        <NuxtLink target="_blank" to="/tools/sub_users">
          <span class="icon"><i class="fas fa-tools"/></span> Tools >
          <span class="icon"><i class="fas fa-users"/></span> Sub-users
        </NuxtLink>
        page to create their own user and backends automatically.
      </li>
    </ul>
  </Message>

  <form id="backend_add_form" @submit.prevent="stage < 4 ? changeStep() : addBackend()">
    <div class="card">
      <div class="card-header">
        <p class="card-header-title">Add backend to '<u class="has-text-danger">{{ api_user }}</u>' user config.</p>
      </div>

      <div class="card-content">
        <div class="field" v-if="error">
          <Message title="Backend Error" id="backend_error" message_class="has-background-danger-80 has-text-dark"
                   icon="fas fa-exclamation-triangle" useClose @close="error = null">
            <p>{{ error }}</p>
          </Message>
        </div>
        <template v-if="stage >= 0">
          <div class="field">
            <label class="label">Local User</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select class="is-capitalized" disabled>
                  <option v-text="api_user"/>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-users"/>
              </div>
            </div>
            <p class="help">
              The local user which this backend will be associated with. You can change this user via the
              <span class="icon"><i class="fas fa-users"/></span> users icon on top right of the page.
            </p>
          </div>

          <div class="field">
            <label class="label">Type</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select v-model="backend.type" class="is-capitalized" required :disabled="stage > 0">
                  <option v-for="type in supported" :key="'type-' + type" :value="type">
                    {{ type }}
                  </option>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-server"/>
              </div>
            </div>
            <p class="help">The backend type.</p>
          </div>

          <div class="field">
            <label class="label">Name</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.name" required :disabled="stage > 0">
              <div class="icon is-left">
                <i class="fas fa-id-badge"/>
              </div>
            </div>
            <p class="help">
              Choose a unique name for this backend. <strong>You CANNOT change it later</strong>.
              Backend name must be in <strong>lower case a-z, 0-9 and _</strong> and cannot start with number.
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
                    <input class="input" v-model="backend.token" required :disabled="stage > 1"
                           :type="false === exposeToken ? 'password' : 'text'">
                    <div class="icon is-left">
                      <i class="fas fa-key"/>
                    </div>
                  </div>
                  <div class="control">
                    <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
                            v-tooltip="'Toggle token'">
                      <span class="icon" v-if="!exposeToken"><i class="fas fa-eye"/></span>
                      <span class="icon" v-else><i class="fas fa-eye-slash"/></span>
                    </button>
                  </div>
                </div>
                <p class="help">
                  <template v-if="'plex' === backend.type">
                    Enter the <strong>X-Plex-Token</strong>.
                    <NuxtLink target="_blank" to="https://support.plex.tv/articles/204059436"
                              v-text="'Visit This link'"/>
                    to learn how to get the token. <span class="is-bold">If you plan to add sub-users, YOU MUST use
                    admin level token.</span>
                  </template>
                  <template v-else>
                    Generate a new API Key from <strong>Dashboard > Settings > API Keys</strong>.<br>
                    <span class="icon has-text-warning"><i class="fas fa-info-circle"/></span>
                    You can use <strong>username:password</strong> as API key and we will automatically generate limited
                    token if you are unable to generate API Key. This should be used as last resort. and it's mostly
                    untested. and things might not work as expected.
                    <span class="is-bold has-text-danger">If you plan to add sub-users, YOU MUST use API KEY and not
                      username:password.</span>
                  </template>
                </p>
              </div>
            </div>

            <div class="control" v-if="'plex' === backend.type && !backend.token">
              <button type="button" class="button is-warning" v-if="Object.keys(plex_oauth).length < 1"
                      :disabled="plex_oauth_loading" @click="generate_plex_auth_request">
                <span class="icon-text">
                  <template v-if="plex_oauth_loading">
                    <span class="icon"><i class="fas fa-spinner fa-pulse"/></span>
                    <span>Generating link</span>
                  </template>
                  <template v-else>
                    <span class="icon"><i class="fas fa-external-link-alt"/></span>
                    <span>Sign-in via Plex</span>
                  </template>
                </span>
              </button>

              <template v-if="plex_oauth_url">
                <div class="field is-grouped">
                  <div class="control">
                    <NuxtLink @click="plex_get_token" type="button" :disabled="plex_oauth_loading">
                      <span class="icon-text">
                        <span class="icon"><i class="fas"
                                              :class="{'fa-check-double': !plex_oauth_loading,'fa-spinner fa-pulse': plex_oauth_loading}"/></span>
                        <span>Check auth request.</span>
                      </span>
                    </NuxtLink>
                  </div>
                  <div class="control">
                    <NuxtLink :href="plex_oauth_url" target="_blank">
                      <span class="icon-text">
                        <span class="icon"><i class="fas fa-external-link-alt"/></span>
                        <span>Open Plex Auth Link</span>
                      </span>
                    </NuxtLink>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <div class="field" v-if="'plex' === backend.type">
            <label class="label">User PIN</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.options.PLEX_USER_PIN" :disabled="stage > 1">
              <div class="icon is-left"><i class="fas fa-key"/></div>
            </div>
            <p class="help">
              If the user you are going to select has <strong>PIN</strong> enabled, you need to enter the pin here.
              Otherwise it will fail to authenticate.
            </p>
          </div>
        </template>

        <template v-if="stage >= 1">
          <div class="field" v-if="'plex' !== backend.type">
            <label class="label">URL</label>
            <div class="control has-icons-left">
              <input class="input" type="text" v-model="backend.url" required :disabled="stage > 1">
              <div class="icon is-left"><i class="fas fa-link"/></div>
            </div>
            <p class="help">
              Enter the URL of the backend. For example <strong>http://192.168.8.200:8096</strong>.
            </p>
          </div>

          <template v-else>
            <div class="field">
              <label class="label">Plex Server URL</label>
              <div class="field-body">
                <div class="field">
                  <div class="field has-addons">
                    <div class="control is-expanded has-icons-left">
                      <div class="select is-fullwidth">
                        <select v-model="backend.url" class="is-capital" @change="stage = 1; updateIdentifier()"
                                required
                                :disabled="stage > 1">
                          <option value="" disabled>Select Server URL</option>
                          <option v-for="server in servers" :key="'server-' + server.uuid" :value="server.uri">
                            {{ server.name }} - {{ server.uri }}
                          </option>
                        </select>
                      </div>
                      <div class="icon is-left">
                        <i class="fas fa-link" v-if="!serversLoading"/>
                        <i class="fas fa-spinner fa-pulse" v-else/>
                      </div>
                    </div>
                    <div class="control">
                      <button class="button is-primary" type="button" :disabled="serversLoading || stage > 2"
                              @click="getServers">
                        <span class="icon"><i class="fa"
                                              :class="{'fa-spinner fa-spin': serversLoading,'fa-refresh' : !serversLoading }"/></span>
                        <span class="is-hidden-mobile">Reload</span>
                      </button>
                    </div>
                  </div>
                  <p class="help">
                    Try to use non <strong>.plex.direct</strong> urls if possible, as they are often have problems
                    working in docker. If you use custom domain for your plex server and it's not showing in the list,
                    you can add it via Plex settings page. <strong>Plex > Settings > Network > Custom server access
                    URLs:</strong>. For more information
                    <NuxtLink target="_blank"
                              to="https://support.plex.tv/articles/200430283-network/#Custom-server-access-URLs"
                              v-text="'Visit this link'"/>
                    .
                  </p>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="label" for="backend_ownership">Are you invited guest to this backend?</label>
              <div class="control">
                <input id="backend_ownership" type="checkbox" class="switch is-success"
                       v-model="backend.options.plex_guest_user" :disabled="stage > 2">
                <label for="backend_ownership" class="is-unselectable">
                  {{ backend.options?.plex_guest_user ? 'Yes' : 'No' }}
                </label>
              </div>
              <p class="help">
                This stops WatchState from attempting to generate access-tokens for different users.
              </p>
            </div>
          </template>
        </template>

        <div class="field" v-if="stage >= 3">
          <label class="label">User</label>
          <div class="field-body">
            <div class="field">
              <div class="field has-addons">
                <div class="control is-expanded has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="backend.user" class="is-capitalized" :disabled="stage > 3">
                      <option value="" disabled>Select User</option>
                      <option v-for="user in users" :key="'uid-' + user.id" :value="user.id">
                        {{ user.name }}
                        <template v-if="user?.token_error"> - {{ user.token_error }}</template>
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-user-tie" v-if="!usersLoading"/>
                    <i class="fas fa-spinner fa-pulse" v-else/>
                  </div>
                </div>
                <div class="control">
                  <button class="button is-primary" type="button" :disabled="usersLoading || stage > 3"
                          @click="getUsers">
                    <span class="icon"><i class="fa"
                                          :class="{'fa-spinner fa-spin': usersLoading,'fa-refresh' : !usersLoading }"/></span>
                    <span class="is-hidden-mobile">Reload</span>
                  </button>
                </div>
              </div>
              <p class="help">Which user we should associate this backend with?</p>
            </div>
          </div>
        </div>

        <template v-if="stage >= 4">
          <div class="field" v-if="backend.import">
            <label class="label" for="backend_import">Import play and progress updates from this backend?</label>
            <div class="control">
              <input id="backend_import" type="checkbox" class="switch is-success" v-model="backend.import.enabled">
              <label for="backend_import" class="is-unselectable">{{ backend.import.enabled ? 'Yes' : 'No' }}</label>
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
                {{ backend.options?.IMPORT_METADATA_ONLY ? 'Yes' : 'No' }}
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
              <label for="backend_export" class="is-unselectable">{{ backend.export.enabled ? 'Yes' : 'No' }}</label>
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
              <label for="webhook_match_uuid" class="is-unselectable">
                {{ backend.webhook.match.uuid ? 'Yes' : 'No' }}
              </label>
            </div>
            <p class="help">
              Check webhook payload for backend unique id. if it does not match, the payload will be ignored.
            </p>
          </div>

          <hr>

          <div class="field">
            <h1 class="title is-4">One Time Operations</h1>
          </div>

          <div class="field">
            <label class="label has-text-danger" for="backup_data">
              Create backup for this backend data?
            </label>
            <div class="control">
              <input id="backup_data" type="checkbox" class="switch is-success" v-model="backup_data">
              <label for="backup_data" class="is-unselectable">{{ backup_data ? 'Yes' : 'No' }}</label>
            </div>
            <p class="help">
              This will run a one time backup for the backend data.
            </p>
          </div>

          <div class="field" v-if="backends.length < 1">
            <label class="label" for="force_import">
              Force one time import from this backend?
            </label>
            <div class="control">
              <input id="force_import" type="checkbox" class="switch is-success" v-model="force_import">
              <label for="force_import" class="is-unselectable">{{ force_import ? 'Yes' : 'No' }}</label>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info-circle"/></span>
              Run a one time import from this backend after adding it.
            </p>
          </div>

          <div class="field" v-if="backends.length > 0">
            <label class="label has-text-danger" for="force_export">
              Force Export local data to this backend?
            </label>
            <div class="control">
              <input id="force_export" type="checkbox" class="switch is-success" v-model="force_export">
              <label for="force_export" class="is-unselectable">{{ force_export ? 'Yes' : 'No' }}</label>
            </div>
            <p class="help has-text-danger is-bold">
              <span class="icon"><i class="fas fa-info-circle"/></span>
              THIS OPTION WILL OVERRIDE THE BACKEND DATA with locally stored data.
            </p>
          </div>
        </template>
      </div>

      <div class="card-footer">

        <div class="card-footer-item" v-if="stage >= 1">
          <button class="button is-fullwidth is-warning" type="button" @click="stage = stage - 1">
            <span class="icon"><i class="fas fa-arrow-left"/></span>
            <span>Previous Step</span>
          </button>
        </div>

        <div class="card-footer-item" v-if="stage < maxStages">
          <button class="button is-fullwidth is-info" type="button" @click="changeStep()">
            <span class="icon"><i class="fas fa-arrow-right"/></span>
            <span>Next Step</span>
          </button>
        </div>
        <div class="card-footer-item" v-else>
          <button class="button is-fullwidth is-primary" type="submit">
            <span class="icon"><i class="fas fa-plus"/></span>
            <span>Add Backend</span>
          </button>
        </div>
      </div>
    </div>
  </form>
</template>

<script setup>
import '~/assets/css/bulma-switch.css'
import request from '~/utils/request.js'
import {awaitElement, explode, notification} from '~/utils/index.js'
import {useStorage} from "@vueuse/core";

const emit = defineEmits(['addBackend', 'backupData', 'forceExport', 'forceImport'])

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
const api_user = useStorage('api_user', 'main')
const users = ref([])
const supported = ref([])
const servers = ref([])

const maxStages = 5
const stage = ref(0)
const usersLoading = ref(false)
const uuidLoading = ref(false)
const serversLoading = ref(false)
const exposeToken = ref(false)
const error = ref()
const backup_data = ref(true)
const force_export = ref(false)
const force_import = ref(false)

const isLimited = ref(false)
const accessTokenResponse = ref({})

const plex_oauth = ref({})
const plex_oauth_loading = ref(false)
const plex_timeout = ref(null)
const plex_window = ref(null)

const generate_plex_auth_request = async () => {
  if (plex_oauth_loading.value) {
    return
  }

  plex_oauth_loading.value = true

  try {
    const response = await request('/backends/plex/generate', {method: 'POST'})
    const json = await parse_api_response(response)
    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }
    plex_oauth.value = json

    await nextTick();

    try {
      const width = 500;
      const height = 600;

      const features = [
        `width=${width}`,
        `height=${height}`,
        `top=${(window.screen.height / 2) - (height / 2)}`,
        `left=${(window.screen.width / 2) - (width / 2)}`,
        'resizable=yes',
        'scrollbars=yes',
      ].join(',');

      plex_window.value = window.open(plex_oauth_url.value, 'plex_auth', features);
      plex_timeout.value = setTimeout(() => plex_get_token(false), 3000)
      await nextTick();

      if (!plex_window.value) {
        n_proxy('error', 'Error', 'Popup blocked. Please allow popups for this site.')
      }
    } catch (e) {
      console.error(e)
      n_proxy('error', 'Error', `Failed to open popup. Please manually click the link.`)
    }
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    plex_oauth_loading.value = false
  }
}

const plex_oauth_url = computed(() => {
  if (Object.keys(plex_oauth.value).length < 1) {
    return
  }
  const url = new URL('https://app.plex.tv/auth')
  const params = new URLSearchParams()
  params.set('code', plex_oauth.value['code'])
  params.set('clientID', plex_oauth.value['X-Plex-Client-Identifier'])
  params.set('context[device][product]', plex_oauth.value['X-Plex-Product'])
  url.hash = '?' + params.toString()
  return url.toString()
})

const plex_get_token = async (notify = true) => {
  if (plex_oauth_loading.value) {
    return
  }

  plex_oauth_loading.value = true

  try {
    if (plex_timeout.value) {
      clearTimeout(plex_timeout.value)
      plex_timeout.value = null
    }
    const response = await request('/backends/plex/check', {
      method: 'POST',
      body: JSON.stringify({
        id: plex_oauth.value.id,
        code: plex_oauth.value.code
      })
    })

    const json = await parse_api_response(response)

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    if (json?.authToken) {
      backend.value.token = json.authToken
      await nextTick();
      plex_oauth.value = {}
      notification('success', 'Success', `Plex token generated inserted successfully.`)
      if (plex_window.value) {
        try {
          plex_window.value.close()
          plex_window.value = null
        } catch (e) {
        }
      }
    } else {
      if (true === notify) {
        notification('warning', 'Warning', `Not authenticated yet. Login via the given link to authorize WatchState.`)
      }
      await nextTick();
      plex_timeout.value = setTimeout(() => plex_get_token(false), 3000)
    }
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    plex_oauth_loading.value = false
  }
}

const getUUid = async () => {
  const required_values = ['type', 'token', 'url'];

  if (true === isLimited.value || Object.keys(accessTokenResponse.value) > 0) {
    return
  }

  if (required_values.some(v => !backend.value[v])) {
    notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    return
  }

  try {
    error.value = null
    uuidLoading.value = true
    let data = {
      name: backend.value?.name,
      token: backend.value.token,
      url: backend.value.url
    }

    if (backend.value.user) {
      data.user = backend.value.user
    }

    const response = await request(`/backends/uuid/${backend.value.type}`, {
      method: 'POST',
      body: JSON.stringify(data)
    })

    const json = await response.json()

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    backend.value.uuid = json.identifier

    return backend.value.uuid
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    uuidLoading.value = false
  }
}

const getAccessToken = async () => {
  const required_values = ['type', 'token', 'url'];

  if (required_values.some(v => !backend.value[v])) {
    notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    return
  }

  if (Object.keys(accessTokenResponse.value) > 0) {
    return
  }

  const [username, password] = explode(':', backend.value.token, 2)

  if (!username || !password) {
    return
  }

  try {
    error.value = null

    const response = await request(`/backends/accesstoken/${backend.value.type}`, {
      method: 'POST',
      body: JSON.stringify({
        name: backend.value?.name,
        url: backend.value.url,
        username: username,
        password: password,
      })
    })

    const json = await response.json()

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    accessTokenResponse.value = json
    backend.value.token = json?.accesstoken
    backend.value.user = json?.user
    backend.value.uuid = json?.identifier
    users.value = [{
      id: json?.user,
      name: username
    }]

    isLimited.value = true
    return true
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
    return false
  }
}

const getUsers = async (showAlert = true) => {
  const required_values = ['type', 'token', 'url', 'uuid']

  if (required_values.some(v => !backend.value[v])) {
    if (showAlert) {
      notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    }
    return
  }

  try {
    error.value = null
    usersLoading.value = true

    let data = {
      name: backend.value?.name,
      token: backend.value.token,
      url: backend.value.url,
      uuid: backend.value.uuid,
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

    if (200 !== response.status) {
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    users.value = json

    return users.value
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    usersLoading.value = false
  }
}

onMounted(async () => {
  supported.value = await (await request('/system/supported')).json()
  backend.value.type = supported.value[0]
})

watch(stage, v => {
  if (v < 3) {
    users.value = []
    backend.value.user = ''
  }
  if (v < 1) {
    servers.value = []
    backend.value.uuid = ''
    backend.value.url = ''
  }
});

const changeStep = async () => {
  let _

  if (stage.value <= 0) {
    // -- basic validation.
    const required = ['name', 'type', 'token']
    if (required.some(v => !backend.value[v])) {
      required.forEach(v => {
        if (!backend.value[v]) {
          notification('error', 'Error', `Please fill the required field: ${v}.`)
        }
      })
      return
    }

    if (false === /^[a-z_0-9]+$/.test(backend.value.name)) {
      notification('error', 'Error', `Backend name must be in lower case a-z, 0-9 and _ only.`)
      return
    }

    if (props.backends.find(b => b.name === backend.value.name)) {
      notification('error', 'Error', `Backend with name '${backend.value.name}' already exists.`)
      return
    }

    stage.value = 1
  }

  if (stage.value <= 1) {
    if ('plex' === backend.value.type && servers.value.length < 1) {
      _ = await getServers()
      if (servers.value.length < 1) {
        stage.value = 0
        return
      }
    }

    if (!backend.value.url) {
      return
    }

    if (false === isLimited.value && backend.value.token.includes(':')) {
      _ = await getAccessToken()
      if (!accessTokenResponse.value) {
        stage.value = 0
        return
      }
    }

    if (backend.value.token.includes(':')) {
      return
    }

    stage.value = 2
  }

  if (stage.value <= 2) {
    if (!backend.value.uuid) {
      _ = await getUUid();
      if (!backend.value.uuid) {
        stage.value = 1
        return
      }
    }

    stage.value = 3
  }

  if (stage.value <= 3) {
    if (false === isLimited.value && users.value.length < 1) {
      _ = await getUsers()
      if (users.value.length < 1) {
        stage.value = 1
        return
      }
    }

    if (!backend.value.user) {
      return
    }

    stage.value = 4
  }

  if (stage.value <= 4) {
    stage.value = 5
  }
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
    if (token && token !== backend.value.token) {
      backend.value.options.ADMIN_TOKEN = backend.value.token;
      backend.value.token = token
    }
  }

  if (isLimited.value) {
    backend.value.options.is_limited_token = true
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

  if (true === Boolean(backup_data?.value ?? false)) {
    emit('backupData', backend)
  }

  if (true === Boolean(force_export?.value ?? false)) {
    emit('forceExport', backend)
  }

  if (true === Boolean(force_import?.value ?? false)) {
    emit('forceImport', backend)
  }

  emit('addBackend')

  return true
}

const getServers = async () => {
  if ('plex' !== backend.value.type) {
    return
  }

  if (!backend.value.token) {
    notification('error', 'Error', `Token is required to get list of servers.`)
    return
  }

  try {
    serversLoading.value = true

    let data = {
      name: backend.value?.name,
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
      n_proxy('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    servers.value = json

    return servers.value
  } catch (e) {
    n_proxy('error', 'Error', `Request error. ${e.message}`, e)
  } finally {
    serversLoading.value = false
  }
}

const updateIdentifier = async () => {
  backend.value.uuid = servers.value.find(s => s.uri === backend.value.url).identifier
  // if (backend.value.uuid) {
  //   await getUsers()
  // }
}

const n_proxy = (type, title, message, e = null) => {
  if ('error' === type) {
    error.value = message
  }

  if (e) {
    console.error(e)
  }

  return notification(type, title, message)
}

watch(error, v => v ? awaitElement('#backend_error', (_, e) => e.scrollIntoView({behavior: 'smooth'})) : null)
</script>
