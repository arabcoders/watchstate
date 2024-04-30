<template>
  <div class="container">
    <nav class="navbar is-dark">
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
        <div class="navbar-end">
          <div class="navbar-item">
            <button class="button is-dark has-tooltip-bottom" @click="selectedTheme = 'light'"
                    v-if="'dark' === selectedTheme">
              <span class="icon is-small is-left has-text-warning">
                <i class="fas fa-sun"></i>
              </span>
            </button>
            <button class="button is-dark has-tooltip-bottom" @click="selectedTheme = 'dark'"
                    v-if="'light' === selectedTheme">
              <span class="icon is-small is-left">
                <i class="fas fa-moon"></i>
              </span>
            </button>
          </div>

        </div>
      </div>
    </nav>

    <div class="columns">
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

const selectedTheme = useStorage('theme', (() => window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')());

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
              rule.media.appendMedium("original-prefers-color-scheme");
              if (rule.media.mediaText.includes("light")) {
                rule.media.deleteMedium("(prefers-color-scheme: light)");
              }
              if (rule.media.mediaText.includes("dark")) {
                rule.media.deleteMedium("(prefers-color-scheme: dark)");
              }
              break;
            case "dark":
              rule.media.appendMedium("(prefers-color-scheme: light)");
              rule.media.appendMedium("(prefers-color-scheme: dark)");
              if (rule.media.mediaText.includes("original")) {
                rule.media.deleteMedium("original-prefers-color-scheme");
              }
              break;
            default:
              rule.media.appendMedium("(prefers-color-scheme: dark)");
              if (rule.media.mediaText.includes("light")) {
                rule.media.deleteMedium("(prefers-color-scheme: light)");
              }
              if (rule.media.mediaText.includes("original")) {
                rule.media.deleteMedium("original-prefers-color-scheme");
              }
              break;
          }
        }
      } catch (e) {
        console.debug(e);
      }
    }
  }
}

onMounted(() => {
  try {
    applyPreferredColorScheme(selectedTheme.value);
  } catch (e) {
  }
})

watch(selectedTheme, (value) => {
  try {
    applyPreferredColorScheme(value);
  } catch (e) {
  }
})
</script>
