<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-sd-card"></i></span>
        Backups
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" @click="queueTask" :disabled="isLoading"
                    :class="{'is-loading':isLoading, 'is-primary':!queued, 'is-danger':queued}">
              <span class="icon"><i class="fas fa-sd-card"></i></span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click="loadContent" :disabled="isLoading" :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">
          This page contains all of your manually generated and automatic backups.
        </span>
      </div>
    </div>

    <div class="column is-12" v-if="items.length < 1 || isLoading">
      <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark" icon="fas fa-spinner fa-spin"
               title="Loading" message="Loading data. Please wait..."/>
      <Message v-else title="Warning" message_class="is-background-warning-80 has-text-dark"
               icon="fas fa-exclamation-triangle">
        No backups found.
      </Message>
    </div>

    <div class="column is-6-tablet" v-for="(item, index) in items" :key="'backup-'+index">
      <div class="card">
        <header class="card-header">
          <p class="card-header-title is-text-overflow pr-1">
            <NuxtLink @click="downloadFile(item)" v-text="item.filename"/>
          </p>
          <span class="card-header-icon">
            <span class="icon"><i class="fas fa-download" :class="{'fa-spin':item?.isDownloading}"></i></span>
          </span>
        </header>
        <div class="card-footer-item">
          <div class="card-footer-item">
            <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
            <span v-tooltip="`Last Update: ${moment.unix(item.modified_at).format(TOOLTIP_DATE_FORMAT)} `"
                  class="has-tooltip">
              {{ moment.unix(item.created_at).fromNow() }}
            </span>
          </div>
          <div class="card-footer-item">
            <span class="icon"><i class="fas fa-hdd"></i>&nbsp;</span>
            <span>{{ humanFileSize(item.size) }}</span>
          </div>
          <div class="card-footer-item">
            <span class="icon"><i class="fas fa-tag"></i>&nbsp;</span>
            <span class="is-capitalized">{{ item.type }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import {humanFileSize, notification, TOOLTIP_DATE_FORMAT} from '~/utils/index.js'
import Message from '~/components/Message.vue'

useHead({title: 'Backups'})
const items = ref([])
const isLoading = ref(false)
const queued = ref(true)

const loadContent = async () => {
  items.value = []
  isLoading.value = true

  try {
    const response = await request('/system/backup')
    items.value = await response.json()

    queued.value = await isQueued()
  } catch (e) {
    notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

const downloadFile = async item => {
  if (true === item?.isDownloading) {
    return
  }
  const filename = item.filename
  item.isDownloading = true

  const response = request(`/system/backup/${filename}`)

  if ('showSaveFilePicker' in window) {
    response.then(async res => {
      item.isDownloading = false

      return res.body.pipeTo(await (await showSaveFilePicker({
        suggestedName: `${filename}`
      })).createWritable())
    })
  } else {
    response.then(res => res.blob()).then(blob => {
      const fileURL = URL.createObjectURL(blob)
      const fileLink = document.createElement('a')
      fileLink.href = fileURL
      fileLink.download = `${filename}`
      fileLink.click()
      item.isDownloading = false
    })
  }
}

const queueTask = async () => {
  const is_queued = await isQueued()
  const message = is_queued ? 'Remove backup task from queue?' : 'Queue backup task to run in background?'

  if (!confirm(message)) {
    return
  }

  try {
    const response = await request(`/tasks/backup/queue`, {method: is_queued ? 'DELETE' : 'POST'})
    if (response.ok) {
      notification('success', 'Success', `Task backup has been ${is_queued ? 'removed from the queue' : 'queued'}.`)
      queued.value = !is_queued
    }
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const isQueued = async () => {
  const response = await request('/tasks/backup')
  const json = await response.json()
  return Boolean(json.queued)
}

onMounted(async () => await loadContent())
</script>
