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
          <div>
            <span class="icon" v-if="filter"><i class="fas fa-filter"/></span>
            No items found.
            <span v-if="filter">For query: <code class="is-underlined is-bold" v-text="filter"/></span>
          </div>
        </Message>
      </div>

      <div v-else class="column is-12" v-if="items">
        <div class="table-container" style="max-height: 70vh; overflow-y: auto">
          <table class="table is-fullwidth is-hoverable is-striped is-bordered">
            <thead>
            <tr>
              <th colspan="2" class="has-text-centered">PID</th>
              <th>Memory</th>
              <th>CPU</th>
              <th>Time</th>
              <th>Command</th>
            </tr>
            </thead>
            <tbody>
            <template v-for="item in items" :key="item.pid">
              <tr v-if="filterItem(item)">
                <td class="is-vcentered">
                  <button class="button is-danger is-small" @click="killProcess(item.pid)">
                    <span class="icon"><i class="fas fa-trash"/></span>
                  </button>
                </td>
                <td class="is-vcentered">{{ item.pid }}</td>
                <td class="is-vcentered">{{ item.mem }}</td>
                <td class="is-vcentered">{{ item.cpu }}</td>
                <td class="is-vcentered">{{ item.time }}</td>
                <td class="is-vcentered">{{ item.command }}</td>
              </tr>
            </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import {awaitElement, notification} from '~/utils/index'
import Message from '~/components/Message'

useHead({title: 'Processes'})
const items = ref([])
const isLoading = ref(false)
const filter = ref(String(useRoute().query.filter ?? ''))
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

const killProcess = async pid => {
  if (!pid) {
    return
  }

  if (false === confirm(`Kill #${pid}, you may have to restart the container if you do that?`)) {
    return
  }

  isLoading.value = true
  try {
    const response = await request(`/system/processes/${pid}`, {method: 'DELETE'})
    const json = await response.json()

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success', `Successfully killed #${pid}.`)

    items.value = items.value.filter(item => item.pid !== pid)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => loadContent())
</script>
