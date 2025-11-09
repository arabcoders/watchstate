<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-users"/></span>
          Users Management
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" @click="toggleForm = !toggleForm" :disabled="isLoading">
                <span class="icon"><i class="fas fa-add"/></span>
                <span>Add User</span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">Manage users and their backend configurations.</span>
        </div>
      </div>

      <div class="column is-12" v-if="toggleForm">
        <form @submit.prevent="addUser">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title">Add New User</p>
              <button type="button" class="card-header-icon" @click="toggleForm = false">
                <span class="icon"><i class="fas fa-times"/></span>
              </button>
            </header>
            <div class="card-content">
              <div class="field" v-if="formError">
                <Message title="Error" message_class="has-background-danger-80 has-text-dark"
                         icon="fas fa-exclamation-triangle" useClose @close="formError = null">
                  <p>{{ formError }}</p>
                </Message>
              </div>

              <div class="field">
                <label class="label">Username</label>
                <div class="control has-icons-left">
                  <input class="input" type="text" v-model="newUsername" required
                         placeholder="Enter username (lowercase a-z, 0-9, _)">
                  <div class="icon is-left">
                    <i class="fas fa-user"/>
                  </div>
                </div>
                <p class="help">
                  Username must be unique and only contain lowercase letters (a-z), numbers (0-9), and underscores (_).
                </p>
              </div>
            </div>
            <footer class="card-footer">
              <span class="card-footer-item">
                <button type="button" class="button is-fullwidth is-warning" @click="cancelAddUser">
                  <span class="icon"><i class="fas fa-cancel"/></span>
                  <span>Cancel</span>
                </button>
              </span>
              <span class="card-footer-item">
                <button type="submit" class="button is-fullwidth is-primary" :disabled="isAdding"
                        :class="{ 'is-loading': isAdding }">
                  <span class="icon"><i class="fas fa-check"/></span>
                  <span>Add User</span>
                </button>
              </span>
            </footer>
          </div>
        </form>
      </div>

      <template v-else>
        <div class="column is-12" v-if="users.length < 1">
          <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                   icon="fas fa-spinner fa-spin" message="Loading users. Please wait..."/>
          <Message v-else message_class="has-background-warning-90 has-text-dark" title="No Users Found"
                   icon="fas fa-info-circle">
            No users found. You can add new user by
            <NuxtLink @click="toggleForm = true">clicking here</NuxtLink>
            or by clicking the <span class="icon is-clickable" @click="toggleForm = true"><i
              class="fas fa-add"/></span> button above.
          </Message>
        </div>

        <div v-for="user in users" :key="user.user" class="column is-6-tablet is-12-mobile">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title">
                <span class="icon"><i class="fas fa-user"/></span>
                <span :class="{'has-text-danger':user.user === 'main'}">&nbsp;{{ ucFirst(user.user) }}</span>
              </p>
              <div class="card-header-icon">
                <div class="field is-grouped">
                  <div class="control">
                    <NuxtLink :to="`/users/${user.user}/edit?redirect=/users`">
                      <span class="icon has-text-warning"><i class="fas fa-cog"/></span>
                      <span class="is-hidden-mobile">Edit</span>
                    </NuxtLink>
                  </div>
                  <div class="control" v-if="user.user !== 'main'">
                    <NuxtLink :to="`/users/${user.user}/delete?redirect=/users`">
                      <span class="icon has-text-danger"><i class="fas fa-trash"/></span>
                      <span class="is-hidden-mobile">Delete</span>
                    </NuxtLink>
                  </div>
                </div>
              </div>
            </header>
            <div class="card-content">
              <div class="columns is-multiline">
                <div class="column is-12">
                  <strong>Backends:&nbsp;</strong>
                  <template v-if="user.backends.length > 0">
                    <NuxtLink class="tag is-link is-light mr-1" v-for="backend in user.backends" :key="backend"
                              @click="user_link(user.user, `/backend/${backend}`)">
                      {{ backend }}
                    </NuxtLink>
                  </template>
                  <template v-else>
                    <span class="tag is-warning is-light">No backends configured</span>
                  </template>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Tips Section -->
        <div class="column is-12">
          <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                   @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips"
                   icon="fas fa-info-circle">
            <ul>
              <li>
                The <strong>main</strong> user is the primary user and cannot be deleted.
              </li>
              <li>
                Each user can have their own set of backends configured independently.
              </li>
              <li>
                Server configurations are validated against the system specification before saving. While this may help
                prevent misconfigurations, it's recommended to double-check configurations manually. The validation is
                not foolproof and may miss certain issues.
              </li>
            </ul>
          </Message>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import {onMounted, ref} from 'vue'
import {navigateTo, useHead, useRoute} from '#app'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message.vue'
import {notification, request, ucFirst} from '~/utils'
import type {UserListItem} from '~/types'

useHead({title: 'Users Management'})

const users = ref<Array<UserListItem>>([])
const toggleForm = ref<boolean>(false)
const isLoading = ref<boolean>(false)
const isAdding = ref<boolean>(false)
const newUsername = ref<string>('')
const formError = ref<string | null>(null)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async (): Promise<void> => {
  users.value = []
  isLoading.value = true

  try {
    const response = await request('/users')
    const json = await response.json()

    if ('users' !== useRoute().name) {
      return
    }

    users.value = json.users || []
    useHead({title: 'Users Management'})
  } catch (e) {
    const error = e as Error
    notification('error', 'Error', `Failed to load users. ${error.message}`)
  } finally {
    isLoading.value = false
  }
}

const addUser = async (): Promise<void> => {
  if (true === isAdding.value) {
    return
  }

  formError.value = null
  const username = newUsername.value.trim().toLowerCase()

  if (0 === username.length) {
    formError.value = 'Please enter a username'
    return
  }

  isAdding.value = true

  try {
    const response = await request('/users', {
      method: 'POST',
      body: JSON.stringify({user: username})
    })

    if (false === response.ok) {
      const json = await response.json()
      formError.value = json.error?.message || 'Failed to create user'
      return
    }

    notification('success', 'Success', `User '${username}' created successfully`)
    toggleForm.value = false
    newUsername.value = ''
    await loadContent()
  } catch (e) {
    const error = e as Error
    formError.value = `Failed to create user. ${error.message}`
  } finally {
    isAdding.value = false
  }
}

const cancelAddUser = (): void => {
  toggleForm.value = false
  newUsername.value = ''
  formError.value = null
}

const user_link = async (user: string, url: string): Promise<void> => {
  const api_user = useStorage('api_user', 'main')
  api_user.value = user || 'main'
  await nextTick()
  await navigateTo(url)
}

onMounted(() => loadContent())

</script>

