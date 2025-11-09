<template>
  <div>
    <div class="field">
      <label class="label" for="user">Browse as</label>
      <div class="control has-icons-left">
        <div class="select is-fullwidth">
          <select v-model="api_user" class="is-capitalized" :disabled="isLoading">
            <option v-for="user in users" :key="'user-'+user" :value="user">
              {{ user }}
            </option>
          </select>
        </div>
        <div class="icon is-left">
          <i class="fas fa-user"></i>
        </div>
      </div>
      <p class="has-text-danger">
        <span class="icon"><i class="fas fa-exclamation"/></span>
        Browse the WebUI as the selected user. Not all API endpoints support non-main user.
      </p>
    </div>
    <div class="control has-text-right">
      <div class="field is-grouped is-grouped-right">
        <div class="control">
          <button type="submit" class="button is-primary" :disabled="!api_user || isLoading" @click="reloadPage">
            <span class="icon"><i class="fas fa-sync"/></span>
            <span>Reload</span>
          </button>
        </div>
        <div class="control">
          <button type="button" class="button is-info" @click="gotoUsers">
            <span class="icon"><i class="fas fa-users"/></span>
            <span>Users management</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted} from 'vue'
import {useStorage} from '@vueuse/core'
import {request, notification} from '~/utils'

const api_user = useStorage<string>('api_user', 'main')
const users = ref<Array<string>>(['main'])
const isLoading = ref<boolean>(true)

const emitter = defineEmits<{
  (e: 'close'): void
}>()

onMounted(async (): Promise<void> => {
  try {
    const response = await request('/system/users')
    if (!response.ok) {
      notification('error', 'Error', 'Failed to fetch users.')
      users.value = [api_user.value]
      return
    }
    const json = await response.json()
    if ('users' in json) {
      (json.users as Array<{ user: string }>).forEach(user => {
        const username = user.user
        if (!users.value.includes(username)) {
          users.value.push(username)
        }
      })
    }
  } catch (e) {
    notification('error', 'Error', `Failed to fetch users. ${e}`)
  } finally {
    isLoading.value = false
  }
})

const reloadPage = async () => {
  await emitter('close')
  if ('/' === useRoute().path) {
    window.location.reload()
    return
  }
  await navigateTo('/')
}
const gotoUsers = async () => {
  await emitter('close')
  await navigateTo('/users')
}
</script>
