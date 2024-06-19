<template>
  <div class="container">
    <nav class="navbar is-dark mb-4">
      <div class="navbar-brand pl-5">
        <NuxtLink class="navbar-item" to="/" @click.native="showMenu=false">
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
          <NuxtLink class="navbar-item" to="/backends" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"></i></span>
              <span>Backends</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/history"
                    @click.native="showMenu=false; dEvent('history_main_link_clicked',{'clear':true})">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-history"></i></span>
              <span>History</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/tasks" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-tasks"></i></span>
              <span>Tasks</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/env" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-cogs"></i></span>
              <span>Env</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/logs" @click.native="showMenu=false">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-globe"></i></span>
              <span>Logs</span>
            </span>
          </NuxtLink>


          <div class="navbar-item has-dropdown is-hoverable">
            <a class="navbar-link">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-ellipsis-vertical"></i></span>
                <span>More</span>
              </span>
            </a>
            <div class="navbar-dropdown">

              <NuxtLink class="navbar-item" to="/parity" @click.native="showMenu=false">
                <span class="icon"><i class="fas fa-database"></i></span>
                <span>Data Parity</span>
              </NuxtLink>
              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/queue" @click.native="showMenu=false">
                <span class="icon"><i class="fas fa-list"></i></span>
                <span>Queue</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/ignore" @click.native="showMenu=false">
                <span class="icon"><i class="fas fa-ban"></i></span>
                <span>Ignore List</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/report" @click.native="showMenu=false">
                <span class="icon"><i class="fas fa-flag"></i></span>
                <span>Basic Report</span>
              </NuxtLink>
              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/suppression" @click.native="showMenu=false">
                <span class="icon"><i class="fas fa-bug-slash"></i></span>
                <span>Log Suppression</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/backup" @click.native="showMenu=false">
                <span class="icon"><i class="fas fa-sd-card"></i></span>
                <span>Backups</span>
              </NuxtLink>
            </div>
          </div>

        </div>
        <div class="navbar-end pr-3">
          <NuxtLink class="navbar-item" to="/console" @click.native="showMenu=false" v-if="hasAPISettings">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-terminal"></i></span>
              <span>Console</span>
            </span>
          </NuxtLink>

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
    <div>
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
                          <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
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
        <slot v-if="!showConnection"/>
      </template>

      <div class="columns is-multiline is-mobile mt-3">
        <div class="column is-6 is-9-mobile has-text-left">
          <NuxtLink @click="loadFile = '/README.md'" v-text="'README'"/>
          -
          <NuxtLink @click="loadFile = '/FAQ.md'" v-text="'FAQ'"/>
          -
          <NuxtLink @click="loadFile = '/NEWS.md'" v-text="'News'"/>
        </div>
        <div class="column is-6 is-4-mobile has-text-right">
          {{ api_version }} - <a href="https://github.com/arabcoders/watchstate" target="_blank">WatchState</a>
        </div>
      </div>

      <template v-if="loadFile">
        <Overlay @closeOverlay="closeOverlay" :title="loadFile">
          <Markdown :file="loadFile"/>
        </Overlay>
      </template>
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
import request from '~/utils/request.js'
import Markdown from '~/components/Markdown.vue'
import {dEvent} from '~/utils/index.js'

const selectedTheme = useStorage('theme', (() => window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')())
const showConnection = ref(false)

const real_api_url = useStorage('api_url', '')
const real_api_path = useStorage('api_path', '/v1/api')
const real_api_token = useStorage('api_token', '')

const api_url = ref(toRaw(real_api_url.value))
const api_path = ref(toRaw(real_api_path.value))
const api_token = ref(toRaw(real_api_token.value))

const api_status = ref(false)
const api_response = ref('Status: Unknown')
const api_version = ref()

const showMenu = ref(false)
const exposeToken = ref(false)

const loadFile = ref()

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

    if ('' === api_token.value || '' === api_url.value) {
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
    const response = await fetch(`${api_url.value}${api_path.value}/backends`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${api_token.value}`
      }
    })
    const json = await response.json()

    if (json.error) {
      api_status.value = false;
      api_response.value = `Error ${json.error.code} - ${json.error.message}`
      return
    }

    if (200 === response.status) {
      real_api_url.value = api_url.value
      real_api_path.value = api_path.value
      real_api_token.value = api_token.value
      await getVersion(false)
    }

    api_status.value = 200 === response.status;
    api_response.value = 200 === response.status ? `Status: OK` : `Status: ${response.status} - ${response.statusText}`;
  } catch (e) {
    api_status.value = false;
    api_response.value = `Request error. ${e.message}`;
  }
}

const getVersion = async (updateStatus = true) => {
  if (api_version.value) {
    return;
  }

  try {
    const response = await request('/system/version')
    const json = await response.json()
    api_version.value = json.version
    if (updateStatus) {
      api_status.value = true
      api_response.value = 'Status: OK'
    }

  } catch (e) {
    return 'Unknown'
  }
}

const setOrigin = () => api_url.value = window.location.origin;

const hasAPISettings = computed(() => '' !== real_api_token.value && '' !== real_api_url.value)

const closeOverlay = () => {
  loadFile.value = ''
}

</script>
