<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <h1 class="title is-4">
        <NuxtLink href="/history">Latest History</NuxtLink>
      </h1>
    </div>

    <div class="column is-12">
      <div class="columns is-multiline" v-if="lastHistory.length>0">
        <div class="column is-6-tablet" v-for="history in lastHistory" :key="history.id">
          <div class="card" :class="{ 'is-success': history.watched, 'is-danger': !history.watched }">
            <header class="card-header">
              <p class="card-header-title is-text-overflow pr-1">
                <span class="icon" v-if="!history.progress">
                  <i class="fas fa-eye-slash" v-if="!history.watched"></i>
                  <i class="fas fa-eye" v-else></i>
                </span>
                <NuxtLink :href="`/history/${history.id}`" v-text="history.full_title ?? history.title"/>
              </p>
              <span class="card-header-icon">
                <span class="icon" v-if="'episode' === history.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
              </span>
            </header>
            <div class="card-content">
              <div class="columns is-multiline is-mobile has-text-centered">
                <div class="column is-4-tablet is-6-mobile has-text-left-mobile">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                    {{ moment(history.updated).fromNow() }}
                  </span>
                </div>
                <div class="column is-4-tablet is-6-mobile has-text-right-mobile">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-server"></i></span>
                    <span>
                      <NuxtLink :href="'/backend/'+history.via" v-text="history.via"/>
                    </span>
                  </span>
                </div>
                <div class="column is-4-tablet is-12-mobile has-text-left-mobile">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-envelope"></i></span>
                    <span>{{ history.event }}</span>
                  </span>
                </div>
              </div>
            </div>
            <div class="card-footer" v-if="history.progress">
              <div class="card-footer-item">
                <span class="has-text-success" v-if="history.watched">Played</span>
                <span class="has-text-danger" v-else>Unplayed</span>
              </div>
              <div class="card-footer-item">{{ formatDuration(history.progress) }}</div>
            </div>
          </div>
        </div>
      </div>
      <div class="column is-12" v-else>
        <Message message_class="is-warning">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-exclamation"></i></span>
            <span>No items were found. There are probably no items in the local database yet.</span>
          </span>
        </Message>
      </div>
    </div>

    <div class="column is-12" v-for="log in logs" :key="log.filename">
      <h1 class="title is-4">
        <NuxtLink :href="`/logs/${log.filename}`">Latest {{ log.type }} logs</NuxtLink>
      </h1>
      <code class="box logs-container">
        <span class="is-block" v-for="(item, index) in log.lines" :key="log.filename + '-' + index">
          {{ item }}
        </span>
      </code>
    </div>

  </div>
</template>

<style scoped>
.logs-container {
  max-height: 20vh;
  overflow-y: auto;
  white-space: pre;
}
</style>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import Message from '~/components/Message.vue'
import {formatDuration} from "../utils/index.js";

useHead({title: 'Index'})

const lastHistory = ref([])
const logs = ref([])

const loadContent = async () => {
  try {
    const response = await request(`/history?perpage=6`)
    const json = await response.json();
    lastHistory.value = json.history
  } catch (e) {
  }

  try {
    const response = await request(`/logs/recent`)
    if (response.ok) {
      logs.value = await response.json();
    }
  } catch (e) {
  }
};

onMounted(async () => loadContent())
onUpdated(() => document.querySelectorAll('.logs-container').forEach((el) => el.scrollTop = el.scrollHeight))
</script>
