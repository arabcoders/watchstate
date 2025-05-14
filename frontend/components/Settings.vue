<template>
  <div class="columns is-multiline mb-2">
    <div class="column">
      <div class="card">
        <header class="card-header">
          <p class="card-header-title">
            WebUI Look & Feel
          </p>
          <span class="card-header-icon">
            <span class="icon"><i class="fas fa-paint-brush"/></span>
          </span>
        </header>
        <div class="card-content">
          <div class="field">
            <label class="label" for="random_bg">Color scheme</label>
            <div class="control">
              <label for="auto" class="radio">
                <input id="auto" type="radio" v-model="webui_theme" value="auto"> System Default
              </label>
              <label for="light" class="radio">
                <input id="light" type="radio" v-model="webui_theme" value="light"> Light
              </label>
              <label for="dark" class="radio">
                <input id="dark" type="radio" v-model="webui_theme" value="dark"> Dark
              </label>
            </div>
            <p class="help">
              <span class="icon"><i class="fa-solid fa-info"/></span>
              <span>Select the color scheme for the WebUI.</span>
            </p>
          </div>

          <div class="field">
            <label class="label" for="random_bg">Backgrounds</label>
            <div class="control">
              <input id="random_bg" type="checkbox" class="switch is-success" v-model="bg_enable">
              <label for="random_bg">Enable</label>
              <p class="help">Use random background image from your media backends.</p>
            </div>
          </div>

          <div class="field">
            <label class="label" for="random_bg_opacity">
              Background Visibility: (<code>{{ bg_opacity }}</code>)
            </label>
            <div class="control">
              <input id="random_bg_opacity" style="width: 100%" type="range" v-model="bg_opacity" min="0.60"
                     max="1.00" step="0.05">
              <p class="help">How visible the background image should be.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'

const webui_theme = useStorage('theme', 'auto')
const bg_enable = useStorage('bg_enable', true)
const bg_opacity = useStorage('bg_opacity', 0.95)
</script>
