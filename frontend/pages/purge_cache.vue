<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-trash"/></span>
          Purge Cache
        </span>
      </div>

      <div class="column is-12" v-if="error">
        <Message message_class="is-background-danger-80 has-text-dark" title="Error" icon="fas fa-exclamation-circle"
                 :use-close="true" @close="error = null"
                 :message="`${error?.error.code}: ${error?.error.message}`"/>
      </div>

      <template v-if="isPurging">
        <div class="column is-12">
          <Message message_class="has-background-warning-90 has-text-dark" title="Working..."
                   icon="fas fa-spin fa-exclamation-triangle" message="Cache purging in progress, Please wait..."/>
        </div>
      </template>
      <template v-else>
        <div class="column is-12">
          <Message message_class="is-background-warning-80 has-text-dark" title="Important information"
                   icon="fas fa-exclamation-triangle">
            <p>This operation will purge the cache for all users.</p>
            <p class="is-underlined is-bold">There is no undo operation. This action is irreversible.</p>
          </Message>
        </div>

        <div class="column is-12">
          <Confirm @confirmed="resetCache()" :title="`Perform purge cache reset for all users?`"
                   title-icon="fa-trash"
                   warning="Depending on your hardware speed, the reset operation might take long time. do not interrupt the process, or close the browser tab."/>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request'
import Message from '~/components/Message'
import {notification} from '~/utils/index'
import Confirm from '~/components/Confirm'
import {useSessionCache} from '~/utils/cache'

const error = ref()
const isPurging = ref(false)

const resetCache = async () => {
  isPurging.value = true

  try {
    error.value = null
    const response = await request(`/system/cache`, {method: 'DELETE'})
    const json = await parse_api_response(response)

    if (useRoute().name !== 'purge_cache') {
      return
    }

    if (200 !== response.status) {
      error.value = json
      return
    }

    notification('success', 'Success', `System Cache has been purged.`)
    await navigateTo('/')

    // -- remove all session storage due to the reset.
    try {
      useSessionCache().clear()
    } catch (e) {
    }
  } catch (e) {
    error.value = {error: {code: 500, message: e.message}}
  } finally {
    isPurging.value = false
  }
}
</script>
