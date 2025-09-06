<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-file"/></span>
          Files Integrity
        </span>
        <div class="is-pulled-right" v-if="isLoaded">
          <div class="field is-grouped">

            <div class="control has-icons-left" v-if="showFilter">
              <input type="search" v-model.lazy="filter" class="input" id="filter"
                     placeholder="Filter displayed results.">
              <span class="icon is-left">
                <i class="fas fa-filter"/>
              </span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter">
                <span class="icon"><i class="fas fa-filter"/></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-danger" @click="massDelete" v-tooltip.bottom="'Delete selected records.'"
                      :disabled="isDeleting || isLoading || selected_ids.length<1"
                      :class="{'is-loading':isDeleting}">
                <span class="icon"><i class="fas fa-trash"/></span>
              </button>
            </p>

            <div class="control">
              <button class="button is-info is-light" @click="selectAll = !selectAll"
                      data-tooltip="Toggle select all">
                <span class="icon">
                  <i class="fas fa-check-square" :class="{ 'fa-check-square': !selectAll, 'fa-square':selectAll}"/>
                </span>
              </button>
            </div>

            <p class="control" v-if="isCached">
              <button class="button is-danger is-light" @click="emptyCache"
                      v-tooltip.bottom="'Empty cache.'" :disabled="isDeleting || isLoading">
                <span class="icon"><i class="fas fa-box-archive"/></span>
              </button>
            </p>

            <p class="control">
              <button class="button is-info" @click.prevent="loadContent()" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This page will show records with files that no longer exist on the system.</span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoaded">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>

        <Message message_class="has-background-warning-80 has-text-dark" v-if="filter && filteredRows(items).length < 1"
                 title="Information"
                 icon="fas fa-check">
          The filter <code>{{ filter }}</code> did not match any records.
        </Message>
        <Message message_class="has-background-success-90 has-text-dark" v-else title="Success" icon="fas fa-check"
                 v-if="!isLoading && items.length<1">
          WatchState did not find any file references that are no longer on the system.
        </Message>
      </div>
    </div>

    <div class="columns is-multiline" v-if="!isLoaded">
      <div class="column is-12">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-justify-center">Request File integrity check.</p>
          </header>
          <div class="card-content">
            <div class="content">
              <ul>
                <li>
                  Please be aware, this process will take time. You will see the spinner while <code>WatchState</code>
                  is analyzing the entire history records. Do not reload the page.
                </li>
                <li>This check <strong><code>REQUIRES</code></strong> that the file contents be accessible to
                  <code>WatchState</code>. You should mount your library in <code>compose.yml</code> file as readonly.
                  <span class="is-bold">If you do not mount your library. every record will fail the check.</span>
                </li>
                <li>There are no path replacement support at the moment. The pathing must match what your media servers
                  are reporting. There are plans to add this feature in the future.
                </li>
                <li>This process will do two checks, One will do dir stat on the file directory, and file stat on the
                  file itself if the directory exists. <span class="has-text-danger">If you are using cloud storage, we
                    recommend to not use this check. as it will be slow. and probably will cost you a lot of
                    money.</span>
                </li>
                <li>The process caches the file and dir stat, as such we only run stat once per file or directory no
                  matter how many backends reports the same path or file.
                </li>
                <li>The results are cached server side for one hour from the request.</li>
              </ul>
            </div>
          </div>
          <div class="control">
            <button class="button is-fullwidth is-primary" @click="loadContent" :disabled="isLoading">
              <span class="icon"><i class="fas fa-check"/></span>
              <span>Initiate The process</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="columns is-multiline" v-if="!isLoading && isLoaded">
      <div class="column is-12">
        <div class="columns is-multiline" v-if="filteredRows(items)?.length>0">
          <template v-for="item in items" :key="item.id">
            <Lazy :unrender="true" :min-height="343" class="column is-6-tablet" v-if="filterItem(item)">
              <div class="card is-flex is-full-height is-flex-direction-column" :class="{ 'is-success': item.watched }">
                <header class="card-header">
                  <p class="card-header-title is-text-overflow pr-1">
                    <span class="icon">
                      <label class="checkbox">
                        <input type="checkbox" :value="item.id" v-model="selected_ids">
                      </label>&nbsp;
                    </span>
                    <FloatingImage :image="`/history/${item.id}/images/poster`" :item_class="'scaled-image'"
                                   v-if="poster_enable">
                      <NuxtLink :to="`/history/${item.id}`" v-text="makeName(item)"/>
                    </FloatingImage>
                    <NuxtLink :to="`/history/${item.id}`" v-text="makeName(item)" v-else/>
                  </p>
                  <span class="card-header-icon" @click="item.showRawData = !item?.showRawData">
                    <span class="icon">
                      <i class="fas"
                         :class="{ 'fa-tv': 'episode' === item.type.toLowerCase(), 'fa-film': 'movie' === item.type.toLowerCase()}"/>
                    </span>
                  </span>
                </header>
                <div class="card-content is-flex-grow-1">
                  <div class="columns is-multiline is-mobile">
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_title, 'is-text-contents': item?.expand_title}"
                             @click="item.expand_title = !item?.expand_title">
                          <span class="icon"><i class="fas fa-heading"/>&nbsp;</span>
                          <template v-if="item?.content_title">
                            <NuxtLink :to="makeSearchLink('subtitle', item.content_title)" v-text="item.content_title"/>
                          </template>
                          <template v-else>
                            <NuxtLink :to="makeSearchLink('subtitle', item.title)" v-text="item.title"/>
                          </template>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable"
                                @click="copyText(item?.content_title ?? item.title, false)">
                            <i class="fas fa-copy"/></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path}"
                             @click="item.expand_path = !item?.expand_path">
                          <span class="icon"><i class="fas fa-file"/>&nbsp;</span>
                          <NuxtLink v-if="item?.content_path" :to="makeSearchLink('path', item.content_path)"
                                    v-text="item.content_path"/>
                          <span v-else>No path found.</span>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable"
                                @click="copyText(item?.content_path ? item.content_path : '', false)">
                            <i class="fas fa-copy"/></span>
                        </div>
                      </div>
                    </div>

                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-expanded is-unselectable">
                          <span class="icon"><i class="fas fa-info"/>&nbsp;</span>
                          <span>Has metadata</span>
                        </div>
                        <div class="control">
                          <template v-for="backend in item.reported_by" :key="`${item.id}-rb-${backend}`">
                            <NuxtLink :to="'/backend/'+backend" class="tag is-primary ml-1">
                              <span class="icon"><i class="fas fa-check"/></span>
                              <span v-text="backend"/>
                            </NuxtLink>
                          </template>
                          <template v-for="backend in item.not_reported_by" :key="`${item.id}-rb-${backend}`">
                            <NuxtLink :to="'/backend/'+backend" class="tag is-danger ml-1">
                              <span class="icon"><i class="fas fa-xmark"/></span>
                              <span v-text="backend"/>
                            </NuxtLink>
                          </template>
                        </div>
                      </div>
                    </div>

                    <div class="column is-12" v-if="item?.integrity">
                      <div class="field is-grouped">
                        <div class="control is-expanded is-unselectable">
                          <span class="icon"><i class="fas fa-file"/>&nbsp;</span>
                          <span>File reference exists</span>
                        </div>
                        <div class="control">
                          <NuxtLink
                              v-for="record in item.integrity" :key="`${item.id}-int-${record.backend}`"
                              :to="'/backend/'+record.backend" class="tag ml-1"
                              :class="{ 'is-danger': !record.status, 'is-primary': record.status}"
                              v-tooltip.bottom="!record.status ? record.backend + ': ' + record.message : ''">
                            <span class="icon">
                              <i class="fas" :class="{ 'fa-xmark': !record.status, 'fa-check': record.status}"/>
                            </span>
                            <span v-text="record.backend"/>
                          </NuxtLink>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="card-content p-0 m-0" v-if="item?.showRawData">
                  <pre style="position: relative; max-height: 343px;"
                       class="is-terminal"><code>{{ JSON.stringify(item, null, 2) }}</code>
                    <button class="button is-small m-4" @click="() => copyText(JSON.stringify(item, null, 2))"
                            style="position: absolute; top:0; right:0;">
                      <span class="icon"><i class="fas fa-copy"/></span>
                    </button>
                  </pre>
                </div>
                <div class="card-footer">
                  <div class="card-footer-item">
                    <span class="icon">
                      <i class="fas" :class="{'fa-eye':item.watched,'fa-eye-slash':!item.watched}"/>&nbsp;
                    </span>
                    <span class="has-text-success" v-if="item.watched">Played</span>
                    <span class="has-text-danger" v-else>Unplayed</span>
                  </div>
                  <div class="card-footer-item">
                    <span class="icon"><i class="fas fa-calendar"/>&nbsp;</span>
                    <span class="has-tooltip"
                          v-tooltip="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                      {{ moment.unix(item.updated_at).fromNow() }}
                    </span>
                  </div>
                </div>
              </div>
            </lazy>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, watch, onMounted} from 'vue'
import {useHead, useRoute} from '#app'
import {useStorage} from '@vueuse/core'
import request from '~/utils/request'
import Message from '~/components/Message.vue'
import {awaitElement, copyText, makeName, makeSearchLink, notification, TOOLTIP_DATE_FORMAT} from '~/utils'
import moment from 'moment'
import Lazy from '~/components/Lazy.vue'
import {useSessionCache} from '~/utils/cache'
import type {GenericError} from '~/types/responses'
import {useDialog} from '~/composables/useDialog'
import {NuxtLink} from "#components";
import FloatingImage from "~/components/FloatingImage.vue"

useHead({title: 'File Integrity'})

/**
 * Status of a file integrity check for a specific backend
 */
type IntegrityBackendStatus = {
  /** Backend name (e.g., 'plex', 'jellyfin', etc.) */
  backend: string
  /** Status of the file (true = exists, false = missing) */
  status: boolean
  /** Optional error or status message */
  message?: string
}

/**
 * Main item displayed in the integrity list
 */
type IntegrityItem = {
  /** Unique record ID */
  id: number
  /** Type of item (e.g., 'movie', 'episode') */
  type: string
  /** Title of the content */
  title: string
  /** Optional subtitle/content title */
  content_title?: string
  /** Path to the file */
  content_path?: string
  /** True if the item has been watched */
  watched: boolean
  /** Unix timestamp of last update */
  updated_at: number
  /** Backends that reported this item */
  reported_by: Array<string>
  /** Backends that did NOT report this item */
  not_reported_by: Array<string>
  /** File integrity status per backend */
  integrity?: Array<IntegrityBackendStatus>
  /** UI state: expanded title */
  expand_title?: boolean
  /** UI state: expanded path */
  expand_path?: boolean
  /** UI state: show raw data */
  showRawData?: boolean
}

/**
 * API response for /system/integrity
 */
type IntegrityApiResponse = {
  /** List of integrity items */
  items: Array<IntegrityItem>
  /** True if the response was served from cache */
  fromCache?: boolean
}

const api_user = useStorage('api_user', 'main')
const poster_enable = useStorage('poster_enable', true)
const cache = useSessionCache(api_user.value)

const items = ref<Array<IntegrityItem>>([])
const isLoading = ref<boolean>(false)
const isLoaded = ref<boolean>(false)
const selected_ids = ref<Array<number>>([])
const isDeleting = ref<boolean>(false)
const filter = ref<string>('')
const showFilter = ref<boolean>(false)
const isCached = ref<boolean>(false)
const selectAll = ref<boolean>(false)
const massActionInProgress = ref<boolean>(false)

watch(selectAll, v => {
  selected_ids.value = v ? filteredRows(items.value).map(i => i.id) : []
})

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value
  if (!showFilter.value) {
    filter.value = ''
    return
  }
  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus())
}

const loadContent = async (): Promise<void> => {
  isLoaded.value = true
  isLoading.value = true
  items.value = []
  selectAll.value = false
  selected_ids.value = []

  try {
    const response = await request(`/system/integrity`)
    const json: IntegrityApiResponse | GenericError = await response.json()

    if ('integrity' !== useRoute().name) {
      return
    }

    if (200 !== response.status) {
      if ('error' in json) {
        notification('error', 'Error', `API Error. ${json.error?.code}: ${json.error?.message}`)
      }

      isLoading.value = false
      return
    }

    if (!('items' in json)) {
      notification('error', 'Error', `API Error. Malformed response.`)
      isLoading.value = false
      return
    }

    if (json.items) {
      items.value = json.items
    }

    isLoading.value = false
    isCached.value = Boolean(json?.fromCache ?? false)

    cache.set('integrity', {items: items.value, fromCache: isCached.value})
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'message' in e) {
      notification('error', 'Error', `Request error. ${(e as Error).message}`)
    } else {
      notification('error', 'Error', 'Unknown error')
    }
  }
}

const massDelete = async (): Promise<void> => {
  if (0 === selected_ids.value.length) {
    return
  }

  const {status: confirmStatus} = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Are you sure you want to delete '${selected_ids.value.length}' item/s?`,
    confirmColor: 'is-danger',
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    isDeleting.value = true

    const urls = selected_ids.value.map(id => `/history/${id}`)

    notification('success', 'Action in progress', `Deleting '${urls.length}' item/s. Please wait...`)

    // -- check each request response after all requests are done
    const requests = await Promise.all(urls.map(url => request(url, {method: 'DELETE'})))

    if (!requests.every(response => 200 === response.status)) {
      notification('error', 'Error', `Some requests failed. Please check the console for more details.`)
    } else {
      items.value = items.value.filter(i => !selected_ids.value.includes(i.id))
    }

    notification('success', 'Success', `Deleting '${urls.length}' item/s completed.`)
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'message' in e) {
      notification('error', 'Error', `Request error. ${(e as Error).message}`)
    } else {
      notification('error', 'Error', 'Unknown error')
    }
  } finally {
    massActionInProgress.value = false
    selected_ids.value = []
    selectAll.value = false
  }
}

const emptyCache = async (): Promise<void> => {

  const {status: confirmStatus} = await useDialog().confirmDialog({
    title: 'Confirm Cache Purge',
    message: `Are you sure you want to purge the file stats cache?`,
    confirmColor: 'is-danger',
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    const response = await request(`/system/integrity`, {method: 'DELETE'})
    if (200 !== response.status) {
      const json = await response.json()
      return notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
    }

    items.value = []
    isLoaded.value = false
    isLoading.value = false
    isCached.value = false
    selectAll.value = false
    selected_ids.value = []
    if (cache.has('integrity')) {
      cache.remove('integrity')
    }

    notification('success', 'Success', `Cache purged.`)
  } catch (e: unknown) {
    if (e && typeof e === 'object' && 'message' in e) {
      notification('error', 'Error', `Request error. ${(e as Error).message}`)
    } else {
      notification('error', 'Error', 'Unknown error')
    }
  } finally {
    massActionInProgress.value = false
    selected_ids.value = []
    selectAll.value = false
  }
}

const filteredRows = (items: Array<IntegrityItem>): Array<IntegrityItem> => {
  if (!filter.value) {
    return items
  }
  return items.filter(i => Object.values(i).some(v => 'string' === typeof v ? v.toLowerCase().includes(filter.value.toLowerCase()) : false))
}

const filterItem = (item: IntegrityItem): boolean => {
  if (!filter.value || !item) {
    return true
  }
  return Object.values(item).some(v => 'string' === typeof v ? v.toLowerCase().includes(filter.value.toLowerCase()) : false)
}

onMounted(() => {
  cache.setNameSpace(api_user.value)
  if (items.value.length < 1 && cache.has('integrity')) {
    const cachedData = cache.get('integrity') as { items: Array<IntegrityItem>, fromCache: boolean }
    items.value = cachedData.items
    isCached.value = cachedData.fromCache
    isLoaded.value = true
  }
})
</script>
