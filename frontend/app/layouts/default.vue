<template>
  <div class="container">
    <NewVersion v-if="newVersionIsAvailable"/>
    <nav class="navbar is-dark mb-4 is-unselectable">
      <div class="navbar-brand pl-5">
        <NuxtLink class="navbar-item" to="/" @click="(e: Event) => changeRoute(e)">
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
        <div class="navbar-start">
          <NuxtLink class="navbar-item" to="/backends" @click="(e: Event) => changeRoute(e)">
            <span class="icon"><i class="fas fa-server"/></span>
            <span>Backends</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/history"
                    @click="(e: Event) => changeRoute(e, () => dEvent('history_main_link_clicked', { 'clear': true }))">
            <span class="icon"><i class="fas fa-history"/></span>
            <span>History</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/tasks" @click="(e: Event) => changeRoute(e)">
            <span class="icon"><i class="fas fa-tasks"/></span>
            <span>Tasks</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/env" @click="(e: Event) => changeRoute(e)">
            <span class="icon"><i class="fas fa-cogs"/></span>
            <span>Env</span>
          </NuxtLink>

          <NuxtLink class="navbar-item" to="/logs" @click="(e: Event) => changeRoute(e)">
            <span class="icon"><i class="fas fa-globe"/></span>
            <span>Logs</span>
          </NuxtLink>

          <div class="navbar-item has-dropdown">
            <a class="navbar-link" @click="(e: Event) => openMenu(e)">
              <span class="icon"><i class="fas fa-tools"/></span>
              <span>Tools</span>
            </a>

            <div class="navbar-dropdown">

              <NuxtLink class="navbar-item" to="/tools/plex_token" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-key"/></span>
                <span>Plex Token</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/tools/sub_users" @click="(e: Event) => changeRoute(e)"
                        v-if="'main' === api_user">
                <span class="icon"><i class="fas fa-users"/></span>
                <span>Sub Users</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/processes" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-microchip"/></span>
                <span>Processes</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/url_check" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-external-link"/></span>
                <span>URL Checker</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/parity" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-database"/></span>
                <span>Data Parity</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/integrity" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-file"/></span>
                <span>Files Integrity</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/duplicate" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-copy"/></span>
                <span>Duplicate File Ref</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/ignore" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-ban"/></span>
                <span>Ignore List</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/suppression" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-bug-slash"/></span>
                <span>Log Suppression</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/backup" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-sd-card"/></span>
                <span>Backups</span>
              </NuxtLink>

            </div>
          </div>

          <div class="navbar-item has-dropdown">
            <a class="navbar-link" @click="(e: Event) => openMenu(e)">
              <span class="icon"><i class="fas fa-ellipsis-vertical"/></span>
              <span>More</span>
            </a>
            <div class="navbar-dropdown">

              <NuxtLink class="navbar-item" to="/console" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-terminal"/></span>
                <span>Console</span>
              </NuxtLink>
              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/events" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-calendar-alt"/></span>
                <span>Events</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/report" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-flag"/></span>
                <span>Basic Report</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/custom" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-map"/></span>
                <span>Custom GUIDs</span>
              </NuxtLink>

              <hr class="navbar-divider">

              <NuxtLink class="navbar-item" to="/purge_cache" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-trash"/></span>
                <span>Purge Cache</span>
              </NuxtLink>

              <NuxtLink class="navbar-item" to="/reset" @click="(e: Event) => changeRoute(e)">
                <span class="icon"><i class="fas fa-redo"/></span>
                <span>System reset</span>
              </NuxtLink>

            </div>
          </div>
        </div>
        <div class="navbar-end pr-3">
          <template v-if="'mobile' === breakpoints.active().value">
            <div class="navbar-item" v-if="in_container">
              <button class="button is-dark" @click="showScheduler = !showScheduler">
                <span class="icon"
                      :class="{ 'has-text-primary': scheduler.status,
                      'has-text-danger fa-fade': !scheduler.status }"><i class="fas fa-microchip"/></span>
                <span>Task Scheduler Status</span>
              </button>
            </div>
            <div class="navbar-item">
              <NuxtLink class="button is-dark" to="/help">
                <span class="icon"><i class="fas fa-circle-question"/></span>
                <span>Guides</span>
              </NuxtLink>
            </div>

            <div class="navbar-item">
              <button class="button is-dark" @click="showUserSelection = !showUserSelection">
                <span class="icon"><i class="fas fa-users"/></span>
                <span>Change User ({{ api_user }})</span>
              </button>
            </div>

            <div class="navbar-item">
              <button class="button is-dark" @click="showSettings = !showSettings">
                <span class="icon"><i class="fas fa-cog"/></span>
                <span>Settings</span>
              </button>
            </div>

            <div class="navbar-item">
              <button class="button is-dark" @click="logout">
                <span class="icon"><i class="fas fa-sign-out"/></span>
                <span>Logout</span>
              </button>
            </div>
          </template>

          <template v-if="'mobile' !== breakpoints.active().value">
            <div class="navbar-item" v-if="in_container">
              <button class="button is-dark" @click="showScheduler = !showScheduler"
                      v-tooltip="'Task Scheduler Status'">
                <span class="icon"
                      :class="{ 'has-text-primary': scheduler.status,
                      'has-text-danger fa-fade': !scheduler.status }"
                      :style="!scheduler.status ? '--fa-animation-iteration-count: 10;' : ''"
                ><i class="fas fa-microchip"/></span>
              </button>
            </div>
            <div class="navbar-item">
              <NuxtLink class="button is-dark" v-tooltip="'Guides'" to="/help">
                <span class="icon"><i class="fas fa-circle-question"/></span>
              </NuxtLink>
            </div>

            <div class="navbar-item">
              <button class="button is-dark" @click="showUserSelection = !showUserSelection" v-tooltip="'Change User'">
                <span class="icon"><i class="fas fa-user"/></span>
                <span>{{ api_user }}</span>
              </button>
            </div>

            <div class="navbar-item">
              <button class="button is-dark" @click="showSettings = !showSettings" v-tooltip="'Settings'">
                <span class="icon"><i class="fas fa-cog"/></span>
              </button>
            </div>

            <div class="navbar-item">
              <button class="button is-dark" @click="logout" v-tooltip="'Logout'">
                <span class="icon"><i class="fas fa-sign-out"/></span>
              </button>
            </div>
          </template>

        </div>
      </div>
    </nav>
    <div>
      <div>
        <NuxtLoadingIndicator/>
        <TaskScheduler :forceShow="showScheduler" @update="e => scheduler = e" v-if="in_container"/>
        <NuxtPage/>
        <ClientOnly>
          <Dialog/>
        </ClientOnly>
      </div>

      <div class="columns is-multiline is-mobile mt-3">
        <div class="column is-12 is-hidden-tablet has-text-centered">
          <a href="#top" id="bottom" class="button">
            <span class="icon"><i class="fas fa-arrow-up"/>&nbsp;</span>
            <span>Go to Top</span>
          </a>
        </div>

        <div class="column is-6 is-9-mobile has-text-left">
          <NuxtLink to="/help">Help</NuxtLink>
          -
          <NuxtLink to="/help/readme">README</NuxtLink>
          -
          <NuxtLink to="/help/faq">FAQ</NuxtLink>
          -
          <NuxtLink to="/help/news">News</NuxtLink>
          -
          <NuxtLink :to="changelog_url">CHANGELOG</NuxtLink>
        </div>
        <div class="column is-6 is-4-mobile has-text-right">
          <span v-tooltip="`Build Date: ${api_version_date}, Branch: ${api_version_branch}, commit: ${api_version_sha}`"
                v-if="api_version" class="has-tooltip">
            {{ api_version }}
          </span>
          - <a href="https://github.com/arabcoders/watchstate" target="_blank">WatchState</a>
        </div>
      </div>

      <template v-if="loadFile">
        <Overlay @closeOverlay="closeOverlay" :title="loadFile">
          <Markdown :file="loadFile"/>
        </Overlay>
      </template>

      <template v-if="showUserSelection">
        <Overlay @closeOverlay="() => showUserSelection = false" title="Change User">
          <UserSelection @close="() => showUserSelection = false"/>
        </Overlay>
      </template>
      <template v-if="showSettings">
        <Overlay @closeOverlay="() => showSettings = false" title="WebUI Settings">
          <Settings @force_bg_reload="() => loadImage(true)"/>
        </Overlay>
      </template>

    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, watch, onMounted, readonly} from 'vue'
import '~/assets/css/bulma.css'
import '~/assets/css/style.css'
import '~/assets/css/all.css'
import {useBreakpoints, useStorage} from '@vueuse/core'
import Markdown from '~/components/Markdown.vue'
import UserSelection from '~/components/UserSelection.vue'
import {useAuthStore} from '~/store/auth'
import Settings from '~/components/Settings.vue'
import TaskScheduler from '~/components/TaskScheduler.vue'
import NewVersion from '~/components/NewVersion.vue'
import Dialog from '~/components/Dialog.vue'
import {navigateTo} from '#app'
import {useDialog} from '~/composables/useDialog.ts'
import {request, dEvent} from '~/utils'

const useVersionUpdate = () => {
  const newVersionIsAvailable = ref(false)
  const nuxtApp = useNuxtApp()
  nuxtApp.hooks.addHooks({
    'app:manifest:update': () => {
      newVersionIsAvailable.value = true
    }
  })
  return {
    newVersionIsAvailable: readonly(newVersionIsAvailable),
  }
}

const {newVersionIsAvailable} = useVersionUpdate()
const selectedTheme = useStorage<string>('theme', 'auto')
const showUserSelection = ref<boolean>(false)
const showSettings = ref<boolean>(false)

const auth = useAuthStore()
const bg_enable = useStorage<boolean>('bg_enable', true)
const bg_opacity = useStorage<number>('bg_opacity', 0.95)
const api_user = useStorage<string>('api_user', 'main')

const api_version = ref<string | undefined>()
const api_version_sha = ref<string | undefined>()
const api_version_date = ref<string | undefined>()
const api_version_branch = ref<string | undefined>()
const bgImage = ref<{ src: string; type: string }>({src: '', type: ''})
const loadedImages = ref<Record<string, string>>({poster: '', background: ''})

const breakpoints = useBreakpoints({mobile: 0, desktop: 640})

const changelog_url = ref<string>('/changelog')
watch(() => api_version.value, () => changelog_url.value = `/changelog?version=${api_version.value}`)

const showMenu = ref<boolean>(false)
const loadFile = ref<string | undefined>()
const in_container = ref<boolean>(false)
const scheduler = ref<{ status: boolean; message: string; restartable: boolean }>({
  status: false,
  message: 'Loading...',
  restartable: false
})
const showScheduler = ref<boolean>(false)

const applyPreferredColorScheme = (scheme: string): void => {
  if (!scheme || scheme === 'auto') {
    return
  }
  for (let s = 0; s < document.styleSheets.length; s++) {
    const styleSheet = document.styleSheets[s]
    if (!styleSheet) {
      continue
    }

    let cssRules: CSSRuleList | undefined
    try {
      cssRules = styleSheet.cssRules
    } catch {
      continue
    }

    if (!cssRules) {
      continue
    }

    for (let i = 0; i < cssRules.length; i++) {
      const rule = cssRules[i]
      if (!rule || !(rule instanceof CSSMediaRule)) {
        continue
      }

      const mediaList = rule.media

      if (!mediaList || typeof mediaList.appendMedium !== 'function' || typeof mediaList.deleteMedium !== 'function') {
        continue
      }

      if (!mediaList.mediaText.includes('prefers-color-scheme')) {
        continue
      }

      switch (scheme) {
        case 'light':
          mediaList.appendMedium('original-prefers-color-scheme')
          if (mediaList.mediaText.includes('light')) {
            mediaList.deleteMedium('(prefers-color-scheme: light)')
          }
          if (mediaList.mediaText.includes('dark')) {
            mediaList.deleteMedium('(prefers-color-scheme: dark)')
          }
          break
        case 'dark':
          mediaList.appendMedium('(prefers-color-scheme: light)')
          mediaList.appendMedium('(prefers-color-scheme: dark)')
          if (mediaList.mediaText.includes('original')) {
            mediaList.deleteMedium('original-prefers-color-scheme')
          }
          break
        default:
          mediaList.appendMedium('(prefers-color-scheme: dark)')
          if (mediaList.mediaText.includes('light')) {
            mediaList.deleteMedium('(prefers-color-scheme: light)')
          }
          if (mediaList.mediaText.includes('original')) {
            mediaList.deleteMedium('original-prefers-color-scheme')
          }
          break
      }
    }
  }
}

onMounted(async () => {
  try {
    applyPreferredColorScheme(selectedTheme.value)
    await getVersion()
    await loadImage()
  } catch {
  }
})

watch(selectedTheme, value => {
  try {
    if ('auto' === value) {
      applyPreferredColorScheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      return
    }
    applyPreferredColorScheme(value)
  } catch {
  }
})

const getVersion = async (): Promise<string | undefined> => {
  if (api_version.value) {
    return
  }
  try {
    const response = await request('/system/version')
    const json = await response.json()
    api_version.value = json.version
    api_version_sha.value = json.sha
    api_version_date.value = json.build
    api_version_branch.value = json.branch
    in_container.value = Boolean(json.container)
  } catch {
    return 'Unknown'
  }
}

const closeOverlay = (): void => {
  loadFile.value = ''
}

const openMenu = (e: Event): void => {
  const elm = (e.target as HTMLElement).closest('div.has-dropdown')
  document.querySelectorAll('div.has-dropdown').forEach(el => {
    if (el !== elm) {
      el.classList.remove('is-active')
    }
  })
  if (elm) {
    elm.classList.toggle('is-active')
  }
}

const changeRoute = async (_: Event, callback?: () => void): Promise<void> => {
  showMenu.value = false
  document.querySelectorAll('div.has-dropdown').forEach(el => el.classList.remove('is-active'))
  if (callback) {
    callback()
  }
}

watch(bgImage, async v => {
  if (bg_enable.value === false) {
    return
  }
  const html = document.documentElement
  if (v.src === '' || v.src === 'failed') {
    if (html.getAttribute('style')) {
      html.removeAttribute('style')
    }
    return
  }
  const style: Record<string, string> = {
    'background-color': 'unset',
    'display': 'block',
    'min-height': '100%',
    'min-width': '100%',
    'background-image': `url(${v.src})`,
  }
  html.setAttribute('style', Object.keys(style).map(k => `${k}: ${style[k]}`).join('; ').trim())
  html.classList.add('bg-fanart')
  document.querySelector('body')?.setAttribute('style', 'opacity: 0.95')
}, {immediate: true})

watch(bg_opacity, v => {
  if (false === bg_enable.value) {
    return
  }
  document.querySelector('body')?.setAttribute('style', `opacity: ${v}`)
})

watch(breakpoints.active(), async () => await loadImage())

watch(bg_enable, async v => {
  if (true === v) {
    await loadImage()
    return
  }
  if ('' === bgImage.value.src) {
    return
  }
  loadedImages.value = {poster: '', background: ''}
  bgImage.value = {src: '', type: ''}
  const html = document.documentElement
  if (!html.getAttribute('style')) {
    return
  }
  html.removeAttribute('style')
  document.querySelector('body')?.setAttribute('style', '')
})

const loadImage = async (force = false): Promise<void> => {
  if (!bg_enable.value) {
    return
  }
  const bg_type = 'mobile' === breakpoints.active().value ? 'poster' : 'background'
  if (false === force && bgImage.value && bgImage.value.type === bg_type) {
    return
  }
  if (false === force && loadedImages.value[bg_type]) {
    bgImage.value = {src: loadedImages.value[bg_type], type: bg_type}
    return
  }
  let url = `/system/images/${bg_type}`
  if (force) {
    url += `?force=1&t=${Date.now()}`
  }
  const imgRequest = await request(url)
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
  } catch {
    bgImage.value = {src: 'failed', type: bg_type}
  }
}

const logout = async (): Promise<boolean> => {
  const {status} = await useDialog().confirmDialog({
    title: 'Logout',
    message: 'Are you sure you want to logout?',
    confirmColor: 'is-danger'
  })

  if (true !== status) {
    return false
  }

  await auth.logout()
  await navigateTo('/auth')
  return true
}
</script>
