<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-list"></i></span>
        Queue
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-info" @click.prevent="loadContent">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">This page will show events that are queued to be sent to backends via the
          <code>Push</code> task.</span>
      </div>
    </div>

    <div class="column is-12" v-if="items.length < 1">
      <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
               icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
      <Message v-else message_class="is-background-success-90 has-text-dark" title="Information"
               icon="fas fa-info-circle"
               message="There are currently no queued events."/>
    </div>

    <div class="column is-4 is-6-tablet" v-for="item in items" :key="item.id">
      <div class="card" :class="{ 'is-success': 'Yes' === item.played }">
        <header class="card-header">
          <p class="card-header-title is-text-overflow pr-1">
            <span class="icon">
              <i class="fas" :class="{'fa-eye-slash': !item.watched,'fa-eye': item.watched}"></i>&nbsp;
            </span>
            <NuxtLink :to="'/history/'+item.id" v-text="makeName(item)"/>
          </p>
          <span class="card-header-icon">
            <button class="button is-danger is-small" @click="deleteItem(item)">
              <span class="icon"><i class="fas fa-trash"></i></span>
            </button>
          </span>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-12 has-text-left" v-if="item?.content_title">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                <NuxtLink :to="makeSearchLink('subtitle',item.content_title)" v-text="item.content_title"/>
              </div>
            </div>
            <div class="column is-12 has-text-left" v-if="item?.content_path">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                <NuxtLink :to="makeSearchLink('path',item.content_path)" v-text="item.content_path"/>
              </div>
            </div>
            <div class="column is-12 has-text-left" v-if="item?.progress">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-bars-progress"></i></span>
                {{ formatDuration(item.progress) }}
              </div>
            </div>
          </div>
        </div>
        <div class="card-footer has-text-centered">
          <div class="card-footer-item">
            <div class="is-text-overflow">
              <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
              <span class="has-tooltip" v-tooltip="moment.unix(item.updated_at).format('YYYY-MM-DD h:mm:ss A')">
                {{ moment.unix(item.updated_at).fromNow() }}
              </span>
            </div>
          </div>
          <div class="card-footer-item">
            <div class="is-text-overflow">
              <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
              <NuxtLink :to="'/backend/'+item.via" v-text="item.via"/>
            </div>
          </div>
          <div class="card-footer-item">
            <div class="is-text-overflow">
              <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
              <span>{{ item.event ?? '-' }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import Message from '~/components/Message.vue'
import {formatDuration, makeName, makeSearchLink, notification} from '~/utils/index.js'

useHead({title: 'Queue'})

const items = ref([])
const isLoading = ref(false)

const loadContent = async () => {
  try {
    isLoading.value = true
    items.value = []

    const response = await request(`/system/queue`)
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

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    items.value = json
  } catch (e) {
    isLoading.value = false
    return notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

const deleteItem = async (item) => {
  if (!confirm(`Are you sure you want to delete '${makeName(item)}' from the queue?`)) {
    return
  }

  try {
    const response = await request(`/system/queue/${item.id}`, {method: 'DELETE'})
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

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success', 'Item successfully deleted from queue.')
    items.value = items.value.filter(i => i.id !== item.id)
  } catch (e) {
    return notification('error', 'Error', e.message)
  }
}

onMounted(async () => loadContent())
</script>
