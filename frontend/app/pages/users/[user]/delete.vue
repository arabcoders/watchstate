<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-users"/>&nbsp;</span>
          <NuxtLink to="/users">Users</NuxtLink>
          - {{ id }} : Delete
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped"></div>
        </div>

        <div class="is-hidden-mobile">
          <span class="subtitle">Delete user and all their backend configurations.</span>
        </div>
      </div>

      <template v-if="isDeleting">
        <div class="column is-12">
          <Message message_class="has-background-warning-90 has-text-dark" title="Deleting..."
                   icon="fas fa-spin fa-exclamation-triangle"
                   message="Delete operation is in progress. Please wait..."/>
        </div>
      </template>
      <template v-else-if="isLoading">
        <div class="column is-12">
          <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
                   message="Loading data. Please wait..."/>
        </div>
      </template>
      <template v-else>
        <div class="column is-12" v-if="error">
          <Message message_class="has-background-warning-90 has-text-dark" title="Error"
                   icon="fas fa-exclamation-circle"
                   :use-close="true" @close="navigateTo('/users')"
                   :message="`${error.error.code}: ${error.error.message}`"/>
        </div>
        <div class="column is-12" v-else-if="id === 'main'">
          <Message :newStyle="true" message_class="is-danger" title="Action is not permitted"
                   icon="fas fa-exclamation-triangle"
                   :useClose="true" @close="navigateTo('/users')"
          >
            <p>The <strong>main</strong> user cannot be deleted as it is the primary user.</p>
          </Message>
        </div>
        <div class="column is-12" v-else>
          <Message message_class="is-warning" title="Confirmation is required"
                   icon="fas fa-exclamation-triangle" :newStyle="true">
            <p>Are you sure you want to delete the user <code>{{ id }}</code> and all their backend configurations?</p>

            <h5 class="has-text-dark">This operation will do the following</h5>

            <ul>
              <li>Remove all user data.</li>
              <li v-if="backends.length > 0">
                Delete <strong>{{ backends.length }}</strong> backend{{ backends.length > 1 ? 's' : '' }}:
                <span v-for="(backend, index) in backends" :key="backend">
                  <code>{{ backend }}</code>
                  <template v-if="index < backends.length - 1">,</template>
                </span>
              </li>
            </ul>

            <u class="has-text-danger is-bold">There is no undo operation. This action is irreversible.</u>
          </Message>

          <Confirm @confirmed="deleteUser()" title="Delete user" title-icon="fa-trash"
                   warning="This will permanently delete the user and all their backend configurations. The operation cannot be undone."/>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import {onMounted, ref} from 'vue'
import {navigateTo, useRoute} from '#app'
import '~/assets/css/bulma-switch.css'
import Message from '~/components/Message.vue'
import Confirm from '~/components/Confirm.vue'
import {notification, parse_api_response, request} from '~/utils'
import {useDialog} from '~/composables/useDialog'
import type {GenericError, UserListItem} from '~/types'

const id = useRoute().params.user as string
const error = ref<GenericError | null>(null)
const backends = ref<Array<string>>([])
const isLoading = ref<boolean>(false)
const isDeleting = ref<boolean>(false)

const loadUser = async (): Promise<void> => {
  try {
    isLoading.value = true

    // Load user list to get backend info
    const response = await request('/users')
    const data = await parse_api_response<{ users: Array<UserListItem> }>(response)

    if ('error' in data) {
      error.value = data
      return
    }

    const user = data.users.find(u => u.user === id)
    if (user) {
      backends.value = user.backends
    } else {
      error.value = {
        error: {code: 404, message: 'User not found'}
      } as GenericError
    }
  } catch (e) {
    error.value = {
      error: {code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred'}
    } as GenericError
  } finally {
    isLoading.value = false
  }
}

const deleteUser = async (): Promise<void> => {
  const {status: confirmStatus} = await useDialog().confirmDialog({
    title: 'Last Chance!',
    message: `This action is irreversible. Delete '${id}' data?`,
    confirmColor: 'is-danger'
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    isDeleting.value = true

    const response = await request(`/users/${id}`, {method: 'DELETE'})

    if (200 !== response.status) {
      error.value = await parse_api_response(response)
      return
    }

    notification('success', 'Success', `User '${id}' has been deleted successfully`)
    await navigateTo('/users')
  } catch (e) {
    error.value = {
      error: {code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred'}
    } as GenericError
  } finally {
    isDeleting.value = false
  }
}

onMounted(async (): Promise<void> => await loadUser())
</script>

