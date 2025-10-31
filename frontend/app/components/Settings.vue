<template>
  <div class="columns is-multiline mb-2">
    <div class="column is-6 is-12-mobile">
      <form class="card">
        <header class="card-header">
          <p class="card-header-title">Password & Sessions</p>
          <span class="card-header-icon"><span class="icon"><i class="fas fa-cog"/></span></span>
        </header>
        <div class="card-content">
          <input type="text" class="is-hidden" name="username" autocomplete="username" :value="username"/>

          <div class="field">
            <label class="label" for="current_password">Current password</label>
            <div class="control has-icons-left">
              <input id="current_password" type="password" class="input" v-model="user.current_password"
                     :disabled="isLoading" placeholder="Current password" autocomplete="current-password" required>
              <span class="icon is-left"><i class="fa fa-lock"/></span>
            </div>
          </div>

          <div class="field">
            <label class="label" for="new_password">New Password</label>
            <div class="control has-icons-left">
              <input id="new_password" type="password" class="input" v-model="user.new_password" :disabled="isLoading"
                     placeholder="New password" autocomplete="new-password" required>
              <span class="icon is-left"><i class="fa fa-lock"/></span>
            </div>
          </div>

          <div class="field">
            <label class="label" for="new_password_confirm">Confirm New Password</label>
            <div class="control has-icons-left">
              <input id="new_password_confirm" type="password" class="input" v-model="user.new_password_confirm"
                     :disabled="isLoading" autocomplete="new-password" placeholder="Confirm new password" required>
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
      </form>
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
          </div>

          <div class="field">
            <label class="is-unselectable label">
              Backgrounds from backends
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
          </div>

          <div class="field">
            <label class="label is-unselectable" for="random_bg_opacity">
              Background Visibility: (<code>{{ (1.0 - parseFloat(String(bg_opacity))).toFixed(2) }}</code>)
            </label>
            <div class="control">
              <input id="random_bg_opacity" style="width: 100%" type="range" v-model="bg_opacity" min="0.60" max="1.00"
                     step="0.05">
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref} from 'vue'
import {useStorage} from '@vueuse/core'
import {request, parse_api_response, notification} from '~/utils'
import {navigateTo} from '#app'
import {useDialog} from '~/composables/useDialog'
import {useAuthStore} from '~/store/auth'


const emit = defineEmits<{
  (e: 'force_bg_reload'): void
}>()

const {username} = useAuthStore()

const webui_theme = useStorage<string>('theme', 'auto')
const bg_enable = useStorage<boolean>('bg_enable', true)
const poster_enable = useStorage<boolean>('poster_enable', true)
const bg_opacity = useStorage<number>('bg_opacity', 0.95)

const defaultValues = () => ({
  current_password: '',
  new_password: '',
  new_password_confirm: ''
})

const user = ref<{
  current_password: string
  new_password: string
  new_password_confirm: string
}>(defaultValues())

const isLoading = ref<boolean>(false)

const change_password = async (): Promise<void> => {
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
    user.value = defaultValues()
  } finally {
    isLoading.value = false
  }
}

const invalidate_sessions = async (): Promise<void> => {
  const {status} = await useDialog().confirmDialog({
    title: 'Invalidate All Sessions',
    message: 'This will log out all users including yourself. You will need to log in again. Do you want to continue?',
    confirmColor: 'is-danger',
  })

  if (true !== status) {
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
    const token = useStorage<string | null>('token', null)
    token.value = null
    await navigateTo('/auth')
  } finally {
    isLoading.value = false
  }
}
</script>
