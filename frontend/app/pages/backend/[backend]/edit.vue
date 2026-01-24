<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server"/>&nbsp;</span>
          <NuxtLink to="/backends">Backends</NuxtLink>
          -
          <NuxtLink :to="`/backend/${id}`">{{ id }}</NuxtLink>
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
                                                :class="{ 'fa-spinner fa-spin': serversLoading, 'fa-refresh': !serversLoading }"/></span>
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
                                  to="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/">
                          Visit This article
                        </NuxtLink>
                        for more information.
                      </template>
                      <template v-else>
                        You can generate a new API key from <strong>Dashboard > Settings > API Keys</strong>.
                      </template>
                    </p>
                  </div>
                </div>

                <div class="control" v-if="'plex' === backend.type">
                  <button type="button" class="button is-warning" v-if="!plex_oauth"
                          :disabled="plex_oauth_loading" @click="generate_plex_auth_request">
                    <span class="icon-text">
                      <template v-if="plex_oauth_loading">
                        <span class="icon"><i class="fas fa-spinner fa-pulse"/></span>
                        <span>Generating link</span>
                      </template>
                      <template v-else>
                        <span class="icon"><i class="fas fa-external-link-alt"/></span>
                        <span>Re-authenticate with plex.tv</span>
                      </template>
                    </span>
                  </button>

                  <template v-if="plex_oauth_url">
                    <div class="field is-grouped">
                      <div class="control">
                        <NuxtLink @click="() => plex_get_token()" type="button" :disabled="plex_oauth_loading">
                          <span class="icon-text">
                            <span class="icon"><i class="fas"
                                                  :class="{ 'fa-check-double': !plex_oauth_loading, 'fa-spinner fa-pulse': plex_oauth_loading }"/></span>
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
                                              :class="{ 'fa-spinner fa-spin': uuidLoading, 'fa-refresh': !uuidLoading }"/></span>
                        <span class="is-hidden-mobile">Reload</span>
                      </button>
                    </div>
                  </div>
                </div>
                <p class="help">
                  <span v-if="'plex' === backend.type">
                    The backend unique ID is random string generated on server setup, In Plex case it used to inquiry
                    about the users associated with the server to generate limited <strong>X-Plex-Token</strong> for
                    them. It used by webhooks as a filter to match the backend. in-case you are member of multiple
                    servers.
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
                      <button class="button is-primary" type="button" :disabled="usersLoading"
                              @click="() => getUsers(false, true)">
                        <span class="icon"><i class="fa"
                                              :class="{ 'fa-spinner fa-spin': usersLoading, 'fa-refresh': !usersLoading }"/></span>
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
                               @input="(e: Event) => option_set(_option, (e.target as HTMLInputElement)?.value || '')"
                               required>
                      </div>
                      <div class="column is-1">
                        <button type="button" class="button is-danger" @click.prevent="removeOption(_option)">
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
                      <button type="button" class="button is-primary" @click.prevent="addOption">
                        <span class="icon"><i class="fas fa-add"/></span>
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

<script setup lang="ts">
import '~/assets/css/bulma-switch.css'
import {computed, nextTick, onMounted, ref, toRaw, watch} from 'vue'
import {navigateTo, useHead, useRoute} from '#app'
import {useStorage} from '@vueuse/core'
import {notification, parse_api_response, request} from '~/utils'
import Message from '~/components/Message.vue'
import type {
  Backend,
  BackendEditUser,
  BackendServer,
  BackendSpecOption,
  BackendUuidResponse,
  GenericResponse,
  JsonObject,
  JsonValue,
  PlexOAuthData,
  PlexOAuthTokenResponse
} from '~/types'

const id = ref<string>(useRoute().params.backend as string)
const redirect = ref<string>((useRoute().query?.redirect as string) ?? `/backend/${id.value}`)

const backend = ref<Backend>({
  name: '',
  type: '',
  url: '',
  token: '',
  uuid: '',
  user: '',
  import: {enabled: false},
  export: {enabled: false},
  options: {}
})

const showOptions = ref<boolean>(false)
const isLoading = ref<boolean>(true)
const users = ref<Array<BackendEditUser>>([])
const supported = ref<Array<string>>([])
const usersLoading = ref<boolean>(false)
const uuidLoading = ref<boolean>(false)
const optionsList = ref<Array<BackendSpecOption>>([])
const selectedOption = ref<string>('')
const newOptions = ref<Record<string, boolean>>({})
const exposeToken = ref<boolean>(false)
const servers = ref<Array<BackendServer>>([])
const serversLoading = ref<boolean>(false)
const isLimitedToken = computed(() => Boolean(backend.value.options?.is_limited_token))
const api_user = useStorage('api_user', 'main')
const optionsVersion = ref<number>(0)
type BackendOptionMap = Record<string, JsonValue>

const selectedOptionHelp = computed((): string => {
  const option = optionsList.value.find(v => selectedOption.value === v.key)
  return option ? option.description : ''
})

useHead({title: 'Backends - Edit: ' + id.value})

const loadContent = async (): Promise<void> => {
  const supportedResponse = await request('/system/supported')
  const supportedData = await parse_api_response<Array<string>>(supportedResponse)

  if ('error' in supportedData) {
    notification('error', 'Error', `Failed to load supported backends: ${supportedData.error.message}`)
    return
  }

  supported.value = supportedData

  const contentResponse = await request(`/backend/${id.value}`)
  const json = await parse_api_response<Backend>(contentResponse)

  if ('error' in json) {
    notification('error', 'Error', `Failed to load backend: ${json.error.message}`)
    return
  }

  if (!json?.options || typeof json.options !== 'object') {
    json.options = {}
  }

  backend.value = json

  if ('plex' === backend.value.type) {
    await getServers()
  }

  await getUsers()

  isLoading.value = false
}

const saveContent = async (): Promise<void> => {
  const json_text = toRaw(backend.value) as Backend & {options: JsonObject}

  const flat: Record<string, JsonValue> = {}
  flatOptionPaths.value.forEach((path: string) => {
    flat[path] = option_get(path) ?? null
  })

  if (0 < Object.keys(flat).length) {
    json_text.options = flat
  }

  try {
    const response = await request(`/backend/${id.value}`, {
      method: 'PUT',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(json_text)
    })

    const json = await parse_api_response<GenericResponse>(response)
    if (200 !== response.status) {
      if ('error' in json) {
        notification('error', 'Error', `Failed to save backend settings. (${json.error.code}: ${json.error.message}).`)
      } else {
        notification('error', 'Error', 'Failed to save backend settings.')
      }
      return
    }

    notification('success', 'Success', `Successfully updated '${id.value}' settings.`)
    const to = !redirect.value.startsWith('/') ? `/backend/${id.value}` : redirect.value
    await navigateTo({path: to})
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : String(e)
    notification('error', 'Error', `Request error. ${errorMessage}`)
  }
}

const removeOption = async (key: string): Promise<void> => {
  if (newOptions.value[key]) {
    const {[key]: _removed, ...rest} = newOptions.value
    newOptions.value = rest
    const options = backend.value.options as BackendOptionMap
    const {[key]: _optionRemoved, ...optionsRest} = options
    backend.value.options = optionsRest
    return
  }

  const {status: confirmStatus} = await useDialog().confirmDialog({
    title: 'Option removal',
    message: `Delete the option '${key}'? This action cannot be undone.`,
    confirmColor: 'is-danger',
  })

  if (true !== confirmStatus) {
    return
  }

  const response = await request(`/backend/${id.value}/option/options.${key}`, {method: 'DELETE'})

  if (!response.ok) {
    const json = await parse_api_response<GenericResponse>(response)
    if ('error' in json) {
      notification('error', 'Error', `Failed to remove the option. (${json.error.code}: ${json.error.message}).`)
    } else {
      notification('error', 'Error', 'Failed to remove the option.')
    }
    return
  }

  notification('success', 'Information', `Option [${key}] removed successfully.`)
  const options = backend.value.options as BackendOptionMap
  const {[key]: _optionRemoved, ...optionsRest} = options
  backend.value.options = optionsRest
}

const addOption = async (): Promise<void> => {
  if (!selectedOption.value) {
    notification('error', 'Error', 'Please select an option to add.')
    return
  }

  backend.value.options = backend.value.options || {}
  option_set(selectedOption.value, '')
  newOptions.value[selectedOption.value] = true
  selectedOption.value = ''
}

const getUUid = async (): Promise<void> => {
  const required_values: Array<keyof Backend> = ['type', 'token', 'url']

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

  const json = await parse_api_response<BackendUuidResponse>(response)
  uuidLoading.value = false

  if (!response.ok) {
    notification('error', 'Error', 'Failed to get the UUID from the backend.')
    return
  }

  if ('error' in json) {
    notification('error', 'Error', `Failed to get UUID: ${json.error.message}`)
    return
  }

  backend.value.uuid = json.identifier
}

const getUsers = async (showAlert: boolean = true, forceReload: boolean = false): Promise<void> => {
  const required_values: Array<keyof Backend> = ['type', 'token', 'url', 'uuid']

  if (required_values.some(v => !backend.value[v])) {
    if (showAlert) {
      notification('error', 'Error', `Please fill all the required fields. ${required_values.join(', ')}.`)
    }
    return
  }

  usersLoading.value = true

  const data: JsonObject & {options: JsonObject} = {
    token: backend.value.token,
    url: backend.value.url,
    uuid: backend.value.uuid,
    user: backend.value.user,
    options: {},
  }

  const requiredOptions = ['ADMIN_TOKEN', 'plex_guest_user', 'PLEX_USER_PIN', 'is_limited_token']
  requiredOptions.forEach(v => {
    const optionsRecord = backend.value.options as BackendOptionMap
    if (backend.value.options && optionsRecord[v] !== undefined) {
      data.options[v] = optionsRecord[v]
    }
  })

  const query = new URLSearchParams()
  query.append('tokens', '1')
  if (forceReload) {
    query.append('no_cache', '1')
  }
  const response = await request(`/backends/users/${backend.value.type}?${query.toString()}`, {
    method: 'POST',
    body: JSON.stringify(data)
  })

  const json = await parse_api_response<Array<BackendEditUser>>(response)

  usersLoading.value = false

  if (200 !== response.status) {
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    } else {
      notification('error', 'Error', 'Failed to load users')
    }
    return
  }

  if ('error' in json) {
    notification('error', 'Error', `Failed to load users: ${json.error.message}`)
    return
  }

  users.value = json
}

// -- if users updated we need to reset the token in-case the plex auth changed
watch(() => users.value, newUsers => {
  if ('plex' !== backend.value.type) {
    return
  }

  if (!newUsers || 0 === newUsers.length) {
    return
  }

  if (!backend.value.user) {
    return
  }

  // Find the currently selected user in the updated users list
  const selectedUser = newUsers.find(user => user.id === backend.value.user)

  if (!selectedUser) {
    notification('warning', 'Warning', 'Selected user not found in updated user list')
    return
  }

  // Check if the user has a token
  if (!selectedUser.token) {
    notification('error', 'Error', 'Selected user does not have a valid token')
    return
  }

  // Only update if the token has actually changed
  if (selectedUser.token !== backend.value.token) {
    backend.value.token = selectedUser.token
    notification('info', 'Information', `Token updated for user: ${selectedUser.name}`)
  }

  // Update user-specific options
  if (selectedUser.guest) {
    backend.value.options.plex_external_user = true
  } else {
    if (backend.value.options?.plex_external_user) {
      delete backend.value.options.plex_external_user
    }
  }

  backend.value.options.plex_user_name = selectedUser.name
  backend.value.options.plex_user_uuid = selectedUser.uuid
}, {deep: true})

watch(showOptions, async (value: boolean) => {
  if (!value) {
    return
  }
  if (0 < optionsList.value.length) {
    return
  }

  const response = await request('/backends/spec')
  const json = await parse_api_response<Array<BackendSpecOption>>(response)

  if ('error' in json) {
    notification('error', 'Error', `Failed to load options: ${json.error.message}`)
    return
  }

  json.forEach((option: BackendSpecOption) => {
    if (false === option.key.startsWith('options.')) {
      return
    }
    optionsList.value.push({...option, key: option.key.replace('options.', '')})
  })
})

const filteredOptions = (options: Array<BackendSpecOption> | null): Array<BackendSpecOption> => {
  if (!options) {
    return []
  }
  const optionsRecord = backend.value.options as BackendOptionMap
  return options.filter((option: BackendSpecOption) =>
    optionsRecord[option.key] === undefined && !newOptions.value[option.key]
  )
}

const getServers = async (): Promise<void> => {
  if ('plex' !== backend.value.type) {
    return
  }

  if (!backend.value.token) {
    notification('error', 'Error', 'Token is required to get list of servers.')
    return
  }

  serversLoading.value = true

  const data: JsonObject = {
    token: backend.value.token,
    url: window.location.origin,
  }

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

  const json = await parse_api_response<Array<BackendServer>>(response)

  if (200 !== response.status) {
    if ('error' in json) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    } else {
      notification('error', 'Error', 'Failed to load servers')
    }
    return
  }

  if ('error' in json) {
    notification('error', 'Error', `Failed to load servers: ${json.error.message}`)
    return
  }

  servers.value = json
}

const updateIdentifier = async (): Promise<void> => {
  const server = servers.value.find(s => backend.value.url === s.uri)
  if (server) {
    backend.value.uuid = server.identifier
    await getUsers()
  }
}

watch(() => backend.value.user, async () => {
  if (0 === users.value.length || 'plex' !== backend.value.type) {
    return
  }

  const selectedUser = users.value.find(u => u.id === backend.value.user)

  if (!selectedUser) {
    notification('warning', 'Warning', 'Selected user not found')
    return
  }

  // Update user-specific options
  if (selectedUser.guest) {
    backend.value.options.plex_external_user = true
  } else {
    if (backend.value.options?.plex_external_user) {
      delete backend.value.options.plex_external_user
    }
  }

  backend.value.options.plex_user_name = selectedUser.name
  backend.value.options.plex_user_uuid = selectedUser.uuid

  if (!selectedUser.token) {
    notification('error', 'Error', 'User token not found')
    return
  }

  if (selectedUser.token !== backend.value.token) {
    backend.value.token = selectedUser.token
    notification('info', 'Information', `Token updated for user: ${selectedUser.name}`)
  }
})

const flattenOptions = (obj: JsonObject, prefix: string = ''): Array<string> => {
  const out: Array<string> = []

  for (const [key, val] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key

    if (Array.isArray(val)) {
      if (0 === val.length) {
        continue
      }
      out.push(path)
      continue
    }

    if (val !== null && typeof val === 'object') {
      if (0 === Object.keys(val).length) {
        continue
      }
      out.push(...flattenOptions(val, path))
      continue
    }

    out.push(path)
  }

  return out
}

const flatOptionPaths = computed(() => {
  if (0 <= optionsVersion.value) {
    return flattenOptions(backend.value.options as unknown as JsonObject)
  }
  return []
})

const option_get = (path: string): JsonValue | undefined => {
  return path.split('.').reduce((obj: JsonValue | JsonObject | undefined, key: string) => {
    if (obj === undefined || obj === null) {
      return undefined
    }

    if (typeof obj !== 'object' || Array.isArray(obj)) {
      return undefined
    }

    return (obj as JsonObject)[key]
  }, backend.value.options as unknown as JsonObject)
}

const option_set = (path: string, value: JsonValue): void => {
  const keys = path.split('.')
  const last = keys.pop()
  let target: JsonObject = backend.value.options as unknown as JsonObject
  for (const k of keys) {
    if (target[k] == null || typeof target[k] !== 'object' || Array.isArray(target[k])) {
      target[k] = {}
    }
    target = target[k] as JsonObject
  }

  if (last) {
    target[last] = value
  }
  optionsVersion.value++
}

const option_describe = (path: string): string => {
  const item = optionsList.value.find((v) => path === v.key)
  return item ? item.description : ''
}

onMounted(async () => await loadContent())

const plex_oauth = ref<PlexOAuthData | null>(null)
const plex_oauth_loading = ref<boolean>(false)
const plex_timeout = ref<NodeJS.Timeout | null>(null)
const plex_window = ref<Window | null>(null)

const generate_plex_auth_request = async (): Promise<void> => {
  if (plex_oauth_loading.value) {
    return
  }

  plex_oauth_loading.value = true

  try {
    const response = await request('/backends/plex/generate', {method: 'POST'})
    const json = await parse_api_response<PlexOAuthData>(response)
    if (200 !== response.status) {
      if ('error' in json) {
        notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      } else {
        notification('error', 'Error', 'Failed to generate Plex auth request')
      }
      return
    }

    if ('error' in json) {
      notification('error', 'Error', `Failed to generate auth: ${json.error.message}`)
      return
    }

    plex_oauth.value = json

    await nextTick()

    try {
      const width = 500
      const height = 600

      const features = [
        `width=${width}`,
        `height=${height}`,
        `top=${(window.screen.height / 2) - (height / 2)}`,
        `left=${(window.screen.width / 2) - (width / 2)}`,
        'resizable=yes',
        'scrollbars=yes',
      ].join(',')

      plex_window.value = window.open(plex_oauth_url.value, 'plex_auth', features)
      plex_timeout.value = setTimeout(() => plex_get_token(false), 3000)
      await nextTick()

      if (!plex_window.value) {
        notification('error', 'Error', 'Popup blocked. Please allow popups for this site.')
      }
    } catch (e) {
      console.error(e)
      notification('error', 'Error', 'Failed to open popup. Please manually click the link.')
    }
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : String(e)
    notification('error', 'Error', `Request error. ${errorMessage}`)
  } finally {
    plex_oauth_loading.value = false
  }
}

const plex_oauth_url = computed((): string | undefined => {
  if (!plex_oauth.value) {
    return
  }
  const url = new URL('https://app.plex.tv/auth')
  const params = new URLSearchParams()
  params.set('code', plex_oauth.value.code)
  params.set('clientID', plex_oauth.value['X-Plex-Client-Identifier'])
  params.set('context[device][product]', plex_oauth.value['X-Plex-Product'])
  url.hash = '?' + params.toString()
  return url.toString()
})

const plex_get_token = async (notify: boolean = true): Promise<void> => {
  if (plex_oauth_loading.value) {
    return
  }

  if (!plex_oauth.value) {
    return
  }

  plex_oauth_loading.value = true

  try {
    if (plex_timeout.value) {
      clearTimeout(plex_timeout.value)
      plex_timeout.value = null
    }
    const plexOauth = plex_oauth.value
    const response = await request('/backends/plex/check', {
      method: 'POST',
      body: JSON.stringify({id: plexOauth.id, code: plexOauth.code})
    })

    const json = await parse_api_response<PlexOAuthTokenResponse>(response)

    if (200 !== response.status) {
      if ('error' in json) {
        notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      } else {
        notification('error', 'Error', 'Failed to check auth status')
      }
      return
    }

    if ('error' in json) {
      notification('error', 'Error', `Auth check failed: ${json.error.message}`)
      return
    }

    if (json?.authToken) {
      backend.value.token = json.authToken
      backend.value.options.ADMIN_TOKEN = json.authToken
      await nextTick()
      plex_oauth.value = null
      notification('success', 'Success', 'Successfully re-authenticated with plex.tv.')
      if (plex_window.value) {
        try {
          plex_window.value.close()
          plex_window.value = null
        } catch {
          // ignore
        }
      }
      await getUsers(true, true)
    } else {
      if (true === notify) {
        notification('warning', 'Warning', 'Not authenticated yet. Login via the given link to authorize WatchState.')
      }
      await nextTick()
      plex_timeout.value = setTimeout(() => plex_get_token(false), 3000)
    }
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : String(e)
    notification('error', 'Error', `Request error. ${errorMessage}`)
  } finally {
    plex_oauth_loading.value = false
  }
}
</script>
