<template>
  <div class="columns is-multiline">
    <div class="column is-12 mt-2">
      <div class="card">
        <header class="card-header">
          <p class="card-header-title">
            Configure API Connection
          </p>
          <span class="card-header-icon">
            <span class="icon"><i class="fas fa-cog"/></span>
          </span>
        </header>
        <div class="card-content">
          <form @submit.prevent="testApi">
            <div class="field">
              <label class="label" for="api_token">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-key"/></span>
                  <span>API Token</span>
                </span>
              </label>
              <div class="field-body">
                <div class="field">
                  <div class="field has-addons">
                    <div class="control is-expanded">
                      <input class="input" id="api_token" v-model="api_token" required placeholder="API Token..."
                             :type="false === exposeToken ? 'password' : 'text'">
                    </div>
                    <div class="control">
                      <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
                              v-tooltip="'Show/Hide token'">
                        <span class="icon" v-if="!exposeToken"><i class="fas fa-eye"/></span>
                        <span class="icon" v-else><i class="fas fa-eye-slash"/></span>
                      </button>
                    </div>
                  </div>
                  <p class="help">
                    You can obtain the <code>API TOKEN</code> by using the <code>system:apikey</code> command or by
                    viewing the <code>/config/.env</code> inside <code>WS_DATA_PATH</code> variable and looking for
                    the
                    <code>WS_API_KEY=</code> key.
                  </p>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="label" for="api_url">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-link"/></span>
                  <span>API URL</span>
                </span>
              </label>
              <div class="field-body">
                <div class="field">
                  <div class="control">
                    <input class="input" id="api_url" type="url" v-model="api_url" required
                           placeholder="API URL... http://localhost:8081">
                    <p class="help">
                      Use <a href="javascript:void(0)" @click="setOrigin">current page URL</a>.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="label" for="api_path">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-folder"/></span>
                  <span>API Path</span>
                </span>
              </label>
              <div class="field-body">
                <div class="field">
                  <div class="control">
                    <input class="input" id="api_path" type="text" v-model="api_path" required
                           placeholder="API Path... /v1/api">
                    <p class="help">
                      Use <a href="javascript:void(0)" @click="api_path = '/v1/api'">Set default API Path</a>.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div class="field has-text-right">
              <div class="control">
                <button type="submit" class="button is-primary" :disabled="!api_url || !api_token">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-save"/></span>
                    <span>Save</span>
                  </span>
                </button>
              </div>
              <p class="help has-text-left">
                <span class="icon-text">
                  <span class="icon has-text-danger"><i class="fas fa-info"/></span>
                  <span>These settings are stored locally in your browser. You need to re-add them if you access
                    the
                    <code>WebUI</code> from different browser.</span>
                </span>
              </p>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="column is-12 mt-2">
      <Message title="Information" message_class="has-background-info-90 has-text-dark" icon="fas fa-info-circle">
        <p>
          It's possible to automatically setup the API connection for this client and <strong
            class="has-text-danger">ALL VISITORS</strong> by setting the following environment variable
          <code>WS_API_AUTO=true</code>
          in <code>/config/.env</code> file. Understand that this option <strong class="has-text-danger">PUBLICLY
          EXPOSES YOUR API TOKEN</strong> to <u>ALL VISITORS</u>. Anyone who is able to reach this page will be
          granted access to your <code>WatchState API</code> which exposes your other media backends data including
          their secrets. <strong>this option is great security risk and SHOULD NEVER be used if
          <code>WatchState</code> is exposed to the internet.</strong>
        </p>

        <p>Please visit
          <span class="icon">
            <i class="fab fa-github"/>
          </span>
          <NuxtLink target="_blank" to="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#ws_api_auto">
            This link
          </NuxtLink>
          . to learn more, this environment variable is important enough to have its own section entry in the FAQ.
        </p>
      </Message>
    </div>
  </div>

</template>

<script setup>
import {defineEmits, ref} from 'vue'
import {useStorage} from '@vueuse/core'
import {notification} from '~/utils/index'
import awaiter from '~/utils/awaiter'

const emitter = defineEmits(['update'])

const real_api_user = useStorage('api_user', 'main')
const real_api_url = useStorage('api_url', window.location.origin)
const real_api_path = useStorage('api_path', '/v1/api')
const real_api_token = useStorage('api_token', '')

const api_url = ref(toRaw(real_api_url.value))
const api_path = ref(toRaw(real_api_path.value))
const api_user = ref(toRaw(real_api_user.value))
const api_token = ref(toRaw(real_api_token.value))

const exposeToken = ref(false)

const testApi = async () => {
  try {
    const response = await fetch(`${api_url.value}${api_path.value}/system/version`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${api_token.value}`
      }
    })

    const json = await response.json()

    if (json.error) {
      notification('error', 'API Connection', `Error ${json.error.code} - ${json.error.message}`)
      return
    }

    if (200 === response.status) {
      real_api_url.value = api_url.value
      real_api_user.value = api_user.value
      real_api_path.value = api_path.value
      real_api_token.value = api_token.value
    }

    const message = 200 === response.status ? `Status: OK` : `Status: ${response.status} - ${response.statusText}`;
    notification('success', 'API Connection', `${response.status}: ${message}`)
    if (200 === response.status) {
      await awaiter(() => '' !== real_api_token.value)
      emitter('update', {version: json.version})
    }
  } catch (e) {
    notification('error', 'API Connection', `Request error. ${e.message}`)
  }
}

onMounted(async () => {
  if ('' === api_token.value) {
    await autoConfig()
  }
})

const autoConfig = async () => {
  try {
    const response = await fetch(`${api_url.value}${api_path.value}/system/auto`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json'
      },
      body: JSON.stringify({'origin': window.location.origin})
    })

    const json = await response.json()

    if (200 !== response.status) {
      return;
    }

    if (!api_url.value) {
      api_url.value = json.url
    }

    if (!api_path.value) {
      api_path.value = json.path
    }

    if (!api_token.value) {
      api_token.value = json.token
    }

    await testApi();
  } catch (e) {
  }
}

const setOrigin = () => api_url.value = window.location.origin;
</script>
