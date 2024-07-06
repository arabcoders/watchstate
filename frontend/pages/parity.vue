<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4 ">
        <span class="icon"><i class="fas fa-database"></i></span>
        Data Parity
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
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

          <div class="control" v-if="min && max" v-tooltip.bottom="'Minimum number of backends'">
            <div class="select">
              <select v-model="min" :disabled="isDeleting || isLoading">
                <option v-for="i in numberRange(1,max+1)" :key="`min-${i}`" :value="i">
                  {{ i }}
                </option>
              </select>
            </div>
          </div>
          <p class="control">
            <button class="button is-danger" @click="deleteData" v-tooltip.bottom="'Delete The reported records'"
                    :disabled="isDeleting || isLoading || items.length<1" :class="{'is-loading':isDeleting}">
              <span class="icon"><i class="fas fa-trash"></i></span>
            </button>
          </p>

          <div class="control">
            <button class="button is-info is-light" @click="selectAll = !selectAll"
                    data-tooltip="Toggle select all">
              <span class="icon">
                <i class="fas fa-check-square"
                   :class="{'fa-check-square': !selectAll,'fa-square':selectAll}"></i>
              </span>
            </button>
          </div>

          <p class="control">
            <button class="button is-info" @click.prevent="loadContent(page, true, true)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">This page shows local database records not being reported by the specified number of
          backends.</span>
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

    <div class="column is-12" v-if="selected_ids.length > 0">
      <div class="field is-grouped is-justify-content-center">
        <div class="control">
          <button class="button is-danger" @click="massDelete()" :disabled="massActionInProgress"
                  :class="{'is-loading':massActionInProgress}">
            <span class="icon"><i class="fas fa-trash"></i></span>
            <span class="is-hidden-mobile">Delete '{{ selected_ids.length }}' selected item/s</span>
          </button>
        </div>
      </div>
    </div>

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
                        <span class="icon"><i class="fas fa-check"></i>&nbsp;</span>
                        <span>Reported By</span>
                      </div>
                      <div class="control">
                        <template v-for="backend in item.reported_by" :key="`${item.id}-rb-${backend}`">
                          <NuxtLink :to="'/backend/'+backend" v-text="backend" class="tag"/>
                          &nbsp;
                        </template>
                      </div>
                    </div>
                  </div>

                  <div class="column is-12">
                    <div class="field is-grouped">
                      <div class="control is-expanded is-unselectable">
                        <span class="icon"><i class="fas fa-times"></i>&nbsp;</span>
                        <span>Not Reported By</span>
                      </div>
                      <div class="control">
                        <template v-for="backend in item.not_reported_by" :key="`${item.id}-nrb-${backend}`">
                          <NuxtLink :to="'/backend/'+backend" v-text="backend" class="tag"/>
                          &nbsp;
                        </template>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="card-content p-0 m-0" v-if="item?.showRawData">
                <pre style="position: relative; max-height: 343px;"><code>{{ JSON.stringify(item, null, 2) }}</code>
                  <button class="button m-4" @click="() => copyText(JSON.stringify(item, null, 2))"
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

      <div class="column is-12" v-else>
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <template v-else>
          <Message message_class="has-background-warning-80 has-text-dark" v-if="filter && items.length > 1"
                   title="Information"
                   icon="fas fa-check">
            The filter <code>{{ filter }}</code> did not match any records.
          </Message>
          <Message message_class="has-background-success-90 has-text-dark" v-if="!filter || items.length < 1"
                   title="Success"
                   icon="fas fa-check">
            WatchState did not find any records matching the criteria. All records has at least <code>{{ min }}</code>
            backends reporting it.
          </Message>
        </template>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              You can specify the minimum number of backends that need to report the record to be considered valid.
            </li>
            <li>
              By clicking the <span class="fa fa-trash"></span> icon you will delete the the reported items from the
              local database. If the items are not fixed by the time <code>import</code> is run, they will re-appear.
            </li>
            <li>
              Deleting records works by deleting everything at or below the specified number of backends. For example,
              if you set the minimum to <code>3</code>, all records that are reported by <code>3</code> or fewer
              backends will be deleted.
            </li>
            <li>
              Records showing here most likely means your backends, are not reporting same data. This could be due to
              many reasons, including using different external databases i.e. <code>TheMovieDB</code> vs
              <code>TheTVDB</code>.
            </li>
            <li>
              The results are cached in your browser temporarily to provide faster response, as the operation to
              generate the report is quite intensive. If you want to refresh the data, click the <span
                class="fa fa-sync"></span> icon.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request'
import Message from '~/components/Message'
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
import Lazy from '~/components/Lazy'

const route = useRoute()

useHead({title: 'Parity'})

const items = ref([])
const page = ref(route.query.page ?? 1)
const perpage = ref(route.query.perpage ?? 100)
const total = ref(0)
const last_page = computed(() => Math.ceil(total.value / perpage.value))
const isLoading = ref(false)
const isDeleting = ref(false)
const show_page_tips = useStorage('show_page_tips', true)
const filter = ref(route.query.filter ?? '')
const showFilter = ref(!!filter.value)
const min = ref(route.query.min ?? null);
const max = ref();
const cacheKey = computed(() => `parity-${min.value}-${page.value}-${perpage.value}`)

const selectAll = ref(false)
const selected_ids = ref([])
const massActionInProgress = ref(false)
watch(selectAll, v => selected_ids.value = v ? filteredRows(items.value).map(i => i.id) : []);


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

  let pageTitle = `Parity: Page #${pageNumber}`

  if (min.value) {
    search.set('min', min.value)
    pageTitle += ` - Min: ${min.value}`
  }

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
    let json;

    if (true === fromReload) {
      clearCache();
    }

    if (sessionStorage?.getItem(cacheKey.value)) {
      json = JSON.parse(sessionStorage.getItem(cacheKey.value))
    } else {
      const response = await request(`/system/parity/?${search.toString()}`)
      json = await response.json()
      sessionStorage.setItem(cacheKey.value, JSON.stringify(json))

      if (200 !== response.status) {
        notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
        isLoading.value = false
        return
      }
    }

    if (!fromPopState && window.location.href !== newUrl) {
      await useRouter().push({
        path: '/parity',
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

const massDelete = async () => {
  if (0 === selected_ids.value.length) {
    return
  }

  if (!confirm(`Are you sure you want to delete '${selected_ids.value.length}' item/s?`)) {
    return
  }

  try {
    massActionInProgress.value = true
    const urls = selected_ids.value.map(id => `/history/${id}`)

    notification('success', 'Action in progress', `Deleting '${urls.length}' item/s. Please wait...`)

    // -- check each request response after all requests are done
    const requests = await Promise.all(urls.map(url => request(url, {method: 'DELETE'})))

    if (!requests.every(response => 200 === response.status)) {
      notification('error', 'Error', `Some requests failed. Please check the console for more details.`)
    } else {
      items.value = items.value.filter(i => !selected_ids.value.includes(i.id))
      try {
        sessionStorage?.removeItem(cacheKey.value)
      } catch (e) {
      }
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

const deleteData = async () => {
  if (isDeleting.value) {
    return
  }
  if (!min.value) {
    notification('error', 'Error', 'Minimum number of backends is not set.')
    return
  }

  if (items.value.length < 1) {
    notification('error', 'Error', 'There are no reported records to delete.')
    return
  }

  if (!confirm(`Are you sure you want to delete the reported records?`)) {
    return
  }

  isDeleting.value = true

  try {
    const response = await request(`/system/parity`, {
      method: 'DELETE',
      body: JSON.stringify({min: min.value})
    })

    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success!', `Deleted '${json.deleted_records ?? 0}' records.`)

    items.value = []
    total.value = 0
    filter.value = ''
    page.value = 1

    clearCache();
  } catch (e) {
    notification('error', 'Error', e.message)
  } finally {
    isDeleting.value = false
  }
};

onMounted(async () => {
  const response = await request(`/backends/`)
  const json = await response.json()
  max.value = json.length
  if (min.value === null) {
    min.value = json.length
  } else {
    await loadContent(page.value ?? 1)
  }
  window.addEventListener('popstate', stateCallBack)
})

onUnmounted(() => window.removeEventListener('popstate', stateCallBack))

const numberRange = (start, end) => new Array(end - start).fill().map((d, i) => i + start)

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

watch(min, async () => await loadContent(page.value ?? 1))
watch(filter, val => {
  const route = useRoute()
  const router = useRouter()
  if (!val) {
    if (!route?.query['filter']) {
      return;
    }

    router.push({
      'path': '/parity',
      'query': {
        ...route.query,
        'filter': undefined
      }
    })
    return;
  }

  if (route?.query['filter'] === val) {
    return;
  }

  router.push({
    'path': '/parity',
    'query': {
      ...route.query,
      'filter': val
    }
  })
})

const clearCache = () => Object.keys(sessionStorage ?? {}).filter(k => /^parity/.test(k)).forEach(k => sessionStorage.removeItem(k))

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
</script>
