<template>
  <div class="container">
    <nav class="navbar is-dark mb-4">
      <div class="navbar-brand pl-5">
        <NuxtLink class="navbar-item" href="/">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-home"></i></span>
            <span>Home</span>
          </span>
        </NuxtLink>

        <button class="navbar-burger burger" @click="showMenu = !showMenu">
          <span aria-hidden="true"></span>
          <span aria-hidden="true"></span>
          <span aria-hidden="true"></span>
        </button>
      </div>

      <div class="navbar-menu" :class="{'is-active':showMenu}">
        <div class="navbar-start">
          <a class="navbar-item" href="/backends">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"></i></span>
              <span>Backends</span>
            </span>
          </a>
          <a class="navbar-item" href="/history">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-history"></i></span>
              <span>History</span>
            </span>
          </a>
          <a class="navbar-item" href="/logs">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-globe"></i></span>
              <span>Logs</span>
            </span>
          </a>
        </div>
        <div class="navbar-end pr-3">
          <div class="navbar-item">
            <button class="button is-dark" @click="selectedTheme = 'light'" v-if="'dark' === selectedTheme"
                    v-tooltip="'Switch to light theme'">
              <span class="icon is-small has-text-warning">
                <i class="fas fa-sun"></i>
              </span>
            </button>
            <button class="button is-dark" @click="selectedTheme = 'dark'" v-if="'light' === selectedTheme"
                    v-tooltip="'Switch to dark theme'">
              <span class="icon is-small">
                <i class="fas fa-moon"></i>
              </span>
            </button>
          </div>
          <div class="navbar-item">
            <button class="button is-dark" @click="showConnection = !showConnection" v-tooltip="'Configure connection'">
              <span class="icon is-small">
                <i class="fas fa-cog"></i>
              </span>
            </button>
          </div>
        </div>
      </div>
    </nav>

    <div class="columns is-multiline">
      <div class="column is-12 mt-2" v-if="showConnection">
        <form class="box" @submit.prevent="testApi">
          <div class="field">
            <label class="label" for="api_url">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>API URL</span>
              </span>
            </label>
            <div class="control">
              <input class="input" id="api_url" type="url" v-model="api_url" required
                     placeholder="API URL... http://localhost:8081"
                     @keyup="api_status = false; api_response = ''">
              <p class="help">
                Use <a href="javascript:void(0)" @click="setOrigin">current page URL</a>.
              </p>
            </div>
          </div>

          <div class="field">
            <label class="label" for="api_token">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-key"></i></span>
                <span>API Token</span>
              </span>
            </label>
            <div class="control">
              <input class="input" id="api_token" type="text" v-model="api_token" required placeholder="API Token..."
                     @keyup="api_status = false; api_response = ''">
            </div>
            <p class="help">Can be obtained by using the <code>system:apikey</code> command.</p>
          </div>

          <div class="field is-grouped has-addons-right">
            <div class="control is-expanded">
              <input class="input" type="text" v-model="api_response" readonly disabled
                     :class="{'has-background-success': true===api_status}">
              <p class="help">These settings are stored locally in your browser.</p>
            </div>
            <div class="control">
              <button type="submit" class="button is-primary" :disabled="!api_url || !api_token">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-signs-post"></i></span>
                  <span>Check</span>
                </span>
              </button>
            </div>
          </div>
        </form>
      </div>

      <div class="column is-12">
        <slot/>
      </div>
    </div>

    <div class="columns mt-3 is-mobile">
      <div class="column is-8-mobile">
        <div class="has-text-left">
          © {{ Year }} - <a href="https://github.com/arabcoders/watchstate" target="_blank">WatchState</a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {ref} from 'vue'
import 'assets/css/bulma.css'
import 'assets/css/style.css'
import 'assets/css/all.css'
import {useStorage} from '@vueuse/core'

const selectedTheme = useStorage('theme', (() => window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')())
const showConnection = ref(false)

const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')
const api_status = ref(false)
const api_response = ref('Status: Unknown')

const Year = ref(new Date().getFullYear())
const showMenu = ref(false)

const applyPreferredColorScheme = (scheme) => {
  for (let s = 0; s < document.styleSheets.length; s++) {
    for (let i = 0; i < document.styleSheets[s].cssRules.length; i++) {
      try {
        const rule = document.styleSheets[s].cssRules[i];
        if (rule && rule.media && rule.media.mediaText.includes("prefers-color-scheme")) {
          switch (scheme) {
            case "light":
              rule.media.appendMedium("original-prefers-color-scheme")
              if (rule.media.mediaText.includes("light")) {
                rule.media.deleteMedium("(prefers-color-scheme: light)")
              }
              if (rule.media.mediaText.includes("dark")) {
                rule.media.deleteMedium("(prefers-color-scheme: dark)")
              }
              break;
            case "dark":
              rule.media.appendMedium("(prefers-color-scheme: light)")
              rule.media.appendMedium("(prefers-color-scheme: dark)")
              if (rule.media.mediaText.includes("original")) {
                rule.media.deleteMedium("original-prefers-color-scheme")
              }
              break;
            default:
              rule.media.appendMedium("(prefers-color-scheme: dark)")
              if (rule.media.mediaText.includes("light")) {
                rule.media.deleteMedium("(prefers-color-scheme: light)")
              }
              if (rule.media.mediaText.includes("original")) {
                rule.media.deleteMedium("original-prefers-color-scheme")
              }
              break;
          }
        }
      } catch (e) {
        console.debug(e)
      }
    }
  }
}

onMounted(() => {
  try {
    applyPreferredColorScheme(selectedTheme.value)
  } catch (e) {
  }
})

watch(selectedTheme, (value) => {
  try {
    applyPreferredColorScheme(value)
  } catch (e) {
  }
})

const testApi = async () => {
  try {
    const response = await fetch(api_url.value + '/v1/api/backends', {
      method: 'GET',
      headers: {
        'Authorization': 'Bearer ' + api_token.value,
        'Content-Type': 'application/json'
      }
    })

    const json = await response.json()

    if (json.error) {
      api_status.value = false;
      api_response.value = `Error ${json.error.code} - ${json.error.message}`
      return
    }

    api_status.value = 200 === response.status;
    api_response.value = 200 === response.status ? `Status: OK` : `Status: ${response.status} - ${response.statusText}`;

  } catch (e) {
    api_status.value = false;
    api_response.value = `Error: ${e.message}`;
  }
}

const setOrigin = () => api_url.value = window.location.origin;
</script>
