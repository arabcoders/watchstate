<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-history"></i></span>
          History
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

            <div class="control">
              <div class="select">
                <select v-model="perpage" :disabled="isLoading" @change="loadContent(1,false)">
                  <option value="" disabled>Per page</option>
                  <option v-for="i in [50,100,200,400,500]" :key="`perpage-${i}`" :value="i">
                    {{ i }}
                  </option>
                </select>
              </div>
            </div>

            <p class="control">
              <button class="button is-primary" @click="searchForm = !searchForm">
                <span class="icon">
                  <i class="fas fa-search"></i>
                </span>
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
              <button class="button is-info" @click="loadContent(page, true)">
                <span class="icon">
                  <i class="fas fa-sync"></i>
                </span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This page has the latest history entries. Sorted by the most recent event.</span>
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
                <option v-for="(item, index) in makePagination(page, last_page)" :key="index" :value="item.page"
                        :disabled="item.page === 0">
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

      <div class="column is-12" v-if="searchForm">
        <form @submit.prevent="loadContent(1)">
          <div class="field">
            <div class="field-body">
              <div class="field is-grouped-tablet">
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="searchField" class="is-capitalized" :disabled="isLoading">
                      <option value="">Select Field</option>
                      <option v-for="field in searchable" :key="'search-' + field.key" :value="field.key">
                        {{ field.display ?? field.key }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-folder-tree"></i>
                  </div>
                </div>

                <div class="control is-expanded has-icons-left">
                  <input class="input" type="search" placeholder="Search..." v-model="query"
                         :disabled="'' === searchField || isLoading">
                  <div class="icon is-left">
                    <i class="fas fa-search"></i>
                  </div>
                </div>

                <div class="control">
                  <button class="button is-primary" type="submit" :disabled="!query || '' === searchField || isLoading"
                          :class="{'is-loading':isLoading}">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-search"></i></span>
                      <span>Search</span>
                    </span>
                  </button>
                </div>

                <div class="control">
                  <button class="button is-warning" type="button" @click="clearSearch" :disabled="isLoading">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-cancel"></i></span>
                      <span>Reset</span>
                    </span>
                  </button>
                </div>

              </div>
            </div>
            <p class="help" v-html="getHelp(searchField)"></p>
          </div>
        </form>
      </div>

      <div class="column is-12" v-if="selected_ids.length > 0">
        <div class="field is-grouped is-justify-content-center">
          <div class="control">
            <button class="button is-danger" @click="massAction('delete')" :disabled="massActionInProgress">
              <span class="icon"><i class="fas fa-trash"></i></span>
              <span class="is-hidden-mobile">Delete '{{ selected_ids.length }}' item/s</span>
            </button>
          </div>
          <div class="control">
            <button class="button is-primary" @click="massAction('mark_played')" :disabled="massActionInProgress">
              <span class="icon"><i class="fas fa-eye"></i></span>
              <span class="is-hidden-mobile">Mark '{{ selected_ids.length }}' item/s as played</span>
            </button>
          </div>
          <div class="control">
            <button class="button is-warning" @click="massAction('mark_unplayed')" :disabled="massActionInProgress">
              <span class="icon"><i class="fas fa-eye-slash"></i></span>
              <span class="is-hidden-mobile">Mark '{{ selected_ids.length }}' item/s as unplayed</span>
            </button>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="items?.length <  1 || filteredRows(items).length < 1">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else class="has-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-triangle" :use-close="true" @close="clearSearch">
          <div class="icon-text">
            No items found.
            <span v-if="query">For <code><strong>{{ searchField }}</strong> : <strong>{{ query }}</strong></code></span>
            <span v-if="filter">For <code><strong>Filter</strong> : <strong>{{ filter }}</strong></code></span>
          </div>
          <code class="is-block mt-4" v-if="error">{{ error }}</code>
        </Message>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="items?.length>0">
          <template v-for="item in items" :key="item.id">
            <Lazy :unrender="true" :min-height="240" class="column is-6-tablet" v-if="filterItem(item)">
              <div class="card is-flex is-full-height is-flex-direction-column" :class="{ 'is-success': item.watched }">
                <header class="card-header">
                  <p class="card-header-title is-text-overflow pr-1">
                    <span class="icon is-unselectable">
                      <label class="checkbox">
                        <input type="checkbox" :value="item.id" v-model="selected_ids">
                      </label>&nbsp;
                    </span>
                    <NuxtLink :to="'/history/'+item.id" v-text="item?.full_title ?? makeName(item)"/>
                  </p>
                  <span class="card-header-icon" @click="item.showRawData = !item?.showRawData">
                    <span class="icon">
                      <i class="fas" :class="{'fa-tv': 'episode' === item.type, 'fa-film': 'movie' === item.type}"></i>
                    </span>
                  </span>
                </header>
                <div class="card-content is-flex-grow-1">
                  <div class="columns is-multiline is-mobile has-text-centered">
                    <div class="column is-12 has-text-left" v-if="item?.content_title">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_title, 'is-text-contents': item?.expand_title}"
                             @click="item.expand_title = !item?.expand_title">
                          <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                          <NuxtLink :to="makeSearchLink('subtitle',item.content_title)" v-text="item.content_title"/>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable" @click="copyText(item.content_title, false)">
                            <i class="fas fa-copy"></i></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12  has-text-left" v-if="item?.content_path">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path}"
                             @click="item.expand_path = !item?.expand_path">
                          <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                          <NuxtLink :to="makeSearchLink('path',item.content_path)" v-text="item.content_path"/>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable" @click="copyText(item.content_path, false)">
                            <i class="fas fa-copy"></i></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12 has-text-left" v-if="item?.progress">
                      <span class="icon"><i class="fas fa-bars-progress"></i></span>
                      <span>{{ formatDuration(item.progress) }}</span>
                    </div>
                  </div>
                </div>
                <div class="card-content p-0 m-0" v-if="item?.showRawData">
                <pre class="is-terminal" style="position: relative; max-height: 343px;"><code
                    v-text="JSON.stringify(item, null, 2)"/><button class="button m-4"
                                                                    @click="() => copyText(JSON.stringify(item, null, 2))"
                                                                    style="position: absolute; top:0; right:0;">
                    <span class="icon"><i class="fas fa-copy"/></span>
                  </button></pre>
                </div>
                <div class="card-footer has-text-centered">
                  <div class="card-footer-item">
                    <div class="is-text-overflow">
                      <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                      <span class="has-tooltip"
                            v-tooltip="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                        {{ moment.unix(item.updated_at).fromNow() }}
                      </span>
                    </div>
                  </div>
                  <div class="card-footer-item">
                    <div class="is-text-overflow">
                      <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
                      <NuxtLink :to="'/backend/'+item.via" v-text="item.via"/>
                      <span v-if="item?.metadata && Object.keys(item?.metadata).length > 1"
                            v-tooltip="`Also reported by: ${Object.keys(item.metadata).filter(i => i !== item.via).join(', ')}.`">
                        (<span class="has-tooltip">+{{ Object.keys(item.metadata).length - 1 }}</span>)
                      </span>
                    </div>
                  </div>
                  <div class="card-footer-item">
                    <div class="is-text-overflow">
                      <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
                      {{ item.event ?? '-' }}
                    </div>
                  </div>
                </div>
              </div>
            </Lazy>
          </template>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import Message from '~/components/Message.vue'
import {
  awaitElement,
  copyText,
  formatDuration,
  makeName,
  makePagination,
  makeSearchLink,
  notification,
  TOOLTIP_DATE_FORMAT
} from '~/utils/index.js'
import Lazy from '~/components/Lazy.vue'

const route = useRoute()
const router = useRouter()

useHead({title: 'History'})

const jsonFields = ref(['metadata', 'extra'])
const items = ref([])
const searchable = ref([{key: 'id'}, {key: 'via'}, {key: 'year'}, {key: 'type'}, {key: 'title'}, {key: 'season'}, {key: 'episode'}, {key: 'parent'}, {key: 'guid'}])
const error = ref('')

const page = ref(route.query.page ?? 1)
const perpage = ref(route.query.perpage ?? 50)
const total = ref(0)
const last_page = computed(() => Math.ceil(total.value / perpage.value))

const query = ref(route.query.q ?? '')
const searchField = ref(route.query.key ?? 'title')
const isLoading = ref(false)
const filter = ref(route.query.filter ?? '')
const showFilter = ref(!!filter.value)
const searchForm = ref(false)
const selectAll = ref(false)
const selected_ids = ref([])
const massActionInProgress = ref(false)

watch(selectAll, v => selected_ids.value = v ? filteredRows(items.value).map(i => i.id) : []);

const loadContent = async (pageNumber, fromPopState = false) => {
  pageNumber = parseInt(pageNumber)

  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1
  }

  let title = `History: Page #${pageNumber}`

  let search = new URLSearchParams()
  search.set('perpage', perpage.value)
  search.set('page', pageNumber)

  if (searchField.value && query.value) {
    search.set('q', query.value)
    search.set('key', searchField.value)
    title += `. (Search: ${query.value})`
  }

  if (filter.value) {
    title += `. (Filter: ${filter.value})`
  }

  useHead({title})

  let newUrl = window.location.pathname + '?' + search.toString()

  try {
    if (searchField.value && query.value) {
      search.delete('q')
      search.delete('key')
      if (jsonFields.value.includes(searchField.value)) {
        search.set(searchField.value, `1`)
        let [field, value] = splitQuery(query.value, '://')
        if (-1 === query.value.indexOf('://') || !value || !field) {
          notification('error', 'Error', `Invalid search format for '${searchField.value}'.`)
          return
        }
        search.set('key', field)
        search.set('value', value)
      } else {
        search.set(searchField.value, query.value)
      }
    }

    isLoading.value = true
    items.value = []

    const response = await request(`/history?${search.toString()}`)
    const json = await response.json()

    if (useRoute().name !== 'history') {
      await unloadPage()
      return
    }

    const currentUrl = window.location.pathname + '?' + (new URLSearchParams(window.location.search)).toString()

    if (!fromPopState && currentUrl !== newUrl) {
      let history_query = {
        perpage: perpage.value,
        page: pageNumber,
      }
      if (searchField.value && query.value) {
        history_query.q = query.value
        history_query.key = searchField.value
      }

      if (filter.value) {
        history_query.filter = filter.value
      }

      await router.push({path: '/history', title: title, query: history_query})
    }

    if ('paging' in json) {
      page.value = json.paging.current_page
      perpage.value = json.paging.perpage
      total.value = json.paging.total
    } else {
      page.value = 1
      total.value = 0
    }

    if (json.history) {
      json.history.forEach(item => {
        item['full_title'] = makeName(item)
        items.value.push(item)
      })
    }

    if (json.searchable) {
      searchable.value = json.searchable
    }

    if (json.error && 404 !== json.error.code) {
      error.value = json.error
    }

    isLoading.value = false

  } catch (e) {
  }
}

const clearSearch = () => {
  query.value = ''
  filter.value = ''
  searchForm.value = false
  showFilter.value = false
  loadContent(1)
}

const splitQuery = (str, delimiter) => {
  const index = str.indexOf(delimiter)
  return (-1 === index) ? [str] : [str.slice(0, index), str.slice(index + delimiter.length)]
}

const getHelp = (key) => {
  if (!key) {
    return ''
  }

  let data = searchable.value.filter(i => i.key === key)
  if (data.length === 0) {
    return ''
  }

  if (!data[0].description) {
    return '';
  }

  let text = `${data[0].description}`;

  if (data[0].type) {
    text += ` Expected value: <code>${typeof data[0].type === 'object' ? data[0].type.join(' or ') : data[0].type}</code>`
  }

  return `<span class="icon-text"><span class="icon"><i class="fas fa-info"></i></span><span>${text}</span></span>`
}

const toggleFilter = () => {
  showFilter.value = !showFilter.value
  if (!showFilter.value) {
    filter.value = ''
    return
  }

  awaitElement('#filter', (_, elm) => elm.focus())
}

const massAction = async (action) => {
  if (selected_ids.value.length === 0) {
    return
  }

  const title = {
    delete: 'Delete',
    mark_played: 'Mark as played',
    mark_unplayed: 'Mark as unplayed'
  }[action]

  if (!confirm(`Are you sure you want to '${title}' ${selected_ids.value.length} item/s?`)) {
    return
  }

  let urls = [];
  let opts = {};
  let callback = null;

  massActionInProgress.value = true

  if ('delete' === action) {
    opts = {method: 'DELETE'}
    urls = selected_ids.value.map(id => `/history/${id}`)
    callback = () => items.value = items.value.filter(i => !selected_ids.value.includes(i.id))
  }

  if ('mark_played' === action || 'mark_unplayed' === action) {
    opts = {method: 'mark_played' === action ? 'POST' : 'DELETE'}
    let ids = selected_ids.value.map(id => items.value.find(i => i.id === id)).filter(i => 'mark_played' === action ? !i.watched : i.watched).map(i => i.id)
    urls = ids.map(i => `/history/${i}/watch`)
    callback = () => items.value.forEach(i => {
      if (ids.includes(i.id)) {
        i.watched = 'mark_played' === action
      }
    })
  }

  try {
    notification('success', 'Action in progress', `Processing Mass ''${title}' request. Please wait...`)

    // -- check each request response after all requests are done
    const requests = await Promise.all(urls.map(url => request(url, opts)))

    const all_ok = requests.every(response => 200 === response.status)
    if (!all_ok) {
      notification('error', 'Error', `Some requests failed. Please check the console for more details.`)
    }

    if (all_ok && callback) {
      callback()
    }

    notification('success', 'Success', `Mass '${title}' request completed.`)
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    massActionInProgress.value = false
    selected_ids.value = []
    selectAll.value = false
  }
}


const stateCallBack = async e => {
  if (!e.state && !e.detail) {
    return
  }
  const state = e.detail ?? e.state


  const route = useRoute()
  page.value = route.query.page ?? 1
  perpage.page = route.query.perpage ?? 50
  filter.value = route.query.filter ?? ''
  if (filter.value) {
    showFilter.value = true
  }

  if ('clear' in state) {
    query.value = ''
    searchField.value = 'title'
  } else {
    query.value = route.query.q ?? ''
    searchField.value = route.query.key ?? 'title'
    if (query.value) {
      searchForm.value = true
    }
  }

  await loadContent(page.value, true)
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

watch(filter, val => {
  const route = useRoute()
  const router = useRouter()
  if (!val) {
    if (!route?.query['filter']) {
      return;
    }

    router.push({
      'path': '/history',
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
    'path': '/history',
    'query': {
      ...route.query,
      'filter': val
    }
  })
})

onMounted(async () => {
  if (query.value) {
    searchForm.value = true
  }
  window.addEventListener('popstate', stateCallBack)
  window.addEventListener('history_main_link_clicked', stateCallBack);
  await loadContent(page.value ?? 1)
})

const unloadPage = async () => {
  window.removeEventListener('history_main_link_clicked', stateCallBack)
  window.removeEventListener('popstate', stateCallBack)
}

onUnmounted(async () => await unloadPage())
</script>
