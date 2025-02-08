<template>
  <div class="container">
    <nav class="navbar is-dark mb-4 is-unselectable">
      <div class="navbar-brand pl-5">
        <NuxtLink class="navbar-item" to="/" @click.native="(e) => changeRoute(e)">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-home"/></span>
            <span>Home</span>
          </span>
        </NuxtLink>

        <a class="navbar-item is-hidden-tablet" id="top" href="#bottom">
          <span class="icon"><i class="fas fa-arrow-down"/></span>
        </a>

        <button class="navbar-burger burger" @click="showMenu = !showMenu">
          <span aria-hidden="true"></span>
          <span aria-hidden="true"></span>
          <span aria-hidden="true"></span>
        </button>
      </div>

      <div class="navbar-menu" :class="{ 'is-active': showMenu }">
        <div class="navbar-start" v-if="hasAPISettings && !showConnection">
          <NuxtLink class="navbar-item" to="/backends" @click.native="(e) => changeRoute(e)">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"/></span>
              <span>Backends</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/history"
                    @click.native="(e) => changeRoute(e, () => dEvent('history_main_link_clicked', { 'clear': true }))">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-history"/></span>
              <span>History</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/tasks" @click.native="(e) => changeRoute(e)">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-tasks"/></span>
              <span>Tasks</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/env" @click.native="(e) => changeRoute(e)">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-cogs"/></span>
              <span>Env</span>
            </span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/logs" @click.native="(e) => changeRoute(e)">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-globe"/></span>
              <span>Logs</span>
            </span>
          </NuxtLink>

          <div class="navbar-item has-dropdown">
            <a class="navbar-link" @click="(e) => openMenu(e)">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-ellipsis-vertical"/></span>
                <span>More</span>
              </span>
            </a>
            <div class="navbar-dropdown">

              <NuxtLink class="navbar-item" to="/console" @click.native="(e) => changeRoute(e)" v-if="hasAPISettings">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-terminal"/></span>
                  <span>Console</span>
                </span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/parity" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-database"/></span>
                <span>Data Parity</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/integrity" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-file"/></span>
                <span>Files Integrity</span>
              </NuxtLink>
              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/events" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-calendar-alt"/></span>
                <span>Events</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/ignore" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-ban"/></span>
                <span>Ignore List</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/report" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-flag"/></span>
                <span>Basic Report</span>
              </NuxtLink>
              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/suppression" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-bug-slash"/></span>
                <span>Log Suppression</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/custom" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-map"/></span>
                <span>Custom GUIDs</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/backup" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-sd-card"/></span>
                <span>Backups</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/reset" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-redo"/></span>
                <span>System reset</span>
              </NuxtLink>
            </div>
          </div>
        </div>
        <div class="navbar-end pr-3">
          <div class="navbar-item">
            <button class="button is-dark has-tooltip-bottom" v-tooltip.bottom="'Switch to Light theme'"
                    v-if="'auto' === selectedTheme" @click="selectTheme('light')">
              <span class="icon has-text-warning"><i class="fas fa-sun"/></span>
            </button>
            <button class="button is-dark has-tooltip-bottom" v-tooltip.bottom="'Switch to Dark theme'"
                    v-if="'light' === selectedTheme" @click="selectTheme('dark')">
              <span class="icon"><i class="fas fa-moon"/></span>
            </button>
            <button class="button is-dark has-tooltip-bottom" v-tooltip.bottom="'Switch to Auto theme'"
                    v-if="'dark' === selectedTheme" @click="selectTheme('auto')">
              <span class="icon"><i class="fas fa-microchip"/></span>
            </button>
          </div>

          <div class="navbar-item" v-if="hasAPISettings">
            <button class="button is-dark" @click="showUserSelection = !showUserSelection" v-tooltip="'Change User'">
              <span class="icon"><i class="fas fa-users"/></span>
            </button>
          </div>

          <div class="navbar-item">
            <button class="button is-dark" @click="showConnection = !showConnection" v-tooltip="'Configure connection'">
              <span class="icon"><i class="fas fa-cog"/></span>
            </button>
          </div>
        </div>
      </div>
    </nav>
    <div>
      <div>
        <no-api v-if="!hasAPISettings"/>
        <Connection v-if="showConnection" @update="data => handleConnection(data)"/>
        <NuxtPage v-if="!showConnection && hasAPISettings"/>
      </div>

      <div class="columns is-multiline is-mobile mt-3">
        <div class="column is-12 is-hidden-tablet has-text-centered">
          <a href="#top" id="bottom" class="button">
            <span class="icon"><i class="fas fa-arrow-up"/>&nbsp;</span>
            <span>Go to Top</span>
          </a>
        </div>

        <div class="column is-6 is-9-mobile has-text-left">
          <NuxtLink @click="loadFile = '/README.md'" v-text="'README'"/>
          -
          <NuxtLink @click="loadFile = '/FAQ.md'" v-text="'FAQ'"/>
          -
          <NuxtLink @click="loadFile = '/NEWS.md'" v-text="'News'"/>
          -
          <NuxtLink :to="changelog_url" v-text="'ChangeLog'"/>
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

      <template v-if="showUserSelection">
        <Overlay @closeOverlay="() => showUserSelection = false" title="Change User">
          <UserSelection/>
        </Overlay>
      </template>
    </div>
  </div>
</template>

<script setup>
import {ref} from 'vue'
import 'assets/css/bulma.css'
import 'assets/css/style.css'
import 'assets/css/all.css'
import {useStorage} from '@vueuse/core'
import request from '~/utils/request'
import Markdown from '~/components/Markdown'
import UserSelection from '~/components/UserSelection'
import Connection from "~/components/Connection"

const selectedTheme = useStorage('theme', (() => window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')())
const showUserSelection = ref(false)
const showConnection = ref(false)

const api_url = useStorage('api_user', 'main')
const api_token = useStorage('api_token', '')
const api_version = ref()

const changelog_url = ref('/changelog')
watch(() => api_version.value, () => changelog_url.value = `/changelog?version=${api_version.value}`)

const showMenu = ref(false)

const loadFile = ref()

const applyPreferredColorScheme = scheme => {
  if (!scheme || 'auto' === scheme) {
    return
  }

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

const hasAPISettings = computed(() => '' !== api_token.value && '' !== api_url.value)

const closeOverlay = () => loadFile.value = ''

const openMenu = e => {
  const elm = e.target.closest('div.has-dropdown')

  document.querySelectorAll('div.has-dropdown').forEach(el => {
    if (el !== elm) {
      el.classList.remove('is-active')
    }
  })

  e.target.closest('div.has-dropdown').classList.toggle('is-active')
}

const changeRoute = async (_, callback) => {
  showMenu.value = false
  document.querySelectorAll('div.has-dropdown').forEach(el => el.classList.remove('is-active'))
  if (callback) {
    callback()
  }
}

const selectTheme = theme => {
  selectedTheme.value = theme
  if ('auto' === theme) {
    return window.location.reload()
  }
}

const handleConnection = data => {
  api_version.value = data.version
  showConnection.value = false
}

</script>
