<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12">
        <h1 class="title is-4">
          <span class="icon"><i class="fas fa-history"></i>&nbsp;</span>
          <NuxtLink to="/history">Latest History</NuxtLink>
        </h1>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="lastHistory.length > 1">
          <div class="column is-6-tablet" v-for="history in lastHistory" :key="history.id">
            <div class="card card-left" :class="{ 'is-success': history.watched }">
              <div class="card-image">
                <!--                <NuxtLink :to="`/history/${history.id}`">-->
                <!--                  <img :src="makeAPIURL(`/history/${history.id}/images/poster`,{},{no_token: true})"-->
                <!--                       :alt="`Poster for ${makeName(history)}`">-->
                <!--                </NuxtLink>-->
              </div>
              <div class="card-content p-0 m-0">
                <header class="card-header">
                  <p class="card-header-title is-text-overflow">
                    <NuxtLink :to="`/history/${history.id}`" v-text="makeName(history)"/>
                  </p>
                  <span class="card-header-icon">
                    <span class="icon" v-if="'episode' === history.type"><i
                        class="fas fa-tv"></i></span>
                    <span class="icon" v-else><i class="fas fa-film"></i></span>
                  </span>
                </header>
                <div class="card-content">
                  <div class="columns is-multiline is-mobile has-text-centered">
                    <div class="column is-4-tablet is-6-mobile has-text-left-mobile">
                      <div class="is-text-overflow" v-if="history?.updated_at">
                        <span class="icon"><i class="fas fa-calendar"/>&nbsp;</span>
                        <span class="has-tooltip"
                              v-tooltip="`Record updated at: ${moment.unix(history.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                          {{ moment.unix(history.updated_at).fromNow() }}
                        </span>
                      </div>
                    </div>
                    <div class="column is-4-tablet is-6-mobile has-text-right-mobile">
                      <div class="is-text-overflow">
                        <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
                        <NuxtLink :to="'/backend/' + history.via" v-text="history.via"/>
                        <span v-if="history?.metadata && Object.keys(history?.metadata).length > 1"
                              v-tooltip="`Also reported by: ${Object.keys(history.metadata).filter(i => i !== history.via).join(', ')}.`">
                          (<span class="has-tooltip">+{{
                            Object.keys(history.metadata).length - 1
                          }}</span>)
                        </span>
                      </div>
                    </div>
                    <div class="column is-4-tablet is-12-mobile has-text-left-mobile">
                      <div class="is-text-overflow">
                        <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
                        {{ history.event }}
                      </div>
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
        </div>
        <div class="column is-12" v-else>
          <Message title="Warning" message_class="has-background-warning-90 has-text-dark"
                   icon="fas fa-exclamation-triangle"
                   message="No items were found. There are probably no items in the local database yet.">
          </Message>
        </div>
      </div>

      <div class="column is-12" v-for="log in logs" :key="log.filename">
        <h1 class="title is-4">
          <span class="icon" v-if="'access' === log.type"><i class="fas fa-key"></i></span>
          <span class="icon" v-if="'task' === log.type"><i class="fas fa-tasks"></i></span>
          <span class="icon" v-if="'app' === log.type"><i class="fas fa-bugs"></i></span>
          <span class="icon" v-if="'webhook' === log.type"><i class="fas fa-book"></i></span>
          <NuxtLink :to="`/logs/${log.filename}`">
            Latest {{ log.type }} logs
          </NuxtLink>
          <span> -
            <NuxtLink @click="reloadLogs()" v-tooltip="'Fetch latest log entries.'">
              <span class="icon"><span class="fas fa-sync" :class="{ 'fa-spin': reloadingLogs }"/></span>
            </NuxtLink>
          </span>
        </h1>
        <code class="box logs-container is-terminal" style="border-radius: 0 !important;">
          <span class="is-block" v-for="(item, index) in log.lines" :key="log.filename + '-' + index">
            <template v-if="item?.date">[<span class="has-tooltip"
                                               v-tooltip="`${moment(item.date).format(TOOLTIP_DATE_FORMAT)}`">
              {{ moment(item.date).format('HH:mm:ss') }}</span>]
            </template>
            <template v-if="item?.item_id">
              <span @click="goto_history_item(item)" class="is-clickable has-tooltip">
                <span class="icon"><i class="fas fa-history"/></span>
                <span>View</span>
              </span>&nbsp;
            </template>
            <span>{{ item.text }}</span>
          </span></code>
      </div>

      <div class="column is-12">
        <div class="content">
          <Message title="Welcome" message_class="has-background-info-90 has-text-dark" icon="fas fa-heart">
            <p>
              If you have question, or want clarification on something, or just want to chat with other users,
              you are
              welcome to join our <span class="icon-text is-underlined">
              <span class="icon"><i class="fas fa-brands fa-discord"></i></span>
              <span>
                <NuxtLink to="https://discord.gg/haUXHJyj6Y" target="_blank"
                          v-text="'Discord server'"/>
              </span>
            </span>. For bug reports, feature requests, or contributions, please visit the
              <span class="icon-text is-underlined">
                <span class="icon"><i class="fas fa-brands fa-github"></i></span>
                <span>
                  <NuxtLink to="https://github.com/arabcoders/watchstate/issues/new/choose"
                            target="_blank" v-text="'GitHub repository'"/>
                </span>
              </span>.
            </p>
            <p>
              We have recently added a guides page to help you get started with WatchState. You can find it
              <span class="icon-text is-underlined">
                <span class="icon"><i class="fas fa-question-circle"/></span>
                <span>
                  <NuxtLink to="/help" v-text="'here'"/>
                </span>
              </span>, it still very early version and only contains a few guides, but we are working on it.
            </p>
          </Message>
        </div>
      </div>
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
import {formatDuration, goto_history_item, makeName, TOOLTIP_DATE_FORMAT} from '~/utils/index.js'
import {NuxtLink} from '#components'
import {useStorage} from '@vueuse/core'

useHead({title: 'Index'})

const lastHistory = ref([])
const logs = ref([])
const reloadingLogs = ref(false)
const api_user = useStorage('api_user', 'main')

const loadContent = async () => {
  try {
    const response = await request(`/history?perpage=6`)
    if (response.ok) {
      const historyResponse = await response.json()
      if (useRoute().name !== 'index') {
        return
      }

      lastHistory.value = historyResponse.history
    }
  } catch (e) {
  }

  await reloadLogs();
}

const reloadLogs = async () => {
  if (reloadingLogs.value) {
    return;
  }

  try {
    reloadingLogs.value = true
    const response = await request(`/logs/recent`)
    if (!response.ok) {
      return
    }
    const logsResponse = await response.json()
    if ('index' !== useRoute().name) {
      return
    }

    logs.value = logsResponse
  } catch (e) {
  } finally {
    reloadingLogs.value = false
  }
}

onMounted(async () => loadContent())
onUpdated(() => document.querySelectorAll('.logs-container').forEach((el) => el.scrollTop = el.scrollHeight))
</script>
