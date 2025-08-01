<style scoped>
.text-container {
  max-height: 50vh;
  overflow-y: auto;
}
</style>
<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="toggleFilter">
              <input type="search" v-model.lazy="query" class="input" id="filter" placeholder="Filter">
              <span class="icon is-left"><i class="fas fa-filter"/></span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter = !toggleFilter"
                      :disabled="!item?.logs || item.logs.length < 1" v-tooltip.bottom="'Filter event logs.'">
                <span class="icon"><i class="fas fa-filter"/></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-warning" @click="resetEvent(0 === item.status ? 4 : 0)"
                      :disabled="1 === item.status" v-tooltip.bottom="'Reset event.'">
                <span class="icon">
                  <i class="fas"
                     :class="{ 'fa-trash-arrow-up': 0 !== item.status, 'fa-power-off': 0 === item.status }"></i>
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-danger" @click="deleteItem" :disabled="1 === item.status"
                      v-tooltip.bottom="'Delete event.'">
                <span class="icon"><i class="fas fa-trash"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-purple" @click="wrapLines = !wrapLines" v-tooltip.bottom="'Toggle wrap line'">
                <span class="icon"><i class="fas fa-text-width"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button" @click="() => copyText(JSON.stringify(item,null,2))" :disabled="isLoading"
                      v-tooltip.bottom="'Copy event.'">
                <span class="icon"><i class="fas fa-copy"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent()" :class="{ 'is-loading': isLoading }"
                      :disabled="isLoading" v-tooltip.bottom="'Reload event data.'">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle"></span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoading && !item?.id">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
      </div>
    </div>

    <div v-if="!isLoading || item?.id" class="columns is-multiline">
      <div class="column is-12">
        <div class="notification">
          <p class="title is-5">
            Event <span class="tag is-info is-clickable" @click="copyText(item.id)">{{ item.event }}</span>
            <template v-if="item.reference">
              with reference <span class="tag is-info is-light">{{ item.reference }}</span>
            </template>
            was created
            <span class="tag is-warning">
              <time class="has-tooltip" v-tooltip="moment(item.created_at).format(TOOLTIP_DATE_FORMAT)">
                {{ moment(item.created_at).fromNow() }}
              </time>
            </span>, and last updated
            <span class="tag is-danger">
              <span v-if="!item.updated_at">not started</span>
              <time v-else class="has-tooltip"
                    v-tooltip="moment(item.updated_at).format(TOOLTIP_DATE_FORMAT)">
                {{ moment(item.updated_at).fromNow() }}
              </time>
            </span>,
            with status of <span class="tag" :class="getStatusClass(item.status)">{{ item.status }}:
            {{ item.status_name }}</span>.
          </p>
        </div>
      </div>

      <div class="column is-12" v-if="item?.event_data && Object.keys(item.event_data).length > 0">
        <h2 class="title is-4 is-clickable is-unselectable" @click="toggleData = !toggleData">
          <span class="icon">
            <i class="fas" :class="{ 'fa-arrow-down': !toggleData, 'fa-arrow-up': toggleData }"/>
          </span>&nbsp;
          <span>{{ !toggleData ? 'Show' : 'Hide' }} attached data</span>
        </h2>
        <div v-if="toggleData" class="is-relative">
          <code class="text-container is-block p-4 is-terminal"
                :class="{ 'is-pre': !wrapLines, 'is-pre-wrap': wrapLines }">
            {{ JSON.stringify(item.event_data, null, 2) }}
          </code>
          <button class="button m-4" v-tooltip="'Copy event data'"
                  @click="() => copyText(JSON.stringify(item.event_data, null, 2))"
                  style="position: absolute; top:0; right:0;">
            <span class="icon"><i class="fas fa-copy"></i></span>
          </button>
        </div>
      </div>

      <div class="column is-12" v-if="item?.logs && item.logs.length > 0">
        <h2 class="title is-4 is-clickable is-unselectable" @click="toggleLogs = !toggleLogs">
          <span class="icon">
            <i class="fas" :class="{ 'fa-arrow-down': !toggleLogs, 'fa-arrow-up': toggleLogs }"/>
          </span>&nbsp;
          <span>{{ !toggleLogs ? 'Show' : 'Hide' }} event logs</span>
        </h2>
        <div v-if="toggleLogs" class="is-relative">
          <code class="is-block text-container p-4 is-terminal"
                :class="{ 'is-pre': !wrapLines, 'is-pre-wrap': wrapLines }">
            <span class="is-block pt-1" v-for="(item, index) in filteredRows" :key="'log_line-' + index"
                  v-text="item"/>
          </code>
          <button class="button m-4" v-tooltip="'Copy logs'" @click="() => copyText(filteredRows.join('\n'))"
                  style="position: absolute; top:0; right:0;">
            <span class="icon"><i class="fas fa-copy"></i></span>
          </button>
        </div>
      </div>

      <div class="column is-12" v-if="item?.options && Object.keys(item.options).length > 0">
        <h2 class="title is-4 is-clickable is-unselectable" @click="toggleOptions = !toggleOptions">
          <span class="icon">
            <i class="fas" :class="{ 'fa-arrow-down': !toggleOptions, 'fa-arrow-up': toggleOptions }"></i>
          </span>&nbsp;
          <span>{{ !toggleOptions ? 'Show' : 'Hide' }} attached options</span>
        </h2>
        <div v-if="toggleOptions" class="is-relative">
          <code class="is-block text-container p-4 is-terminal"
                :class="{ 'is-pre': !wrapLines, 'is-pre-wrap': wrapLines }">
            {{ JSON.stringify(item.options, null, 2) }}
          </code>
          <button class="button m-4" v-tooltip="'Copy options'"
                  @click="() => copyText(JSON.stringify(item.options, null, 2))"
                  style="position: absolute; top:0; right:0;">
            <span class="icon"><i class="fas fa-copy"></i></span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {
  copyText,
  disableOpacity,
  enableOpacity,
  notification,
  parse_api_response,
  TOOLTIP_DATE_FORMAT
} from '~/utils/index.js'
import request from '~/utils/request.js'
import moment from 'moment'
import {getStatusClass, makeName} from '~/utils/events/helpers.js'
import {useStorage} from '@vueuse/core'

const emitter = defineEmits(['closeOverlay', 'deleted'])
const props = defineProps({id: {type: Number, required: true}})

const query = ref()
const item = ref({})
const isLoading = ref(true)
const toggleFilter = ref(false)
const timer = ref()
const toggleLogs = useStorage('events_toggle_logs', true)
const toggleData = useStorage('events_toggle_data', true)
const toggleOptions = useStorage('events_toggle_options', true)
const wrapLines = useStorage('logs_wrap_lines', false)

const bg_enable = useStorage('bg_enable', true)
const bg_opacity = useStorage('bg_opacity', 0.95)

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = ''
  }
});

const filteredRows = computed(() => {
  if (!query.value) {
    return item.value.logs ?? []
  }
  return item.value.logs.filter(m => m.toLowerCase().includes(query.value.toLowerCase()));
});

onMounted(async () => {
  disableOpacity()
  if (!props.id) {
    throw createError({
      statusCode: 404,
      message: 'Error ID not provided.'
    })
  }
  return await loadContent()
})

onUnmounted(async () => enableOpacity())

const loadContent = async () => {
  try {
    isLoading.value = true
    const response = await request(`/system/events/${props.id}`,)
    const json = await parse_api_response(response)

    if (200 !== response.status) {
      notification('error', 'Error', `Errors viewItem request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    if (1 === json.status) {
      if (!timer.value) {
        timer.value = setInterval(async () => await loadContent(), 5000)
      }
    } else {
      if (timer.value) {
        clearInterval(timer.value)
        timer.value = null
      }
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

const deleteItem = async () => emitter('delete', item.value)

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
