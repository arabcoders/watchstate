<template>
  <div class="columns is-multiline">

    <div class="column is-12">
      <h1 class="title is-4">
        <NuxtLink href="/history">Recent History</NuxtLink>
      </h1>
      <div class="table-container" v-if="lastHistory.length>0">
        <table class="table is-fullwidth is-hoverable is-striped is-bordered has-text-centered"
               style="table-layout: fixed">
          <thead>
          <tr>
            <th width="10%">Time</th>
            <th width="50%">Title</th>
            <th width="10%">Backend</th>
            <th width="6%">Played</th>
            <th width="10%">Progress</th>
            <th width="14%">Event</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="history in lastHistory" :key="history.id">
            <td>{{ moment(history.updated).fromNow() }}</td>
            <td class="has-text-left">
              <span class="icon-text">
                <span class="icon" v-if="'episode' === history.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
                <span>{{ history.full_title ?? history.title }}</span>
              </span>
            </td>
            <td>{{ history.via }}</td>
            <td>
              <span class="has-text-success" v-if="history.watched">Yes</span>
              <span class="has-text-danger" v-else>No</span>
            </td>
            <td>{{ history.progress ?? 'None' }}</td>
            <td>{{ history.event ?? '-' }}</td>
          </tr>
          </tbody>
        </table>
      </div>
      <div v-else>
        <Message title="Ho history found." message_class="is-warning"/>
      </div>
    </div>

    <div class="column is-12" v-if="recentAccessLog.length>0">
      <h1 class="title is-4">
        <NuxtLink :href="`/logs/access.${today}.log`">Recent Access logs</NuxtLink>
      </h1>
      <code class="box logs-container">
        <p v-for="(item, index) in recentAccessLog" :key="'alog-'+index">{{ item }}</p>
      </code>
    </div>

    <div class="column is-12" v-if="recentAppLogs.length>0">
      <h1 class="title is-4">
        <NuxtLink :href="`/logs/app.${today}.log`">Recent App logs</NuxtLink>
      </h1>
      <code class="box logs-container">
        <p v-for="(item, index) in recentAppLogs" :key="'plog-'+index">{{ item }}</p>
      </code>
    </div>

    <div class="column is-12" v-if="recentTaskLogs.length>0">
      <h1 class="title is-4">
        <NuxtLink :href="`/logs/task.${today}.log`">Recent Task logs</NuxtLink>
      </h1>
      <code class="box logs-container">
        <p v-for="(item, index) in recentTaskLogs" :key="'plog-'+index">{{ item }}</p>
      </code>
    </div>

  </div>
</template>

<style>
.logs-container {
  max-height: 20vh;
  overflow-y: auto;
  white-space: pre;
}
</style>

<script setup>
import request from "~/utils/request.js";
import moment from "moment";
import Message from "~/components/Message.vue";

useHead({title: 'Index'})

const today = moment().format('YYYYMMDD')

const lastHistory = ref([])
const recentAccessLog = ref([])
const recentAppLogs = ref([])
const recentTaskLogs = ref([])

const loadContent = async () => {
  const logs_limit = 50
  try {
    const response = await request(`/history?perpage=5`)
    const json = await response.json();
    lastHistory.value = json.history
  } catch (e) {
  }

  try {
    const response = await request(`/logs/access.${today}.log`)
    if (response.ok) {
      const access = await response.text();
      recentAccessLog.value = access.split('\n').filter(Boolean).slice(-logs_limit)
    }
  } catch (e) {
  }

  try {
    const response = await request(`/logs/app.${today}.log`)
    if (response.ok) {
      const app = await response.text();
      recentAppLogs.value = app.split('\n').filter(Boolean).slice(-logs_limit)
    }
  } catch (e) {
  }

  try {
    const response = await request(`/logs/task.${today}.log`)
    if (response.ok) {
      const access = await response.text();
      recentTaskLogs.value = access.split('\n').filter(Boolean).slice(-logs_limit)
    }
  } catch (e) {
  }
};

onMounted(async () => loadContent())
</script>
