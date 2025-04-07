<template>
  <div>
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
              Are you sure you want to reset <span class="has-text-danger is-bold is-underlined">all users</span> local
              state?
            </p>

            <h5 class="has-text-dark">This operation will do the following:</h5>

            <ul>
              <li>Remove all data from local databases.</li>
              <li>Flush to cached data.</li>
              <li>Reset all users backends last sync date.</li>
            </ul>

            <p class="is-underlined is-bold">There is no undo operation. This action is irreversible.</p>
          </Message>
        </div>

        <div class="column is-12">
          <Confirm @confirmed="resetSystem()" :title="`Perform local state reset for all users`"
                   title-icon="fa-redo"
                   warning="Depending on your hardware speed, the reset operation might take long time. do not interrupt the process, or close the browser tab. You will be redirected to the index page automatically once the process is complete. Otherwise, you might end up with a corrupted database and/or state."
          />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import Message from '~/components/Message'
import {notification} from '~/utils/index'
import Confirm from '~/components/Confirm'
import {useSessionCache} from '~/utils/cache'

const error = ref()
const isResetting = ref(false)
const api_user = useStorage('api_user', 'main')

const resetSystem = async () => {
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

    if (useRoute().name !== 'reset') {
      return
    }

    if (200 !== response.status) {
      error.value = json
      return
    }

    notification('success', 'Success', `System has been successfully reset.`)
    await navigateTo('/')

    // -- remove all session storage due to the reset.
    try {
      useSessionCache().clear()
    } catch (e) {
    }
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
</script>
