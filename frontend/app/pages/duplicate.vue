<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4 ">
          <span class="icon"><i class="fas fa-copy"/></span>
          Duplicate File Reference
        </span>
        <div class="is-pulled-right">
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

            <div class="control">
              <button class="button is-danger" @click="deleteRecords">
                <span class="icon"><i class="fas fa-trash"/></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-info" @click.prevent="loadContent(page, true, true)" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This tool is useful to discover if your backends are reporting different metadata for same files.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="total && last_page > 1">
        <div class="field is-grouped">
          <div class="control" v-if="page !== 1">
            <button rel="first" class="button" @click="loadContent(1)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span><<</span>
            </button>
          </div>
          <div class="control" v-if="page > 1 && (page-1) !== 1">
            <button rel="prev" class="button" @click="loadContent(page-1)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span><</span>
            </button>
          </div>
          <div class="control">
            <div class="select">
              <select v-model="page" @change="loadContent(page)" :disabled="isLoading">
                <option v-for="(item, index) in makePagination(page, last_page)" :key="index" :value="item.page">
                  {{ item.text }}
                </option>
              </select>
            </div>
          </div>
          <div class="control" v-if="page !== last_page && (page+1) !== last_page">
            <button rel="next" class="button" @click="loadContent(page+1)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span>></span>
            </button>
          </div>
          <div class="control" v-if="page !== last_page">
            <button rel="last" class="button" @click="loadContent(last_page)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span>>></span>
            </button>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="filteredRows(items)?.length>0">
          <template v-for="item in items" :key="item.id">
            <Lazy :unrender="true" :min-height="270" class="column is-6-tablet" v-if="filterItem(item)">
              <div class="card" :class="{ 'is-success': item.watched }">
                <header class="card-header">
                  <p class="card-header-title is-text-overflow pr-1">
                    <FloatingImage :image="`/history/${item.id}/images/poster`" :item_class="'scaled-image'"
                                   v-if="poster_enable">
                      <NuxtLink :to="'/history/'+item.id" v-text="item?.full_title || makeName(item)"/>
                    </FloatingImage>
                    <NuxtLink :to="'/history/'+item.id" v-text="item?.full_title || makeName(item)" v-else/>
                  </p>
                  <span class="card-header-icon" @click="item.showRawData = !item?.showRawData">
                    <span class="icon">
                      <i class="fas"
                         :class="{ 'fa-tv': 'episode' === item.type.toLowerCase(), 'fa-film': 'movie' === item.type.toLowerCase()}"/>
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
                          <span class="icon"><i class="fas fa-heading"/>&nbsp;</span>
                          <template v-if="item?.content_title">
                            <NuxtLink :to="makeSearchLink('subtitle', item.content_title)"
                                      v-text="item.content_title"/>
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
                             @click="item.expand_path = !item?.expand_path" v-tooltip="item.content_path">
                          <span class="icon"><i class="fas fa-file"/>&nbsp;</span>
                          <NuxtLink v-if="item?.content_path" :to="makeSearchLink('path', item.content_path)"
                                    v-text="item.content_path"/>
                          <span v-else>No path found.</span>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable"
                                @click="copyText(item?.content_path ?item.content_path : null, false)">
                            <i class="fas fa-copy"/></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-expanded is-unselectable">
                          <span class="icon"><i class="fas fa-info"/>&nbsp;</span>
                          <span>Has metadata from</span>
                        </div>
                        <div class="control">
                          <NuxtLink v-for="backend in item.reported_by" :key="`${item.id}-rb-${backend}`"
                                    :to="'/backend/'+backend" v-text="backend" class="tag is-primary ml-1"/>
                          <NuxtLink v-for="backend in item.not_reported_by" :key="`${item.id}-nrb-${backend}`"
                                    :to="'/backend/'+backend" v-text="backend" class="tag is-danger ml-1"/>
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

        <div class="column is-12" v-else>
          <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                   icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
          <template v-else>
            <Message message_class="has-background-warning-80 has-text-dark" v-if="filter && items.length > 1"
                     title="Information" icon="fas fa-check">
              The filter <code>{{ filter }}</code> did not match any thing.
            </Message>
            <Message message_class="has-background-success-90 has-text-dark" v-if="!filter || items.length < 1"
                     title="Success" icon="fas fa-check">
              There are no duplicate file references in the database.
            </Message>
          </template>
        </div>

        <div class="column is-12">
          <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                   @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
            <ul>
              <li>This checker will only works <b>if your media servers are actually using same file paths</b>.</li>
              <li>If you see multi-episode records, that mean your metadata need to be forcibly updated. Go to backends
                page and select the <code>9th</code> option to force metadata update for that backend.
              </li>
              <li>The initial request is quite slow as we traverse the entire database looking for duplicate file
                references. Once the initial request is done, the subsequent requests will be much faster as we cache
                the results. To force cache invalidation, you have to click on the reload button.
              </li>
            </ul>
          </Message>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import Message from '~/components/Message.vue'
import {
  awaitElement,
  copyText,
  makeName,
  makePagination,
  makeSearchLink,
  notification,
  TOOLTIP_DATE_FORMAT
} from '~/utils/index'
import moment from 'moment'
import {useStorage} from '@vueuse/core'
import Lazy from '~/components/Lazy.vue'
import {NuxtLink} from "#components";
import FloatingImage from "~/components/FloatingImage.vue";
import {useDialog} from "~/composables/useDialog.js";

const route = useRoute()

useHead({title: 'DFR'})

const items = ref([])
const page = ref(route.query.page ?? 1)
const perpage = ref(route.query.perpage ?? 50)
const total = ref(0)
const last_page = computed(() => Math.ceil(total.value / perpage.value))
const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)
const filter = ref(route.query.filter ?? '')
const showFilter = ref(!!filter.value)
const poster_enable = useStorage('poster_enable', true)

const toggleFilter = () => {
  showFilter.value = !showFilter.value
  if (!showFilter.value) {
    filter.value = ''
    return
  }

  awaitElement('#filter', (_, elm) => elm.focus())
}
const loadContent = async (pageNumber, fromPopState = false, fromReload = false) => {
  pageNumber = parseInt(pageNumber)

  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1
  }

  const search = new URLSearchParams()
  search.set('perpage', perpage.value)
  search.set('page', pageNumber)

  let pageTitle = `DFR: Page #${pageNumber}`

  if (filter.value) {
    search.set('filter', filter.value)
    pageTitle += ` - Filter: ${filter.value}`
  }

  useHead({title: pageTitle})

  let newUrl = window.location.pathname + '?' + search.toString()
  isLoading.value = true
  items.value = []

  page.value = pageNumber

  try {
    let json

    if (true === fromReload) {
      search.set('no_cache', '1')
    }

    const response = await request(`/system/duplicate/?${search.toString()}`)
    json = await response.json()

    if (useRoute().name !== 'duplicate') {
      return
    }

    if (200 !== response.status) {
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
      isLoading.value = false
      return
    }

    if (!fromPopState && window.location.href !== newUrl) {
      await useRouter().push({
        path: '/duplicate',
        title: pageTitle,
        query: Object.fromEntries(search)
      })
    }

    if ('paging' in json) {
      page.value = json.paging.current_page
      perpage.value = json.paging.perpage
      total.value = json.paging.total
    } else {
      page.value = 1
      total.value = 0
    }

    if (json.items) {
      items.value = json.items
    }

    isLoading.value = false
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

onMounted(async () => {
  await loadContent(page.value ?? 1)
  window.addEventListener('popstate', stateCallBack)
})

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack))

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

watch(filter, val => {
  const route = useRoute()
  const router = useRouter()
  if (!val) {
    if (!route?.query['filter']) {
      return
    }

    router.push({
      'path': '/duplicate',
      'query': {
        ...route.query,
        'filter': undefined
      }
    })
    return
  }

  if (route?.query['filter'] === val) {
    return
  }

  router.push({
    'path': '/duplicate',
    'query': {
      ...route.query,
      'filter': val
    }
  })
})

const stateCallBack = async e => {
  if (!e.state && !e.detail) {
    return
  }

  const route = useRoute()
  page.value = route.query.page ?? 1
  perpage.page = route.query.perpage ?? 50
  filter.value = route.query.filter ?? ''
  if (filter.value) {
    showFilter.value = true
  }
  await loadContent(page.value, true)
}

const deleteRecords = async () => {
  const {confirmDialog} = useDialog()
  const {status} = await confirmDialog({
    title: 'Delete items',
    message: `Delete '${total.value}' items?`,
    confirmText: 'Delete',
    confirmClass: 'is-danger',
    confirmIcon: 'fas fa-trash',
  })

  if (true !== status) {
    return
  }
  try {
    const response = await request('/system/duplicate', {method: 'DELETE'});
    if (!response.ok) {
      const json = await response.json();
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`);
      return;
    }

    notification('success', 'Success', `Successfully deleted '${total.value}' items.`);
    await loadContent(page.value, true, true);
  } catch (error) {
    notification('error', 'Error', `Request error. ${error.message}`);
  }
}
</script>
