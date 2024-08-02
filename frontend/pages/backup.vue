<template>
  <div>
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
                <span>{{ !queued ? 'Queue backup' : 'Remove from queue' }}</span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
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
              <span class="icon"><i class="fas fa-download" :class="{'fa-spin':item?.isDownloading}"></i>&nbsp;</span>
              <span>
                <NuxtLink @click="downloadFile(item)" v-text="item.filename"/>
              </span>
            </p>
            <span class="card-header-icon">
              <NuxtLink @click="deleteFile(item)" class="has-text-danger" v-tooltip="'Delete this backup file.'">
                <span class="icon"><i class="fas fa-trash"></i></span>
              </NuxtLink>
            </span>
          </header>
          <div class="card-footer-item">
            <div class="card-footer-item">
              <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
              <span class="has-tooltip" v-tooltip="`Last Update: ${moment(item.date).format(TOOLTIP_DATE_FORMAT)}`">
                {{ moment(item.date).fromNow() }}
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

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              Backups that are tagged <code>Automatic</code> are subject to auto deletion after <code>9</code> days from
              the date of creation.
            </li>
            <li>
              You can trigger a backup task to run in the background by clicking the
              <code><span class="icon"><i class="fas fa-sd-card"></i></span> Queue backup</code> button. on top right.
              Those backups will be tagged as <code>Automatic</code>.
            </li>
            <li>
              To generate a manual backup, you need to use the <code>state:backup</code> command from the console.
              or by <span class="icon"><i class="fas fa-terminal"></i></span>
              <NuxtLink :to="makeConsoleCommand('state:backup -s [backend] --file /config/backup/[file]')"
                        v-text="'Web Console'"/>
              page.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request'
import moment from 'moment'
import {humanFileSize, makeConsoleCommand, notification, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import Message from '~/components/Message'
import {useStorage} from '@vueuse/core'

useHead({title: 'Backups'})
const items = ref([])
const isLoading = ref(false)
const queued = ref(true)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  items.value = []
  isLoading.value = true

  try {
    const response = await request('/system/backup')
    items.value = await response.json()
    if (useRoute().name !== 'backup') {
      return
    }

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

const deleteFile = async (item) => {
  if (!confirm(`Delete backup file '${item.filename}'?`)) {
    return
  }

  try {
    const response = await request(`/system/backup/${item.filename}`, {method: 'DELETE'})

    if (200 === response.status) {
      notification('success', 'Success', `Backup file '${item.filename}' has been deleted.`)
      items.value = items.value.filter(i => i.filename !== item.filename)
      return
    }

    let json

    try {
      json = await response.json()
    } catch (e) {
      json = {error: {code: response.status, message: response.statusText}}
    }

    notification('error', 'Error', `API error. ${json.error.code}: ${json.error.message}`)
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
