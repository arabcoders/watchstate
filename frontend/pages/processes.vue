<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-microchip"/></span>
          Processes
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="showFilter">
              <input type="search" v-model.lazy="filter" class="input" id="filter"
                     placeholder="Filter displayed results.">
              <span class="icon is-left">
                <i class="fas fa-filter"></i>
              </span>
            </div>
            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter">
                <span class="icon"><i class="fas fa-filter"></i></span>
              </button>
            </div>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page gives you overview of all the processes that are currently running on the system/container.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="items.length < 1 || filteredRows(items).length < 1">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else class="has-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-triangle" :use-close="true" @close="filter = ''">
          <div class="icon-text">
            No items found.
            <span v-if="filter">For <code><strong>Filter</strong> : <strong>{{ filter }}</strong></code></span>
          </div>
        </Message>
      </div>

      <div v-else class="column is-12" v-if="items">
        <div class="table-container">
          <table class="table is-fullwidth is-hoverable is-striped">
            <thead>
            <tr>
              <th>PID</th>
              <th>Memory</th>
              <th>CPU</th>
              <th>Time</th>
              <th>Command</th>
            </tr>
            </thead>
            <tbody>
            <template v-for="item in items" :key="item.pid">
              <tr v-if="filterItem(item)">
                <td>{{ item.pid }}</td>
                <td>{{ item.mem }}</td>
                <td>{{ item.cpu }}</td>
                <td>{{ item.time }}</td>
                <td>{{ item.command }}</td>
              </tr>
            </template>
            </tbody>
          </table>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>Ignoring specific GUID sometimes helps in preventing incorrect data being added to WatchState, due to
              incorrect metadata being provided by backends.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import {awaitElement, notification} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message'

useHead({title: 'Processes'})
const route = useRoute()

const items = ref([])
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(false)
const filter = ref(route.query.filter ?? '')
const showFilter = ref(!!filter.value)

const loadContent = async () => {
  if (isLoading.value) {
    return
  }

  try {
    const response = await request('/system/processes')
    const json = await response.json()

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    if (!('processes' in json)) {
      notification('error', 'Error', 'Invalid response from the server.')
      return
    }

    items.value = json.processes
  } finally {
    isLoading.value = false
  }
}

const toggleFilter = () => {
  showFilter.value = !showFilter.value
  if (!showFilter.value) {
    filter.value = ''
    return
  }

  awaitElement('#filter', (_, elm) => elm.focus())
}

const filteredRows = items => {
  if (!filter.value) {
    return items
  }

  return items.filter(i => Object.values(i).some(v => typeof v === 'string' ? v.toLowerCase().includes(String(filter.value).toLowerCase()) : false))
}

const filterItem = item => {
  if (!filter.value || !item) {
    return true
  }

  return Object.values(item).some(v => typeof v === 'string' ? v.toLowerCase().includes(String(filter.value).toLowerCase()) : false)
}

onMounted(() => loadContent())

</script>
