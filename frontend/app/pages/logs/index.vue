<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-globe"/></span>
          Logs
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="toggleFilter">
              <input type="search" v-model.lazy="query" class="input" id="filter"
                     placeholder="Filter displayed content">
              <span class="icon is-left"><i class="fas fa-filter"/></span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" v-tooltip.bottom="'Filter files.'"
                      @click="toggleFilter = !toggleFilter">
                <span class="icon"><i class="fas fa-filter"/></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This page contains all the stored log files.</span>
        </div>
      </div>

      <div class="column is-12" v-if="filterItems.length < 1 || isLoading">
        <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark" icon="fas fa-spinner fa-spin"
                 title="Loading" message="Loading data. Please wait..."/>
        <Message v-else
                 :title="query ? 'No results' : 'Warning'"
                 message_class="is-background-warning-80 has-text-dark"
                 icon="fas fa-exclamation-triangle">
          <span v-if="query">No results found for <strong>{{ query }}</strong></span>
          <span v-else>No logs found.</span>
        </Message>
      </div>

      <div class="column is-4-tablet" v-for="(item, index) in filterItems" :key="'log-' + index">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-text-overflow pr-1">
              <NuxtLink :to="'/logs/' + item.filename">{{ item.filename ?? item.date }}</NuxtLink>
            </p>
            <span class="card-header-icon">
              <span class="icon" v-if="'access' === item.type"><i class="fas fa-key"/></span>
              <span class="icon" v-if="'task' === item.type"><i class="fas fa-tasks"/></span>
              <span class="icon" v-if="'app' === item.type"><i class="fas fa-bugs"/></span>
              <span class="icon" v-if="'webhook' === item.type"><i class="fas fa-book"/></span>
              <span class="icon" v-if="'request' === item.type"><i class="fas fa-globe"/></span>
              <span class="is-capitalized">{{ item.type }}</span>
            </span>
          </header>
          <div class="card-footer">
            <p class="card-footer-item">
              <span class="icon"><i class="fas fa-calendar"/>&nbsp;</span>
              <span class="has-tooltip" v-tooltip="`Last Update: ${moment(item.modified).format(TOOLTIP_DATE_FORMAT)}`">
                {{ moment(item.modified).fromNow() }}
              </span>
            </p>
            <p class="card-footer-item">
              <span class="icon"><i class="fas fa-hdd"/>&nbsp;</span>
              <span>{{ humanFileSize(item.size) }}</span>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import {useHead, useRoute} from '#app'
import moment from 'moment'
import {humanFileSize, notification, parse_api_response, request, TOOLTIP_DATE_FORMAT} from '~/utils'
import type {LogItem} from '~/types'
import Message from '~/components/Message.vue'

useHead({title: 'Logs'})

const query = ref<string>('')
const logs = ref<Array<LogItem>>([])
const isLoading = ref<boolean>(false)
const toggleFilter = ref<boolean>(false)

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = ''
  }
})

const filterItems = computed((): Array<LogItem> => {
  if (!query.value) {
    return logs.value ?? []
  }
  return logs.value.filter(i => i.filename.toLowerCase().includes(query.value.toLowerCase()))
})

const loadContent = async (): Promise<void> => {
  logs.value = []
  isLoading.value = true

  try {
    const response = await request('/logs')
    const data = await parse_api_response<Array<LogItem>>(response)

    if ('logs' !== useRoute().name) {
      return
    }

    // Handle both success and error cases
    if ('error' in data) {
      notification('error', 'Error', data.error.message)
      return
    }

    // TypeScript knows data is Array<LogItem> here
    data.sort((a, b) => new Date(b.modified).getTime() - new Date(a.modified).getTime())

    logs.value = data
  } catch (e: any) {
    notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => loadContent())
</script>
