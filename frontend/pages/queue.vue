<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">Queue</span>
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
            <span class="icon" v-if="!item.progress">
              <i class="fas fa-eye-slash" v-if="!item.watched"></i>
              <i class="fas fa-eye" v-else></i>
              &nbsp;
            </span>
            <NuxtLink :to="'/history/'+item.id" v-text="item.title"/>
          </p>
          <span class="card-header-icon">
            <span class="icon" v-if="'episode' === item.type"><i class="fas fa-tv"></i></span>
            <span class="icon" v-else><i class="fas fa-film"></i></span>
          </span>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-4-tablet is-6-mobile has-text-left-mobile">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                {{ moment(item.updated).fromNow() }}
              </span>
            </div>
            <div class="column is-4-tablet is-6-mobile has-text-right-mobile">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-server"></i></span>
                <span>
                  <NuxtLink :to="'/backend/'+item.via" v-text="item.via"/>
                </span>
              </span>
            </div>
            <div class="column is-4-tablet is-12-mobile has-text-left-mobile">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-envelope"></i></span>
                <span>{{ item.event }}</span>
              </span>
            </div>
          </div>
        </div>
        <div class="card-footer" v-if="item.progress">
          <div class="card-footer-item">
            <span class="has-text-success" v-if="item.watched">Played</span>
            <span class="has-text-danger" v-else>Unplayed</span>
          </div>
          <div class="card-footer-item">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-bars-progress"></i></span>
              <span>{{ formatDuration(item.progress) }}</span>
            </span>
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
import {formatDuration, notification} from '~/utils/index.js'

useHead({title: 'Queue'})

const items = ref([])
const isLoading = ref(false)

const loadContent = async () => {
  isLoading.value = true
  items.value = []

  let response, json;

  try {
    response = await request(`/system/queue`)
  } catch (e) {
    isLoading.value = false
    return notification('error', 'Error', e.message)
  }

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

  isLoading.value = false

  if (!response.ok) {
    notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    return
  }

  items.value = json
}

onMounted(async () => loadContent())
</script>
