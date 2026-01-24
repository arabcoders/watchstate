<template>
  <div>
    <div class="columns is-multiline" v-if="isLoading || isError">
      <div class="column is-12">
        <Message message_class="is-background-info-90 has-text-dark" title="Loading" v-if="isLoading"
                 icon="fas fa-spinner fa-spin" message="Loading backend settings. Please wait..."/>

        <Message message_class="has-background-warning-80 has-text-dark" icon="fas fa-exclamation-triangle"
                 title="Warning" v-if="!isLoading && isError">
          <p>
            <span class="icon"><i class="fas fa-exclamation"></i></span>
            There was error loading your backend data. Please try again later.
          </p>
          <div v-if="error">
            <pre><code>{{ error }}</code></pre>
          </div>
        </Message>
      </div>
    </div>

    <template v-if="!isLoading && !isError">
      <div class="columns is-multiline">
        <div class="column is-12 is-clearfix is-unselectable">
          <span class="title is-4">
            <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
            <NuxtLink to="/backends">Backends</NuxtLink>
            : {{ backend }}
          </span>
          <div class="is-pulled-right">
            <div class="field is-grouped">
              <p class="control">
                <NuxtLink class="button is-danger" v-tooltip.bottom="'Delete Backend'"
                          :to="`/backend/${backend}/delete`">
                  <span class="icon"><i class="fas fa-trash"></i></span>
                </NuxtLink>
              </p>
              <p class="control">
                <NuxtLink class="button is-primary" v-tooltip.bottom="'Edit Backend'" :to="`/backend/${backend}/edit`">
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
                <NuxtLink :to="`/backend/${backend}/mismatched`">Find possible mis-identified content.</NuxtLink>
              </li>
              <li>
                <NuxtLink :to="`/backend/${backend}/unmatched`">Find unmatched content.</NuxtLink>
              </li>
              <li>
                <NuxtLink :to="`/backend/${backend}/libraries`">View backend libraries.</NuxtLink>
              </li>
              <li>
                <NuxtLink :to="`/backend/${backend}/users`">View backend users.</NuxtLink>
              </li>
              <li>
                <NuxtLink :to="`/backend/${backend}/sessions`">View active sessions.</NuxtLink>
              </li>
              <li>
                <NuxtLink :to="`/backend/${backend}/search`">Search backend content.</NuxtLink>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <div class="columns" v-if="bHistory.length < 1">
        <div class="column is-12">
          <Message message_class="is-background-warning-80 has-text-dark" title="Warning"
                   icon="fas fa-exclamation-circle"
                   message="No items were found. There are probably no items in the local database yet or the backend data not imported yet."/>
        </div>
      </div>

      <div class="columns is-multiline" v-else>
        <div class="column is-12">
          <h1 class="title is-4">Recent History</h1>
        </div>
        <div class="column is-6-tablet" v-for="item in bHistory" :key="item.id">
          <div class="card" :class="{ 'is-success': item.watched }">
            <div class="card-content p-0 m-0">
              <header class="card-header">
                <p class="card-header-title is-text-overflow pr-1">
                  <FloatingImage :image="`/history/${item.id}/images/poster`" :item_class="'scaled-image'"
                                 v-if="poster_enable">
                    <NuxtLink :to="`/history/${item.id}`">{{ item?.full_title || makeName(item as unknown as JsonObject) }}</NuxtLink>
                  </FloatingImage>
                  <NuxtLink :to="`/history/${item.id}`" v-else>{{ item?.full_title || makeName(item as unknown as JsonObject) }}</NuxtLink>
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
                      <span class="has-tooltip"
                            v-tooltip="`Updated at: ${moment.unix( item.updated || item.updated_at ).format(TOOLTIP_DATE_FORMAT)}`">
                        {{ moment.unix(item.updated || item.updated_at).fromNow() }}
                      </span>
                    </span>
                  </div>
                  <div class="column is-4-tablet is-6-mobile has-text-right-mobile">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-server"></i></span>
                      <span>
                        <NuxtLink :to="'/backend/' + item.via">{{ item.via }}</NuxtLink>
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
                <div class="card-footer-item">{{
                    formatDuration(typeof item.progress === 'number' ? item.progress :
                        parseInt(String(item.progress), 10) || 0)
                  }}
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="column is-12">
          <NuxtLink :to="`/history/?perpage=50&page=1&q=${backend}.via://${backend}&key=metadata`">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-history"></i></span>
              <span>View all history related to this backend</span>
            </span>
          </NuxtLink>
        </div>
      </div>

      <div class="columns is-multiline" v-if="info">
        <div class="column is-12">
          <h1 class="title is-4">Basic info</h1>
        </div>
        <div class="column is-12">
          <div class="mt-2" style="position: relative;">
            <code class="is-terminal is-block is-pre-wrap" v-text="info"/>
            <button class="button m-4" v-tooltip="'Copy text'" style="position: absolute; top:0; right:0;"
                    @click="() => copyText(JSON.stringify(info, null, 2))">
              <span class="icon"><i class="fas fa-copy"/></span>
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import moment from 'moment'
import {onMounted, ref} from 'vue'
import {useHead, useRoute} from '#app'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message.vue'
import {NuxtLink} from '#components'
import FloatingImage from '~/components/FloatingImage.vue'
import {copyText, formatDuration, makeName, parse_api_response, request, TOOLTIP_DATE_FORMAT} from '~/utils'
import type {GenericError, HistoryItem, JsonObject} from '~/types'

const poster_enable = useStorage('poster_enable', true)

const backend = ref<string>(useRoute().params.backend as string)
const bHistory = ref<Array<HistoryItem>>([])
const info = ref<JsonObject | null>(null)
const isLoading = ref<boolean>(true)
const isError = ref<boolean>(false)
const error = ref<string | null>(null)

const loadRecentHistory = async (): Promise<void> => {
  if (!backend.value) {
    return
  }
  const search = new URLSearchParams()
  search.append('perpage', '6')
  search.append('key', 'metadata')
  search.append('q', `${backend.value}.via://${backend.value}`)
  search.append('sort', 'updated_at:desc')

  try {
    const response = await request(`/history/?${search.toString()}`)
    const json = await parse_api_response<{
      /** Array of history items */
      history: Array<HistoryItem>
      /** Total number of items available */
      total?: number
      /** Current page number */
      page?: number
      /** Items per page */
      perpage?: number
    }>(response)
    if ('backend-backend' !== useRoute().name) {
      return
    }

    if (response.ok && 'error' in json) {
      return
    }

    if (response.ok && 'history' in json) {
      bHistory.value = json.history
    }
  } catch {
    // Silently handle errors for this non-critical operation
  }
}

const loadInfo = async (): Promise<void> => {
  try {
    isLoading.value = false
    const response = await request(`/backend/${backend.value}/info`)
    const json = await parse_api_response<JsonObject>(response)

    if ('backend-backend' !== useRoute().name) {
      return
    }

    if ('error' in json) {
      const errorJson = json as GenericError
      isError.value = true
      error.value = `${errorJson.error.code}: ${errorJson.error.message}`
      backend.value = ''
      return
    }

    info.value = json

    if (200 !== response.status) {
      isError.value = true
      error.value = 'Unknown error occurred'
      backend.value = ''
      return
    }
    await loadRecentHistory()
    useHead({title: `Backends: ${backend.value}`})
  } catch (e) {
    const message = e instanceof Error ? e.message : String(e)
    error.value = message
    isError.value = true
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => await loadInfo())
</script>
