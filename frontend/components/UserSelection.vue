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
        Browse the WebUI as the selected user. This feature is new and not all endpoints supports it yet, over time we
        plan to add support for this feature to all endpoints. If the endpoint doesn't support this feature, the main
        user will be used instead.
      </p>
    </div>
    <div class="control has-text-right">
      <button type="submit" class="button is-primary" :disabled="!api_user || isLoading" @click="reloadPage">
        <span class="icon"><i class="fas fa-sync"/></span>
        <span>Reload</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'
import request from '~/utils/request'
import {notification} from "~/utils/index.js";

const api_user = useStorage('api_user', 'main')
const users = ref(['main'])
const isLoading = ref(true)


onMounted(async () => {
  try {
    isLoading.value = true
    const response = await request('/system/users');
    if (!response.ok) {
      notification('error', 'Failed to fetch users.');
      return;
    }
    const json = await response.json();
    if ('users' in json) {
      users.value = json?.users;
    }
  } catch (e) {
    notification('error', `Failed to fetch users. ${e}`);
  } finally {
    isLoading.value = false
  }
})

const reloadPage = () => window.location.reload()
</script>
