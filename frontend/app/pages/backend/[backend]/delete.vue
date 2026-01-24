<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server"/>&nbsp;</span>
          <NuxtLink to="/backends">Backends</NuxtLink>
          -
          <NuxtLink :to="`/backend/${id}`">{{ id }}</NuxtLink> : Delete
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped"></div>
        </div>

        <div class="is-hidden-mobile">
          <span class="subtitle">Delete backend configuration and data.</span>
        </div>
      </div>

      <template v-if="isDeleting">
        <div class="column is-12">
          <Message message_class="has-background-warning-90 has-text-dark" title="Deleting..."
            icon="fas fa-spin fa-exclamation-triangle" message="Delete operation is in progress. Please wait..." />
        </div>
      </template>
      <template v-else-if="isLoading">
        <div class="column is-12">
          <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
            message="Loading data. Please wait..." />
        </div>
      </template>
      <template v-else>
        <div class="column is-12" v-if="error">
          <Message message_class="is-background-warning-80 has-text-dark" title="Error" icon="fas fa-exclamation-circle"
            :use-close="true" @close="navigateTo('/backends')"
            :message="`${error.error.code}: ${error.error.message}`" />
        </div>
        <div class="column is-12" v-else>
          <Message message_class="is-background-warning-80 has-text-dark" title="Confirmation is required"
            icon="fas fa-exclamation-triangle">
            <p>Are you sure you want to delete the backend <code>{{ type }}: {{ id }}</code> configuration and all its
              records?</p>

            <h5 class="has-text-dark">This operation will do the following</h5>

            <ul>
              <li>Remove records metadata that references the given backend.</li>
              <li>Run data integrity check to remove no longer used records.</li>
              <li>Update <code>servers.yaml</code> file and remove backend configuration.</li>
            </ul>

            <p>There is no undo operation. This action is irreversible.</p>
          </Message>

          <Confirm @confirmed="deleteBackend()" title="Delete backend" title-icon="fa-trash" warning="Depending on your hardware speed, the delete operation might take long time. do not interrupt the
                  process, or close the browser tab. You will be redirected to the backends page automatically once the
                  process is complete. Otherwise, you might end up with a corrupted database." />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, navigateTo } from '#app'
import '~/assets/css/bulma-switch.css'
import Message from '~/components/Message.vue'
import Confirm from '~/components/Confirm.vue'
import { request, notification, parse_api_response } from '~/utils'
import type { Backend, GenericError } from '~/types'

const id = useRoute().params.backend as string
const error = ref<GenericError | null>(null)
const type = ref<string>('')
const isLoading = ref<boolean>(false)
const isDeleting = ref<boolean>(false)

const loadBackend = async (): Promise<void> => {
  try {
    isLoading.value = true
    const response = await request(`/backend/${id}`)
    const data = await parse_api_response<Backend>(response)

    if ('error' in data) {
      error.value = data
      return
    }

    type.value = data.type
  } catch (e) {
    error.value = {
      error: { code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred' }
    } as GenericError
  } finally {
    isLoading.value = false
  }
}

const deleteBackend = async (): Promise<void> => {
  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Last Chance!',
    message: `This action is irreversible. Are you sure you want to delete the backend '${id}'?`,
    confirmColor: 'is-danger'
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    isDeleting.value = true

    const response = await request(`/backend/${id}`, { method: 'DELETE' })
    const data = await parse_api_response<{ deleted: { references: number, records: number, } }>(response)

    if ('error' in data) {
      error.value = data
      return
    }

    notification('success', 'Success', `Backend '${id}' has been deleted. Deleted References: ${data.deleted.references} records: ${data.deleted.records}`)
    await navigateTo('/backends')
  } catch (e) {
    error.value = {
      error: { code: 500, message: e instanceof Error ? e.message : 'Unknown error occurred' }
    } as GenericError
  } finally {
    isDeleting.value = false
  }
}

onMounted(async (): Promise<void> => await loadBackend())
</script>
