<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-redo"></i></span>
        System reset
      </span>

      <div class="is-pulled-right">
        <div class="field is-grouped"></div>
      </div>

      <div class="is-hidden-mobile">
        <span class="subtitle">Reset the system state.</span>
      </div>
    </div>

    <div class="column is-12" v-if="error">
      <Message message_class="is-background-warning-80 has-text-dark" title="Error" icon="fas fa-exclamation-circle"
               :use-close="true" @close="navigateTo('/backends')"
               :message="`${error.error.code}: ${error.error.message}`"/>
    </div>

    <template v-if="isResetting">
      <div class="column is-12">
        <Message message_class="has-background-warning-90 has-text-dark" title="Working..."
                 icon="fas fa-spin fa-exclamation-triangle" message="Reset in progress, Please wait..."/>
      </div>
    </template>
    <template v-else>
      <div class="column is-12">
        <Message message_class="is-background-warning-80 has-text-dark" title="Important information"
                 icon="fas fa-exclamation-triangle">
          <p>
            Are you sure you want to reset the system state? This operation will remove all records and metadata from
            the database. This action is irreversible.
          </p>

          <h5 class="has-text-dark">This operation will do the following</h5>

          <ul>
            <li>Remove all data from local database.</li>
            <li>Attempt to flush the cache.</li>
            <li>Reset the backends last sync date.</li>
          </ul>

          <p>There is no undo operation. This action is irreversible.</p>
        </Message>
      </div>

      <div class="column is-12">
        <form @submit.prevent="resetSystem()">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title">System reset</p>
              <p class="card-header-icon"><span class="icon"><i class="fas fa-redo"></i></span></p>
            </header>
            <div class="card-content">
              <div class="field">
                <label class="label">
                  To confirm, please write '<code>{{ random_secret }}</code>' in the box below.
                </label>
                <div class="control">
                  <input class="input" type="text" v-model="user_secret" placeholder="Enter the secret key"/>
                </div>
                <p class="help">
                  <span class="icon has-text-warning">
                    <i class="fas fa-info-circle"></i>
                  </span>
                  Depending on your hardware speed, the reset operation might take long time. do not interrupt the
                  process, or close the browser tab. You will be redirected to the index page automatically once the
                  process is complete. Otherwise, you might end up with a corrupted database and/or state.
                </p>
              </div>
            </div>
            <footer class="card-footer">
              <div class="card-footer-item">
                <NuxtLink to="/" class="button is-fullwidth is-primary">
                  <span class="icon"><i class="fas fa-cancel"></i></span>
                  <span>Cancel</span>
                </NuxtLink>
              </div>
              <div class="card-footer-item">
                <button class="button is-danger is-fullwidth" type="submit" :disabled="user_secret !== random_secret">
                  <span class="icon"><i class="fas fa-redo"></i></span>
                  <span>Proceed</span>
                </button>
              </div>
            </footer>
          </div>
        </form>
      </div>
    </template>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import Message from '~/components/Message'
import {makeSecret, notification} from '~/utils/index'

const error = ref()
const isResetting = ref(false)
const random_secret = ref('')
const user_secret = ref('')

const resetSystem = async () => {
  if (user_secret.value !== random_secret.value) {
    notification('error', 'Error', 'Invalid secret key. Please try again.')
    return
  }

  if (!confirm('Last chance! Are you sure you want to reset the system state?')) {
    return
  }

  isResetting.value = true

  try {
    const response = await request(`/system/reset`, {method: 'DELETE'})

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

    notification('success', 'Success', `System has been successfully reset.`)
    await navigateTo('/')
  } catch (e) {
    error.value = {
      error: {
        code: 500,
        message: e.message
      }
    }
  } finally {
    isResetting.value = false
  }
}

onMounted(() => {
  random_secret.value = makeSecret(8)
})
</script>
