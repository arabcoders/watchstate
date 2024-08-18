<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-calendar-alt"></i>&nbsp;</span>
          <NuxtLink to="/events" v-text="'Events'"/>
          : {{ makeName(id) }}
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">

            <p class="control">
              <button class="button is-warning" @click="resetEvent(0 === item.status ? 4 : 0)"
                      :disabled="1 === item.status">
                <span class="icon">
                  <i class="fas" :class="{'fa-trash-arrow-up': 0!== item.status, 'fa-power-off': 0=== item.status}"></i>
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-danger" @click="deleteItem" :disabled="1 === item.status">
                <span class="icon"><i class="fas fa-trash"></i></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent()" :class="{'is-loading': isLoading}"
                      :disabled="isLoading">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle"></span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
      </div>
    </div>

    <div v-if="!isLoading" class="columns is-multiline">
      <div class="column is-12">
        <div class="notification">
          <p class="title is-5">
            Event <span class="tag is-info">{{ item.event }}</span> was created at
            <span class="tag is-warning">
              <time class="has-tooltip" v-tooltip="moment(item.created_at).format(tooltip_dateformat)">
                {{ moment(item.created_at).fromNow() }}
              </time>
            </span>, and last updated at
            <span class="tag is-danger">
              <span v-if="!item.updated_at">not started</span>
              <time v-else class="has-tooltip" v-tooltip="moment(item.updated_at).format(tooltip_dateformat)">
                {{ moment(item.updated_at).fromNow() }}
              </time>
            </span>.
            with status of <span class="tag" :class="getStatusClass(item.status)">{{ item.status }}:
            {{ item.status_name }}</span>.
          </p>
        </div>
      </div>

      <div class="column is-12" v-if="Object.keys(item.event_data).length > 0">
        <h2 class="title is-4 is-clickable is-unselectable" @click="toggleData = !toggleData">
          <span class="icon">
            <i class="fas" :class="{ 'fa-arrow-down': !toggleData, 'fa-arrow-up': toggleData }"></i>
          </span>&nbsp;
          <span>Show attached data</span>
        </h2>
        <pre class="p-0 is-pre-wrap" v-if="toggleData"><code
            style="word-break: break-word" class="language-json">{{
            JSON.stringify(item.event_data, null, 2)
          }}</code></pre>
      </div>

      <div class="column is-12" v-if="item.logs">
        <h2 class="title is-4 is-clickable is-unselectable" @click="toggleLogs = !toggleLogs">
          <span class="icon">
            <i class="fas" :class="{ 'fa-arrow-down': !toggleLogs, 'fa-arrow-up': toggleLogs }"></i>
          </span>&nbsp;
          <span>Show event logs</span>
        </h2>
        <pre class="p-0 is-pre-wrap" v-if="toggleLogs"><code
            style="word-break: break-word" class="language-json">{{
            JSON.stringify(item.logs, null, 2)
          }}</code></pre>
      </div>
      <div class="column is-12" v-if="item.options">
        <h2 class="title is-4 is-clickable is-unselectable" @click="toggleOptions = !toggleOptions">
          <span class="icon">
            <i class="fas" :class="{ 'fa-arrow-down': !toggleOptions, 'fa-arrow-up': toggleOptions }"></i>
          </span>&nbsp;
          <span>Show attached options</span>
        </h2>
        <pre class="p-0 is-pre-wrap" v-if="toggleOptions"><code
            style="word-break: break-word" class="language-json">{{
            JSON.stringify(item.options, null, 2)
          }}</code></pre>
      </div>
    </div>
  </div>
</template>

<script setup>
import {notification, parse_api_response} from '~/utils/index'
import request from '~/utils/request'
import moment from 'moment'
import {getStatusClass, makeName} from '~/utils/events/helpers'
import {useStorage} from '@vueuse/core'

const route = useRoute()

const id = ref(route.query.id)

const isLoading = ref(true)
const item = ref({})

const toggleLogs = useStorage('events_toggle_logs', true)
const toggleData = useStorage('events_toggle_data', true)
const toggleOptions = useStorage('events_toggle_options', true)

onMounted(async () => {
  if (!id.value) {
    throw createError({
      statusCode: 404,
      message: 'Error ID not provided.'
    })
  }
  return await loadContent()
})

const loadContent = async () => {
  try {
    isLoading.value = true
    const response = await request(`/system/events/${id.value}`,)
    const json = await parse_api_response(response)

    if (200 !== response.status) {
      notification('error', 'Error', `Errors viewItem request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    item.value = json

    useHead({title: `Event: ${json.id}`})
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Errors viewItem Request failure. ${e.message}`
    )
  } finally {
    isLoading.value = false
  }
}

const deleteItem = async () => {
  if (!confirm(`Delete '${item.value.id}'?`)) {
    return
  }

  try {
    const response = await request(`/system/events/${item.value.id}`, {method: 'DELETE'})

    if (200 !== response.status) {
      const json = await parse_api_response(response)
      notification('error', 'Error', `Events view delete Request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success', `Event '${makeName(item.value.id)}' deleted.`)
    await navigateTo('/events')
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Events view delete Request failure. ${e.message}`
    )
  }
}

const resetEvent = async (status = 0) => {
  if (!confirm(`Reset '${makeName(item.value.id)}'?`)) {
    return
  }

  try {
    const response = await request(`/system/events/${item.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        status: status,
        reset_logs: true,
      })
    })

    const json = await parse_api_response(response)

    if (200 !== response.status) {
      notification('error', 'Error', `Events view patch Request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    item.value = json
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Events view patch Request failure. ${e.message}`
    )
  }
}
</script>
