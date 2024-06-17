<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
        <NuxtLink to="/backends" v-text="'Backends'"/>
        -
        <NuxtLink :to="'/backend/' + id" v-text="id"/>
        : Delete
      </span>

      <div class="is-pulled-right">
        <div class="field is-grouped"></div>
      </div>

      <div class="is-hidden-mobile">
        <span class="subtitle">Delete backend configuration and records.</span>
      </div>
    </div>

    <template v-if="isDeleting">
      <div class="column is-12">
        <Message message_class="has-background-warning-90 has-text-dark" title="Deleting..."
                 icon="fas fa-spin fa-exclamation-triangle" message="Delete operation is in progress. Please wait..."/>
      </div>
    </template>
    <template v-else-if="isLoading">
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
      </div>
    </template>
    <template v-else>
      <div class="column is-12" v-if="error">
        <Message message_class="is-background-warning-80 has-text-dark" title="Error" icon="fas fa-exclamation-circle"
                 :use-close="true" @close="navigateTo('/backends')"
                 :message="`${error.error.code}: ${error.error.message}`"/>
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
          <p class="is-bold">
            Depending on your hardware speed, the delete operation might take long time. do not interrupt the process,
            or close the browser tab. You will be redirected to the backends page automatically once the process is
            complete. Otherwise, you might end up with a corrupted database.
          </p>

          <div class="columns is-mobile">
            <div class="column is-6">
              <p class="control " v-if="!isDeleting">
                <NuxtLink to="/backends" class="button is-fullwidth is-primary">
                  <span class="icon"><i class="fas fa-cancel"></i></span>
                  <span>Cancel</span>
                </NuxtLink>
              </p>
            </div>
            <div class="column">
              <p class="control">
                <button class="button is-danger is-fullwidth" @click="deleteBackend">
                  <span class="icon"><i class="fas fa-trash"></i></span>
                  <span>Yes, Delete it!</span>
                </button>
              </p>
            </div>
          </div>
        </Message>
      </div>
    </template>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request.js'
import Message from "~/components/Message.vue";

const id = useRoute().params.backend
const error = ref()
const type = ref('')
const isLoading = ref(false)
const isDeleting = ref(false)

const loadBackend = async () => {
  isLoading.value = true
  try {
    const response = await request(`/backend/${id}`)
    let json;
    try {
      json = await response.json()
    } catch (e) {
      json = {
        error: {
          code: response.status,
          message: response.statusText
        }
      }
    }

    if (200 !== response.status) {
      error.value = json

      return
    }

    type.value = json.type
  } catch (e) {
    error.value = {
      error: {
        code: 500,
        message: e.message
      }
    }
  } finally {
    isLoading.value = false
  }
}

const deleteBackend = async () => {
  if (!confirm('Last chance! Are you sure you want to delete the backend?')) {
    return
  }

  isDeleting.value = true

  try {
    const response = await request(`/backend/${id}`, {
      method: 'DELETE'
    })

    let json;
    try {
      json = await response.json()
    } catch (e) {
      json = {
        error: {
          code: response.status,
          message: response.statusText
        }
      }
    }

    if (200 !== response.status) {
      error.value = json
      return
    }

    notification('success', 'Success', `Backend '${id}' has been deleted. Deleted References: ${json.deleted.references} records: ${json.deleted.records}`)
    await navigateTo('/backends')
  } catch (e) {
    error.value = {
      error: {
        code: 500,
        message: e.message
      }
    }
  } finally {
    isDeleting.value = false
  }
}

onMounted(async () => await loadBackend())
</script>
