<template>
  <div class="container">
    <nav class="navbar is-dark mb-4">
      <div class="navbar-brand pl-5">
        <NuxtLink class="navbar-item" href="/" @click.native="showMenu=false">
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
        <div class="navbar-start" v-if="hasAPISettings">
          <NuxtLink class="navbar-item" href="/backends" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"></i></span>
              <span>Backends</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/history" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-history"></i></span>
              <span>History</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/tasks" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-tasks"></i></span>
              <span>Tasks</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/env" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-cogs"></i></span>
              <span>Env</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/logs" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-globe"></i></span>
              <span>Logs</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/console" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-terminal"></i></span>
              <span>Console</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" href="/report" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-flag"></i></span>
              <span>S. Report</span>
            </span>
          </NuxtLink>

        </div>
        <div class="navbar-end pr-3">
          <div class="navbar-item">
            <button class="button is-dark" @click="maskAll = !maskAll" v-tooltip="'Toggle Text Obscure'">
              <span class="icon"><i class="fas fa-mask"></i></span>
            </button>
          </div>
          <div class="navbar-item">
            <button class="button is-dark" @click="selectedTheme = 'light'" v-if="'dark' === selectedTheme"
                    v-tooltip="'Switch to light theme'">
              <span class="icon has-text-warning"><i class="fas fa-sun"></i></span>
            </button>
            <button class="button is-dark" @click="selectedTheme = 'dark'" v-if="'light' === selectedTheme"
                    v-tooltip="'Switch to dark theme'">
              <span class="icon"><i class="fas fa-moon"></i></span>
            </button>
          </div>
          <div class="navbar-item">
            <button class="button is-dark" @click="showConnection = !showConnection" v-tooltip="'Configure connection'">
              <span class="icon"><i class="fas fa-cog"></i></span>
            </button>
          </div>
        </div>
      </div>
    </nav>
    <div :class="{'is-full-mask':maskAll}">

      <div class="columns is-multiline" v-if="showConnection">
        <div class="column is-12 mt-2">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title">
                Configure API Connection
              </p>
              <span class="card-header-icon">
                <span class="icon"><i class="fas fa-cog"></i></span>
              </span>
            </header>
            <div class="card-content">
              <form @submit.prevent="testApi">
                <div class="field">
                  <label class="label" for="api_token">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-key"></i></span>
                      <span>API Token</span>
                    </span>
                  </label>
                  <div class="field-body">
                    <div class="field">
                      <div class="field has-addons">
                        <div class="control is-expanded">
                          <input class="input" id="api_token" v-model="api_token" required placeholder="API Token..."
                                 @keyup="api_status = false; api_response = ''"
                                 :type="false === exposeToken ? 'password' : 'text'">
                        </div>
                        <div class="control">
                          <button class="button is-primary" @click="exposeToken = !exposeToken"
                                  v-tooltip="'Show/Hide token'">
                            <span class="icon" v-if="!exposeToken"><i class="fas fa-eye"></i></span>
                            <span class="icon" v-else><i class="fas fa-eye-slash"></i></span>
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
                      <span class="icon"><i class="fas fa-link"></i></span>
                      <span>API URL</span>
                    </span>
                  </label>
                  <div class="field-body">
                    <div class="field">
                      <div class="control">
                        <input class="input" id="api_url" type="url" v-model="api_url" required
                               placeholder="API URL... http://localhost:8081"
                               @keyup="api_status = false; api_response = ''">
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
                      <span class="icon"><i class="fas fa-folder"></i></span>
                      <span>API Path</span>
                    </span>
                  </label>
                  <div class="field-body">
                    <div class="field">
                      <div class="control">
                        <input class="input" id="api_path" type="text" v-model="api_path" required
                               placeholder="API Path... /v1/api"
                               @keyup="api_status = false; api_response = ''">
                        <p class="help">
                          Use <a href="javascript:void(0)" @click="api_path = '/v1/api'">Set default API Path</a>.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="field">
                  <div class="field-body">
                    <div class="field">
                      <div class="field has-addons">
                        <div class="control is-expanded">
                          <input class="input" type="text" v-model="api_response" readonly disabled
                                 :class="{'has-background-success': true===api_status,'has-background-warning': true!==api_status}">
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
                      <p class="help">
                        <span class="icon-text">
                          <span class="icon has-text-danger"><i class="fas fa-info"></i></span>
                          <span>These settings are stored locally in your browser. You need to re-add them if you access
                            the
                            <code>WebUI</code> from different browser.</span>
                        </span>
                      </p>
                    </div>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <template v-if="!api_url || !api_token">
        <no-api/>
      </template>
      <template v-else>
        <slot/>
      </template>

      <div class="columns is-multiline mt-3">
        <div class="column is-12 is-hidden-mobile">
          <div class="content">
            <Message v-if="show_page_info" title="Information">
              <button class="delete" @click="show_page_info = false"></button>
              If you have question, or want clarification on something, or just want to chat with other users, you are
              welcome to join our
              <NuxtLink href="https://discord.gg/haUXHJyj6Y" target="_blank">
                <span class="icon-text is-underlined">
                  <span class="icon"><i class="fas fa-brands fa-discord"></i></span>
                  <span>Discord server</span>
                </span>
              </NuxtLink>
              . For real bug reports, feature requests, or contributions, please visit the
              <NuxtLink href="https://github.com/arabcoders/watchstate/issues/new/choose" target="_blank">
                <span class="icon-text is-underlined">
                  <span class="icon"><i class="fas fa-brands fa-github"></i></span>
                  <span>GitHub repository</span>
                </span>
              </NuxtLink>
              .
            </Message>
          </div>
        </div>
        <div class="column is-6 is-12-mobile has-text-left">
          {{ api_version }} - <a href="https://github.com/arabcoders/watchstate" target="_blank">WatchState</a>
          <template v-if="!show_page_info">
            <span class="is-hidden-mobile">
              - <a href="javascript:void(0)" @click="show_page_info=true">Show Info</a>
            </span>
          </template>
        </div>
      </div>

      <NuxtNotifications position="top right" :speed="800" :ignoreDuplicates="true" :width="340" :pauseOnHover="true"/>
    </div>
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
const show_page_info = useStorage('show_page_info', true)
const maskAll = useStorage('page_mask', false)

const api_status = ref(false)
const api_response = ref('Status: Unknown')
const api_version = ref()

const showMenu = ref(false)
const exposeToken = ref(false)

const applyPreferredColorScheme = scheme => {
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

    if ('' === api_token.value) {
      showConnection.value = true
      return
    }
    await getVersion()
  } catch (e) {
  }
})

watch(selectedTheme, value => {
  try {
    applyPreferredColorScheme(value)
  } catch (e) {
  }
})

watch(api_token, value => {
  if ('' === value) {
    api_status.value = false;
    api_response.value = 'Status: Unknown'
    showConnection.value = true
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
  if (api_version.value) {
    return;
  }

  try {
    const response = await request('/system/version')
    const json = await response.json()
    api_version.value = json.version
  } catch (e) {
    return 'Unknown'
  }
}

const setOrigin = () => api_url.value = window.location.origin;

const hasAPISettings = computed(() => '' !== api_token.value && '' !== api_url.value)
</script>
