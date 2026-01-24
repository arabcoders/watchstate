<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-history"></i>&nbsp;</span>
          <NuxtLink to="/history">Latest History</NuxtLink>
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button" @click="toggleLogsAutoReload()"
                :class="autoReloadLogs ? 'is-success' : 'is-warning'"
                v-tooltip.bottom="autoReloadLogs ? 'Disable auto reload' : 'Enable auto reload'">
                <span class="icon">
                  <i class="fas" :class="autoReloadLogs ? 'fa-pause' : 'fa-play'" />
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="reloadLogs()" :disabled="reloadingLogs"
                :class="{ 'is-loading': reloadingLogs }" v-tooltip.bottom="'Fetch latest log entries.'">
                <span class="icon"><i class="fas fa-sync" /></span>
              </button>
            </p>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="lastHistory.length > 1">
          <div class="column is-6-tablet" v-for="item in lastHistory" :key="item.id">
            <div class="card" :class="{ 'is-success': item.watched }">
              <header class="card-header">
                <p class="card-header-title is-text-overflow">
                  <FloatingImage :image="`/history/${item.id}/images/poster`" :item_class="'scaled-image'"
                    v-if="poster_enable">
                    <NuxtLink :to="'/history/' + item.id">
                      {{ item?.full_title || makeName(item as unknown as JsonObject) }}
                    </NuxtLink>
                  </FloatingImage>
                  <NuxtLink :to="'/history/' + item.id" v-else>
                    {{ item?.full_title || makeName(item as unknown as JsonObject) }}
                  </NuxtLink>
                </p>
                <span class="card-header-icon">
                  <Popover v-if="(item?.duplicate_reference_ids?.length || 0) > 0" placement="top" trigger="hover"
                    :show-delay="200" :hide-delay="200" :offset="8" content-class="p-0">
                    <template #trigger>
                      <span class="tag is-warning is-bold is-clickable is-size-7">
                        <span class="icon is-small mr-1"><i class="fas fa-layer-group" /></span>
                        <span>{{ item.duplicate_reference_ids?.length }}</span>
                      </span>
                    </template>
                    <template #content>
                      <DuplicateRecordList :ids="item.duplicate_reference_ids ?? []" />
                    </template>
                  </Popover>

                  <span class="icon" v-if="'episode' === item.type"><i class="fas fa-tv"></i></span>
                  <span class="icon" v-else><i class="fas fa-film"></i></span>
                </span>
              </header>
              <div class="card-content">
                <div class="columns is-multiline is-mobile has-text-centered">
                  <div class="column is-4-tablet is-6-mobile has-text-left-mobile">
                    <div class="is-text-overflow" v-if="item?.updated_at">
                      <span class="icon"><i class="fas fa-calendar" />&nbsp;</span>
                      <span class="has-tooltip"
                        v-tooltip="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                        {{ moment.unix(item.updated_at).fromNow() }}
                      </span>
                    </div>
                  </div>
                  <div class="column is-4-tablet is-6-mobile has-text-right-mobile">
                    <div class="is-text-overflow">
                      <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
                      <NuxtLink :to="'/backend/' + item.via"> {{ item.via }}</NuxtLink>
                      <span v-if="item?.metadata && Object.keys(item?.metadata).length > 1"
                        v-tooltip="`Also reported by: ${Object.keys(item.metadata).filter(i => i !== item.via).join(', ')}.`">
                        (<span class="has-tooltip">+{{
                          Object.keys(item.metadata).length - 1
                        }}</span>)
                      </span>
                    </div>
                  </div>
                  <div class="column is-4-tablet is-12-mobile has-text-left-mobile">
                    <div class="is-text-overflow">
                      <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
                      {{ item.event }}
                    </div>
                  </div>
                </div>
              </div>
              <div class="card-footer" v-if="item.progress">
                <div class="card-footer-item">
                  <span class="has-text-success" v-if="item.watched">Played</span>
                  <span class="has-text-danger" v-else>Unplayed</span>
                </div>
                <div class="card-footer-item">{{ formatDuration(item.progress as number) }}</div>
              </div>
            </div>
          </div>
        </div>
        <div class="column is-12" v-else>
          <Message v-if="historyLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
            icon="fas fa-spinner fa-spin" message="Loading history. Please wait..." />
          <Message title="Warning" message_class="has-background-warning-90 has-text-dark"
            icon="fas fa-exclamation-triangle" message="DB has no history records." v-if="!historyLoading">
          </Message>
        </div>
      </div>


      <div class="column is-12" v-for="log in logs" :key="log.filename">
        <h1 class="title is-4">
          <span class="icon">
            <i class="fas" :class="{
              'fa-key': 'access' === log.type,
              'fa-tasks': 'task' === log.type,
              'fa-bugs': 'app' === log.type,
              'fa-book': 'webhook' === log.type,
              'fa-spin': reloadingLogs
            }" /></span>
          <NuxtLink :to="`/logs/${log.filename}`">
            Latest {{ log.type }} logs
          </NuxtLink>
        </h1>
        <code class="box logs-container is-terminal is-pre-wrap" style="border-radius: 0 !important;">
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
                  <NuxtLink to="https://discord.gg/haUXHJyj6Y" target="_blank">
                    Discord server
                  </NuxtLink>
                </span>
              </span>. For bug reports, feature requests, or contributions, please visit the
              <span class="icon-text is-underlined">
                <span class="icon"><i class="fas fa-brands fa-github"></i></span>
                <span>
                  <NuxtLink to="https://github.com/arabcoders/watchstate/issues/new/choose" target="_blank">
                    GitHub repository
                  </NuxtLink>
                </span>
              </span>.
            </p>
            <p>
              We have recently added a guides page to help you get started with WatchState. You can find it
              <span class="icon-text is-underlined">
                <span class="icon"><i class="fas fa-question-circle" /></span>
                <span>
                  <NuxtLink to="/help">here</NuxtLink>
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
  white-space: pre-wrap;
}
</style>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, onUpdated, ref, watch } from 'vue'
import { useHead, useRoute } from '#app'
import { useStorage } from '@vueuse/core'
import { NuxtLink } from '#components'
import moment from 'moment'
import Message from '~/components/Message.vue'
import FloatingImage from '~/components/FloatingImage.vue'
import { formatDuration, goto_history_item, makeName, parse_api_response, request, TOOLTIP_DATE_FORMAT } from '~/utils'
import type {HistoryItem, JsonObject} from '~/types'
import Popover from '~/components/Popover.vue'
import DuplicateRecordList from '~/components/DuplicateRecordList.vue'

type IndexLogFile = {
  /** Type of log file (e.g., 'access', 'task', 'app', 'webhook') */
  type: string
  /** Filename of the log */
  filename: string
  /** Last modified date as a Unix timestamp */
  date: number
  /** Size of the log file in bytes */
  size: number
  /** Last modified date as a formatted string */
  modified: string
  /** Array of log lines */
  lines: Array<{
    /** Unique log entry ID */
    id?: string,
    /** Associated history item ID, if any */
    item_id?: number
    /** User associated with the log entry, if any */
    user?: string
    /** Backend associated with the log entry, if any */
    backend?: string
    /** Timestamp of the log entry */
    date?: string
    /** Log entry text */
    text: string
  }>
}

useHead({ title: 'Index' })

const lastHistory = ref<Array<HistoryItem>>([])
const logs = ref<Array<IndexLogFile>>([])
const reloadingLogs = ref<boolean>(false)
const poster_enable = useStorage('poster_enable', true)
const historyLoading = ref<boolean>(true)
const autoReloadLogs = useStorage<boolean>('auto_reload_logs', true)
const logReloadInterval = ref<ReturnType<typeof setInterval> | null>(null)
const logReloadFrequency = 10000

const loadContent = async (): Promise<void> => {
  try {
    const response = await request(`/history?perpage=6`)
    if (response.ok) {
      const historyResponse = await parse_api_response<{
        history: Array<HistoryItem>,
        total: number,
        page: number,
        perpage: number,
      }>(response)

      if ('error' in historyResponse || 'index' !== useRoute().name) {
        return
      }

      lastHistory.value = historyResponse.history
    }
  } catch {
  } finally {
    historyLoading.value = false
  }

  if (lastHistory.value.length > 0) {
    for (const item of lastHistory.value) {
      if (item.duplicate_reference_ids && item.duplicate_reference_ids.length > 0) {
        continue
      }

      try {
        const response = await request(`/history/${item.id}/duplicates`)
        if (response.ok) {
          const historyResponse = await parse_api_response<{duplicate_reference_ids: Array<number>}>(response)

          if ('error' in historyResponse || 'index' !== useRoute().name) {
            continue
          }

          item.duplicate_reference_ids = historyResponse.duplicate_reference_ids
        }
      } catch {
      }
    }
  }
}

const reloadLogs = async (): Promise<void> => {
  if (reloadingLogs.value) {
    return
  }

  try {
    reloadingLogs.value = true
    const response = await request(`/logs/recent`)
    if (!response.ok) {
      return
    }
    const logsResponse = await parse_api_response<Array<IndexLogFile>>(response)
    if ('error' in logsResponse) {
      return
    }
    if ('index' !== useRoute().name) {
      return
    }

    logs.value = logsResponse
  } catch { } finally {
    reloadingLogs.value = false
  }
}

const stopLogsAutoReload = () => {
  if (null === logReloadInterval.value) {
    return
  }

  clearInterval(logReloadInterval.value)
  logReloadInterval.value = null
}

const startLogsAutoReload = () => {
  if (false === autoReloadLogs.value || null !== logReloadInterval.value) {
    return
  }

  logReloadInterval.value = setInterval(() => reloadLogs(), logReloadFrequency)
}

const toggleLogsAutoReload = () => {
  autoReloadLogs.value = !autoReloadLogs.value
  if (true === autoReloadLogs.value) {
    void reloadLogs()
    startLogsAutoReload()
    return
  }

  stopLogsAutoReload()
}

onMounted(async () => {
  const tasks = [loadContent(), reloadLogs()]
  await Promise.all(tasks)
  startLogsAutoReload()
})

onUpdated(() => document.querySelectorAll('.logs-container').forEach(el => el.scrollTop = el.scrollHeight))

watch(autoReloadLogs, (value: boolean) => {
  if (true === value) {
    startLogsAutoReload()
    return
  }

  stopLogsAutoReload()
})

onBeforeUnmount(() => stopLogsAutoReload())
</script>
