<template>
  <div class="container">
    <nav class="navbar is-dark mb-4 is-unselectable">
      <div class="navbar-brand pl-5">
        <NuxtLink class="navbar-item" to="/" @click.native="(e) => changeRoute(e)">
          <span class="icon"><i class="fas fa-home"/></span>
          <span>Home</span>
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
            <span class="icon"><i class="fas fa-server"/></span>
            <span>Backends</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/history"
                    @click.native="(e) => changeRoute(e, () => dEvent('history_main_link_clicked', { 'clear': true }))">
            <span class="icon"><i class="fas fa-history"/></span>
            <span>History</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/tasks" @click.native="(e) => changeRoute(e)">
            <span class="icon"><i class="fas fa-tasks"/></span>
            <span>Tasks</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/env" @click.native="(e) => changeRoute(e)">
            <span class="icon"><i class="fas fa-cogs"/></span>
            <span>Env</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/logs" @click.native="(e) => changeRoute(e)">
            <span class="icon"><i class="fas fa-globe"/></span>
            <span>Logs</span>
          </NuxtLink>

          <div class="navbar-item has-dropdown">
            <a class="navbar-link" @click="(e) => openMenu(e)">
              <span class="icon"><i class="fas fa-tools"/></span>
              <span>Tools</span>
            </a>

            <div class="navbar-dropdown">

              <NuxtLink class="navbar-item" to="/tools/plex_token" @click.native="(e) => changeRoute(e)"
                        v-if="hasAPISettings">
                <span class="icon"><i class="fas fa-key"/></span>
                <span>Plex Token</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/tools/sub_users" @click.native="(e) => changeRoute(e)"
                        v-if="hasAPISettings">
                <span class="icon"><i class="fas fa-users"/></span>
                <span>Sub Users</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/processes" @click.native="(e) => changeRoute(e)"
                        v-if="hasAPISettings">
                <span class="icon"><i class="fas fa-microchip"/></span>
                <span>Processes</span>
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

              <NuxtLink class="navbar-item" to="/ignore" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-ban"/></span>
                <span>Ignore List</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/suppression" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-bug-slash"/></span>
                <span>Log Suppression</span>
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

          <div class="navbar-item has-dropdown">
            <a class="navbar-link" @click="(e) => openMenu(e)">
              <span class="icon"><i class="fas fa-ellipsis-vertical"/></span>
              <span>More</span>
            </a>
            <div class="navbar-dropdown">

              <NuxtLink class="navbar-item" to="/console" @click.native="(e) => changeRoute(e)" v-if="hasAPISettings">
                <span class="icon"><i class="fas fa-terminal"/></span>
                <span>Console</span>
              </NuxtLink>
              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/events" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-calendar-alt"/></span>
                <span>Events</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/report" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-flag"/></span>
                <span>Basic Report</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/custom" @click.native="(e) => changeRoute(e)">
                <span class="icon"><i class="fas fa-map"/></span>
                <span>Custom GUIDs</span>
              </NuxtLink>

            </div>
          </div>
        </div>
        <div class="navbar-end pr-3">
          <template v-if="hasAPISettings && !showConnection">
            <div class="navbar-item">
              <NuxtLink class="button is-dark" v-tooltip="'Guides'" to="/help">
                <span class="icon"><i class="fas fa-circle-question"/></span>
              </NuxtLink>
            </div>

            <div class="navbar-item" v-if="hasAPISettings && !showConnection">
              <button class="button is-dark" @click="showUserSelection = !showUserSelection" v-tooltip="'Change User'">
                <span class="icon"><i class="fas fa-users"/></span>
              </button>
            </div>
          </template>

          <div class="navbar-item">
            <button class="button is-dark" @click="showConnection = !showConnection"
                    v-tooltip="'Configure connection'">
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
          <NuxtLink to="/help/readme" v-text="'README'"/>
          -
          <NuxtLink to="/help/faq" v-text="'FAQ'"/>
          -
          <NuxtLink to="/help/news" v-text="'News'"/>
          -
          <NuxtLink :to="changelog_url" v-text="'CHANGELOG'"/>
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
import {useBreakpoints, useStorage} from '@vueuse/core'
import request from '~/utils/request'
import Markdown from '~/components/Markdown'
import UserSelection from '~/components/UserSelection'
import Connection from '~/components/Connection'

const selectedTheme = useStorage('theme', 'auto')
const showUserSelection = ref(false)
const showConnection = ref(false)

const api_url = useStorage('api_user', 'main')
const api_token = useStorage('api_token', '')
const bg_enable = useStorage('bg_enable', true)
const bg_opacity = useStorage('bg_opacity', 0.95)
const api_version = ref()
const bgImage = ref({src: '', type: ''})
const loadedImages = ref({poster: '', background: ''})

const breakpoints = useBreakpoints({mobile: 0, desktop: 640})

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
    await loadImage()
  } catch (e) {
  }
})

watch(selectedTheme, value => {
  try {
    if ('auto' === value) {
      applyPreferredColorScheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      return
    }
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

const handleConnection = data => {
  api_version.value = data.version
  showConnection.value = false
}

watch(bgImage, async v => {
  if (false === hasAPISettings.value || false === bg_enable.value) {
    return
  }

  const html = document.documentElement;
  if ('' === v || 'failed' === v.src || '' === v.src) {
    if (html.getAttribute("style")) {
      html.removeAttribute("style");
    }
    return
  }

  const style = {
    "background-color": "unset",
    "display": 'block',
    "min-height": '100%',
    "min-width": '100%',
    "background-image": `url(${v.src})`,
  }

  html.setAttribute("style", Object.keys(style).map(k => `${k}: ${style[k]}`).join('; ').trim())
  html.classList.add('bg-fanart')
  document.querySelector('body').setAttribute("style", "opacity: 0.95");
}, {immediate: true})

watch(bg_opacity, v => {
  if (false === hasAPISettings.value || false === bg_enable.value) {
    return
  }
  document.querySelector('body').setAttribute("style", `opacity: ${v}`)
})
watch(hasAPISettings, async () => await loadImage())
watch(breakpoints.active(), async () => await loadImage())
watch(bg_enable, async v => {
  if (true === v) {
    await loadImage()
    return
  }

  if ('' === bgImage.value.src) {
    return
  }

  loadedImages.value = []
  bgImage.value = {src: '', type: ''}


  const html = document.documentElement;
  if (!html.getAttribute("style")) {
    return
  }

  html.removeAttribute("style");
  document.querySelector('body').setAttribute("style", "");
})

const loadImage = async () => {
  if (!bg_enable.value || !hasAPISettings) {
    return
  }

  const bg_type = 'mobile' === breakpoints.active().value ? 'poster' : 'background'

  if (bgImage.value && bgImage.value.type === bg_type) {
    return
  }

  if (loadedImages.value[bg_type]) {
    bgImage.value = {src: loadedImages.value[bg_type], type: bg_type}
    return
  }

  const imgRequest = await request(`/system/images/${bg_type}`)
  if (200 !== imgRequest.status) {
    bgImage.value = {src: 'failed', type: bg_type}
    return
  }

  try {
    loadedImages.value[bg_type] = URL.createObjectURL(await imgRequest.blob())

    bgImage.value = {
      src: loadedImages.value[bg_type],
      type: bg_type
    }
  } catch (e) {
    bgImage.value = {src: 'failed', type: bg_type}
  }
}

</script>
