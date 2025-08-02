<template>
  <div class="columns is-multiline mb-2">
    <div class="column is-6 is-12-mobile">
      <div class="card">
        <header class="card-header">
          <p class="card-header-title">Password & Sessions</p>
          <span class="card-header-icon"><span class="icon"><i class="fas fa-cog"/></span></span>
        </header>
        <div class="card-content">
          <div class="field">
            <label class="label" for="current_password">Current password</label>
            <div class="control has-icons-left">
              <input id="current_password" type="password" class="input" v-model="user.current_password"
                     :disabled="isLoading" placeholder="Current password" required>
              <span class="icon is-left"><i class="fa fa-lock"/></span>
            </div>
          </div>

          <div class="field">
            <label class="label" for="new_password">New Password</label>
            <div class="control has-icons-left">
              <input id="new_password" type="password" class="input" v-model="user.new_password" :disabled="isLoading"
                     placeholder="New password" required>
              <span class="icon is-left"><i class="fa fa-lock"/></span>
            </div>
          </div>

          <div class="field">
            <label class="label" for="new_password_confirm">Confirm New Password</label>
            <div class="control has-icons-left">
              <input id="new_password_confirm" type="password" class="input" v-model="user.new_password_confirm"
                     :disabled="isLoading" placeholder="Confirm new password" required>
              <span class="icon is-left"><i class="fa fa-lock"/></span>
            </div>
          </div>

          <div class="field is-grouped">
            <div class="control is-expanded">
              <button type="button" class="button is-fullwidth is-primary" @click="change_password"
                      :disabled="isLoading" :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fa-solid fa-key"/></span>
                <span>Change Password</span>
              </button>
            </div>
            <div class="control is-expanded">
              <button type="button" class="button is-fullwidth is-danger" @click="invalidate_sessions"
                      :disabled="isLoading">
                <span class="icon"><i class="fa-solid fa-user-slash"/></span>
                <span>Invalidate Sessions</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="column is-6 is-12-mobile">
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
            <label class="label is-unselectable">Color scheme</label>
            <div class="control">
              <label for="auto" class="radio">
                <input id="auto" type="radio" v-model="webui_theme" value="auto">
                <span class="icon">
                  <i class="fa-solid fa-circle-half-stroke"/>
                </span>
                Auto
              </label>
              <label for="light" class="radio">
                <input id="light" type="radio" v-model="webui_theme" value="light">
                <span class="has-text-warning-80 icon"><i class="fa-solid fa-sun"/></span>
                <span>Light</span>
              </label>
              <label for="dark" class="radio">
                <input id="dark" type="radio" v-model="webui_theme" value="dark">
                <span class="icon"><i class="fa-solid fa-moon"/></span>
                Dark
              </label>
            </div>
            <p class="help is-unselectable">
              <span class="icon"><i class="fa-solid fa-info"/></span>
              <span>Select the color scheme for the WebUI.</span>
            </p>
          </div>

          <div class="field">
            <label class="is-unselectable label">
              Show posters
            </label>
            <div class="control">
              <input id="show_posters" type="checkbox" class="switch is-success" v-model="poster_enable">
              <label for="show_posters">
                {{ poster_enable ? 'Disable' : 'Enable' }}
              </label>
            </div>
            <p class="help is-unselectable">
              <span class="icon"><i class="fa-solid fa-info"/></span>
              <span>Display posters for episodes and movies in the item history cards.</span>
            </p>
          </div>

          <div class="field">
            <label class="is-unselectable label">
              Backgrounds
              <span v-if="bg_enable">
                -
                <NuxtLink @click="emit('force_bg_reload')">
                  <span class="icon"><i class="fa-solid fa-sync"/></span>
                  <span>Reload</span>
                </NuxtLink>
              </span>
            </label>
            <div class="control">
              <input id="random_bg" type="checkbox" class="switch is-success" v-model="bg_enable">
              <label for="random_bg">
                {{ bg_enable ? 'Disable' : 'Enable' }}
              </label>
            </div>
            <p class="help is-unselectable">
              <span class="icon"><i class="fa-solid fa-info"/></span>
              <span>Use random background image from your media backends. Images are cached for 1 hour.</span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable" for="random_bg_opacity">
              Background Visibility: (<code>{{ parseFloat(1.0 - bg_opacity).toFixed(2) }}</code>)
            </label>
            <div class="control">
              <input id="random_bg_opacity" style="width: 100%" type="range" v-model="bg_opacity" min="0.60" max="1.00"
                     step="0.05">
            </div>
            <p class="help is-unselectable">
              <span class="icon"><i class="fa-solid fa-info"/></span>
              <span>How visible the background image should be.</span>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'

const emit = defineEmits(['force_bg_reload'])
const webui_theme = useStorage('theme', 'auto')
const bg_enable = useStorage('bg_enable', true)
const poster_enable = useStorage('poster_enable', true)
const bg_opacity = useStorage('bg_opacity', 0.95)
const user = ref({
  current_password: '',
  new_password: '',
  new_password_confirm: ''
})
const isLoading = ref(false)

const change_password = async () => {
  if (!user.value.current_password || !user.value.new_password || !user.value.new_password_confirm) {
    notification('Error', 'Error', 'All fields are required.', 2000)
    return
  }

  if (user.value.new_password !== user.value.new_password_confirm) {
    notification('Error', 'Error', 'New passwords do not match.', 2000)
    return
  }

  try {
    isLoading.value = true
    const response = await request('/system/auth/change_password', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        new_password: user.value.new_password,
        current_password: user.value.current_password,
      })
    })
    const json = await parse_api_response(response)
    if (200 !== response.status) {
      notification('Error', 'Error', json.error.message, 2000)
      return
    }

    notification('Success', 'Success', json.info.message)
    user.value = {
      current_password: '',
      new_password: '',
      new_password_confirm: ''
    }

  } finally {
    isLoading.value = false
  }
}

const invalidate_sessions = async () => {
  if (!confirm('Are you sure you want to invalidate all sessions?')) {
    return
  }

  try {
    isLoading.value = true
    const response = await request('/system/auth/sessions', {method: 'DELETE'})
    const json = await parse_api_response(response)

    if (200 !== response.status) {
      notification('Error', 'Error', json.error.message, 2000)
      return
    }

    notification('Success', 'Success', json.info.message)
    const token = useStorage('token', null)
    token.value = null
    await navigateTo('/auth')
  } finally {
    isLoading.value = false
  }
}

</script>
