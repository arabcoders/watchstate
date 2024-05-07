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
          <NuxtLink class="navbar-item" href="/backends">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"></i></span>
              <span>Backends</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/history">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-history"></i></span>
              <span>History</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/tasks">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-tasks"></i></span>
              <span>Tasks</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/env">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-cogs"></i></span>
              <span>Env</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/logs">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-globe"></i></span>
              <span>Logs</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/console">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-terminal"></i></span>
              <span>Console</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/report">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-flag"></i></span>
              <span>S. Report</span>
            </span>
          </NuxtLink>

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

    <div class="columns is-multiline" v-if="showConnection">
      <div class="column is-12 mt-2">
        <form class="box" @submit.prevent="testApi">

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
            <label class="label" for="api_path">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-folder"></i></span>
                <span>API Path</span>
              </span>
            </label>
            <div class="control">
              <input class="input" id="api_path" type="text" v-model="api_path" required
                     placeholder="API Path... /v1/api"
                     @keyup="api_status = false; api_response = ''">
              <p class="help">
                Use <a href="javascript:void(0)" @click="api_path = '/v1/api'">Set default API</a>.
              </p>
            </div>
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
                  <span class="icon"><i class="fas fa-save"></i></span>
                  <span>Save</span>
                </span>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <template v-if="!api_url || !api_token">
      <no-api/>
    </template>
    <template v-else>
      <slot/>
    </template>

    <div class="columns is-multiline mt-3">
      <div class="column is-12">
        <div class="content">
          If you have question, want clarification on something, or just want to chat with other users, you are welcome
          to join our <a href="https://discord.gg/haUXHJyj6Y" rel="noreferrer,nofollow,noopener" target="_blank">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-brands fa-discord"></i></span>
            <span>Discord server</span>
          </span>
        </a>. For real bug reports, feature requests, or contributions, please visit the <a
            href="https://github.com/arabcoders/watchstate/issues/new/choose" rel="noreferrer,nofollow,noopener">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-brands fa-github"></i></span>
            <span>GitHub repository</span>
          </span>
        </a>.
        </div>
      </div>
      <div class="column is-6 is-12-mobile has-text-left">
        {{ api_version }} - <a href="https://github.com/arabcoders/watchstate" target="_blank">WatchState</a>
      </div>
    </div>

    <NuxtNotifications position="top right" :speed="800" :ignoreDuplicates="true" :width="340" :pauseOnHover="true"/>

  </div>
</template>

<script setup>
import {ref} from 'vue'
import 'assets/css/bulma.css'
import 'assets/css/style.css'
import 'assets/css/all.css'
import {useStorage} from '@vueuse/core'
import request from "~/utils/request.js";

const selectedTheme = useStorage('theme', (() => window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')())
const showConnection = ref(false)

const api_url = useStorage('api_url', window.location.origin)
const api_path = useStorage('api_path', '/v1/api')
const api_token = useStorage('api_token', '')
const api_status = ref(false)
const api_response = ref('Status: Unknown')
const api_version = useStorage('api_version', 'dev-master')

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

onMounted(async () => {
  try {
    applyPreferredColorScheme(selectedTheme.value)
    await getVersion()
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
    const response = await request('/backends')
    const json = await response.json()

    if (json.error) {
      api_status.value = false;
      api_response.value = `Error ${json.error.code} - ${json.error.message}`
      return
    }

    api_status.value = 200 === response.status;
    api_response.value = 200 === response.status ? `Status: OK` : `Status: ${response.status} - ${response.statusText}`;

    await getVersion()

  } catch (e) {
    api_status.value = false;
    api_response.value = `Error: ${e.message}`;
  }
}

const getVersion = async () => {
  try {
    const response = await request('/system/version')
    const json = await response.json()
    api_version.value = json.version
  } catch (e) {
    return 'Unknown'
  }
}

const setOrigin = () => api_url.value = window.location.origin;
</script>
