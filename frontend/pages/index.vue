<template>
  <div class="columns is-multiline">

    <div class="column is-12">
      <h1 class="title is-4">
        <NuxtLink href="/history">Latest History Entries</NuxtLink>
      </h1>
    </div>

    <div class="column is-12">
      <div class="columns is-multiline" v-if="lastHistory.length>0">
        <div class="column is-6-tablet" v-for="history in lastHistory" :key="history.id">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-text-overflow is-justify-center pr-1">
                <NuxtLink :href="`/history/${history.id}`">
                  {{ history.full_title ?? history.title }}
                </NuxtLink>
              </p>
              <span class="card-header-icon">
                <span class="icon" v-if="'episode' === history.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
              </span>
            </header>
            <div class="card-content">
              <div class="columns is-multiline is-mobile has-text-centered">
                <div class="column is-6-mobile">
                  {{ moment(history.updated).fromNow() }}
                </div>
                <div class="column is-6-mobile">
                  <NuxtLink :href="'/backend/'+history.via">
                    {{ history.via }}
                  </NuxtLink>
                </div>
                <div class="column is-6-mobile" v-if="history.event">
                  <span v-tooltip="'The event which triggered the update.'" class="has-tooltip">
                    {{ history.event }}
                  </span>
                </div>
                <div class="column is-6-mobile">
                  <span class="has-text-success" v-if="history.watched">Played</span>
                  <span class="has-text-danger" v-else>Unplayed</span>
                </div>
                <div class="column is-6-mobile" v-if="history.progress && !history.watched">
                  <span v-tooltip="'Play Progress'">
                    {{ history.progress }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="column is-12" v-else>
        <Message title="Ho history found." message_class="is-warning"/>
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
