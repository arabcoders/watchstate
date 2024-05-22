<template>
  <div class="columns is-multiline">

    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink to="/backends" v-text="'Backends'"/>
        : {{ backend }}
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <NuxtLink class="button is-primary" v-tooltip="'Edit Backend'" :to="`/backend/${backend}/edit`">
              <span class="icon"><i class="fas fa-edit"></i></span>
            </NuxtLink>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">Basic information about backend activity.</span>
      </div>
    </div>
  </div>

  <div class="columns is-multiline">
    <div class="column is-12">
      <div class="content">
        <h1 class="title is-4">Useful Tools</h1>
        <ul>
          <li>
            <NuxtLink :to="`/backend/${backend}/mismatched`" v-text="'Find possible mismatched content.'"/>
          </li>
          <li>
            <NuxtLink :to="`/backend/${backend}/unmatched`" v-text="'Find unmatched content.'"/>
          </li>
          <li>
            <NuxtLink :to="`/backend/${backend}/libraries`" v-text="'View backend libraries.'"/>
          </li>
          <li>
            <NuxtLink :to="`/backend/${backend}/users`" v-text="'View backend users.'"/>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <div class="columns is-multiline" v-if="bHistory.length>0">
    <div class="column is-12">
      <h1 class="title is-4">Recent History</h1>
    </div>
    <div class="column is-6-tablet" v-for="history in bHistory" :key="history.id">
      <div class="card" :class="{ 'is-success': history.watched }">
        <header class="card-header">
          <p class="card-header-title is-text-overflow pr-1">
            <span class="icon" v-if="!history.progress">
              <i class="fas fa-eye-slash" v-if="!history.watched"></i>
              <i class="fas fa-eye" v-else></i>
            </span>
            <NuxtLink :to="`/history/${history.id}`" v-text="history.full_title ?? history.title"/>
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
                  <NuxtLink :to="'/backend/'+history.via" v-text="history.via"/>
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
    <div class="column is-12">
      <NuxtLink :to="`/history/?perpage=50&page=1&q=${backend}&key=via`">
        <span class="icon-text">
          <span class="icon"><i class="fas fa-history"></i></span>
          <span>View all history related to this backend</span>
        </span>
      </NuxtLink>
    </div>
  </div>

  <div class="columns" v-if="bHistory.length<1">
    <div class="column is-12">
      <Message message_class="is-warning">
        <span class="icon-text">
          <span class="icon"><i class="fas fa-exclamation"></i></span>
          <span>No items were found. There are probably no items in the local database yet.</span>
        </span>
      </Message>
    </div>
  </div>

</template>

<script setup>
import moment from 'moment'
import Message from '~/components/Message.vue'
import {formatDuration, notification} from "~/utils/index.js";

const backend = useRoute().params.backend
const historyUrl = `/history/?via=${backend}`

useHead({title: `Backends: ${backend}`})

const bHistory = ref([])

const loadRecentHistory = async () => {
  const response = await request(`/history/?perpage=6&via=${backend}`)
  const json = await response.json()

  if (200 !== response.status) {
    notification('Error', 'Error loading data', `${json.error.code}: ${json.error.message}`);
    return
  }

  bHistory.value = json.history
};

onMounted(() => loadRecentHistory())
</script>
