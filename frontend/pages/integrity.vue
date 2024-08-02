<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-file"></i></span>
          Files Integrity
        </span>
        <div class="is-pulled-right" v-if="isLoaded">
          <div class="field is-grouped">

            <p class="control" v-if="isCached">
              <button class="button is-danger" @click="emptyCache" v-tooltip.bottom="'Empty cache.'"
                      :disabled="isDeleting || isLoading">
                <span class="icon"><i class="fas fa-box-archive"></i></span>
              </button>
            </p>

            <div class="control has-icons-left" v-if="showFilter">
              <input type="search" v-model.lazy="filter" class="input" id="filter"
                     placeholder="Filter displayed results.">
              <span class="icon is-left">
                <i class="fas fa-filter"></i>
              </span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter">
                <span class="icon"><i class="fas fa-filter"></i></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-danger" @click="massDelete" v-tooltip.bottom="'Delete selected records.'"
                      :disabled="isDeleting || isLoading || selected_ids.length<1"
                      :class="{'is-loading':isDeleting}">
                <span class="icon"><i class="fas fa-trash"></i></span>
              </button>
            </p>

            <div class="control">
              <button class="button is-info is-light" @click="selectAll = !selectAll"
                      data-tooltip="Toggle select all">
                <span class="icon">
                  <i class="fas fa-check-square"
                     :class="{ 'fa-check-square': !selectAll, 'fa-square':selectAll}"></i>
                </span>
              </button>
            </div>

            <p class="control">
              <button class="button is-info" @click.prevent="loadContent()" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"></i></span>
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
              <span class="icon"><i class="fas fa-check"></i></span>
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
              <div class="card" :class="{ 'is-success': item.watched }">
                <header class="card-header">
                  <p class="card-header-title is-text-overflow pr-1">
                    <span class="icon">
                      <label class="checkbox">
                        <input type="checkbox" :value="item.id" v-model="selected_ids">
                      </label>&nbsp;
                    </span>
                    <NuxtLink :to="'/history/'+item.id" v-text="makeName(item)"/>
                  </p>
                  <span class="card-header-icon" @click="item.showRawData = !item?.showRawData">
                    <span class="icon">
                      <i class="fas"
                         :class="{ 'fa-tv': 'episode' === item.type.toLowerCase(), 'fa-film': 'movie' === item.type.toLowerCase()}"></i>
                    </span>
                  </span>
                </header>
                <div class="card-content">
                  <div class="columns is-multiline is-mobile">
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_title, 'is-text-contents': item?.expand_title}"
                             @click="item.expand_title = !item?.expand_title">
                          <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
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
                            <i class="fas fa-copy"></i></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path}"
                             @click="item.expand_path = !item?.expand_path">
                          <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                          <NuxtLink v-if="item?.content_path" :to="makeSearchLink('path', item.content_path)"
                                    v-text="item.content_path"/>
                          <span v-else>No path found.</span>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable"
                                @click="copyText(item?.content_path ?item.content_path : null, false)">
                            <i class="fas fa-copy"></i></span>
                        </div>
                      </div>
                    </div>

                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-expanded is-unselectable">
                          <span class="icon"><i class="fas fa-info"></i>&nbsp;</span>
                          <span>Has metadata from</span>
                        </div>
                        <div class="control">
                          <template v-for="backend in item.reported_by" :key="`${item.id}-rb-${backend}`">
                            <NuxtLink :to="'/backend/'+backend" v-text="backend" class="tag is-primary ml-1"/>
                          </template>
                          <template v-for="backend in item.not_reported_by" :key="`${item.id}-rb-${backend}`">
                            <NuxtLink :to="'/backend/'+backend" v-text="backend" class="tag is-danger ml-1"/>
                          </template>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12" v-if="item?.integrity">
                      <template v-for="record in item.integrity" :key="`integrity-${record.backend}`">
                        <p>
                          <span class="icon">
                            <i class="fas"
                               :class="{'fa-xmark':!record.status,'fa-check':record.status}"></i>&nbsp;
                          </span>
                          <span :class="{'has-text-danger':!record.status,'has-text-success':record.status}">
                            {{ record.backend }}: {{ record.message }}</span>
                        </p>
                      </template>
                    </div>
                  </div>
                </div>
                <div class="card-content p-0 m-0" v-if="item?.showRawData">
                  <pre style="position: relative; max-height: 343px;"><code>{{ JSON.stringify(item, null, 2) }}</code>
                    <button class="button is-small m-4" @click="() => copyText(JSON.stringify(item, null, 2))"
                            style="position: absolute; top:0; right:0;">
                      <span class="icon"><i class="fas fa-copy"></i></span>
                    </button>
                  </pre>
                </div>
                <div class="card-footer">
                  <div class="card-footer-item">
                    <span class="icon">
                      <i class="fas" :class="{'fa-eye':item.watched,'fa-eye-slash':!item.watched}"></i>&nbsp;
                    </span>
                    <span class="has-text-success" v-if="item.watched">Played</span>
                    <span class="has-text-danger" v-else>Unplayed</span>
                  </div>
                  <div class="card-footer-item">
                    <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
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

<script setup>
import request from '~/utils/request'
import Message from '~/components/Message'
import {awaitElement, copyText, makeName, makeSearchLink, notification, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import moment from 'moment'
import Lazy from '~/components/Lazy'
import {useSessionCache} from '~/utils/cache'

useHead({title: 'File Integrity'})

const items = ref([])
const isLoading = ref(false)
const isLoaded = ref(false)
const selected_ids = ref([])
const isDeleting = ref(false)
const filter = ref('')
const showFilter = ref(false)
const isCached = ref(false)
const cache = useSessionCache()

const selectAll = ref(false)
const massActionInProgress = ref(false)
watch(selectAll, v => selected_ids.value = v ? filteredRows(items.value).map(i => i.id) : [])

const toggleFilter = () => {
  showFilter.value = !showFilter.value
  if (!showFilter.value) {
    filter.value = ''
    return
  }

  awaitElement('#filter', (_, elm) => elm.focus())
}

const loadContent = async () => {
  isLoaded.value = true
  isLoading.value = true
  items.value = []
  selectAll.value = false
  selected_ids.value = []

  try {
    const response = await request(`/system/integrity`)
    const json = await response.json()

    if (useRoute().name !== 'integrity') {
      return
    }

    if (200 !== response.status) {
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
      isLoading.value = false
      return
    }

    if (json.items) {
      items.value = json.items
    }

    isLoading.value = false
    isCached.value = Boolean(json?.fromCache ?? false)

    cache.set('integrity', {
      items: items.value,
      fromCache: isCached.value
    })
  } catch (e) {
    console.error(e)
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const massDelete = async () => {
  if (0 === selected_ids.value.length) {
    return
  }

  if (!confirm(`Are you sure you want to delete '${selected_ids.value.length}' item/s?`)) {
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
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    massActionInProgress.value = false
    selected_ids.value = []
    selectAll.value = false
  }
}

const emptyCache = async () => {
  if (!confirm(`Are you sure you want to purge file stats cache?`)) {
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
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    massActionInProgress.value = false
    selected_ids.value = []
    selectAll.value = false
  }
}

const filteredRows = items => {
  if (!filter.value) {
    return items
  }

  return items.filter(i => Object.values(i).some(v => typeof v === 'string' ? v.toLowerCase().includes(filter.value.toLowerCase()) : false))
}

const filterItem = item => {
  if (!filter.value || !item) {
    return true
  }

  return Object.values(item).some(v => typeof v === 'string' ? v.toLowerCase().includes(filter.value.toLowerCase()) : false)
}

onMounted(() => {
  if (items.value.length < 1 && cache.has('integrity')) {
    const cachedData = cache.get('integrity')
    items.value = cachedData.items
    isCached.value = cachedData.fromCache
    isLoaded.value = true
  }
})
</script>
