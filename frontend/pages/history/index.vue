<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">History</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" @click="searchForm = !searchForm">
              <span class="icon">
                <i class="fas fa-search"></i>
              </span>
            </button>
          </p>
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
              <option v-for="(item, index) in makePagination()" :key="index" :value="item.page">
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

    <div class="column is-12">
      <div class="columns is-multiline" v-if="items?.length>0">
        <div class="column is-6-tablet" v-for="item in items" :key="item.id">
          <div class="card" :class="{ 'is-success': item.watched }">
            <header class="card-header">
              <p class="card-header-title is-text-overflow pr-1">
                <span class="icon" v-if="!item.progress">
                  <i class="fas" :class="{'fa-eye-slash': !item.watched, 'fa-eye': item.watched}"></i>
                </span>
                <NuxtLink :to="'/history/'+item.id" v-text="item.full_title ?? item.title"/>
              </p>
              <span class="card-header-icon">
                <span class="icon">
                  <i class="fas" :class="{'fa-tv': 'episode' === item.type, 'fa-film': 'movie' === item.type}"></i>
                </span>
              </span>
            </header>
            <div class="card-content">
              <div class="columns is-multiline is-mobile has-text-centered">
                <div class="column is-12 has-text-left" v-if="item?.title">
                  <div class="is-text-overflow is-clickable"
                       @click="(e) => e.target.classList.toggle('is-text-overflow')">
                    <span class="icon"><i class="fas fa-heading"></i></span>
                    <span class="is-hidden-mobile">Title:&nbsp;</span>
                    {{ item.title }}
                  </div>
                </div>
                <div class="column is-12 is-clickable has-text-left" v-if="item?.path"
                     @click="(e) => e.target.firstChild?.classList?.toggle('is-text-overflow')">
                  <div class="is-text-overflow">
                    <span class="icon"><i class="fas fa-file"></i></span>
                    <span class="is-hidden-mobile">Path:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('path',item.path)" v-text="item.path"/>
                  </div>
                </div>
                <div class="column is-4-tablet is-6-mobile has-text-left-mobile">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                    {{ moment(item.updated).fromNow() }}
                  </span>
                </div>
                <div class="column is-4-tablet is-6-mobile has-text-right-mobile">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-server"></i></span>
                    <span>
                      <NuxtLink :to="'/backend/'+item.via" v-text="item.via"/>
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
              <div class="card-footer-item">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-bars-progress"></i></span>
                  <span>{{ formatDuration(item.progress) }}</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="column is-12" v-else>
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else class="has-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-triangle" :use-close="true" @close="clearSearch">
          <div class="icon-text">
            No items found.
            <span v-if="query">For <code><strong>{{ searchField }}</strong> : <strong>{{ query }}</strong></code></span>
          </div>
          <code class="is-block mt-4" v-if="error">{{ error }}</code>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import Message from '~/components/Message.vue'
import {formatDuration, makeSearchLink, notification} from '~/utils/index.js'

const route = useRoute()

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
const searchForm = ref(false)

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

    const response = await request(`/history/?${search.toString()}`)
    const json = await response.json()
    const currentUrl = window.location.pathname + '?' + (new URLSearchParams(window.location.search)).toString()

    if (!fromPopState && currentUrl !== newUrl) {
      console.log(currentUrl, newUrl)
      window.history.pushState({
        page: pageNumber,
        query: query.value,
        key: searchField.value
      }, '', newUrl)
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
      items.value = json.history
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

const makePagination = () => {
  let pagination = []
  let pages = Math.ceil(total.value / perpage.value)

  if (pages < 2) {
    return pagination
  }

  for (let i = 1; i <= pages; i++) {
    pagination.push({
      page: i,
      text: `Page #${i}`,
      selected: parseInt(page.value) === i,
    })
  }

  return pagination
}

const clearSearch = () => {
  query.value = ''
  searchForm.value = false
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

onMounted(async () => {
  if (query.value) {
    searchForm.value = true
  }
  await loadContent(page.value ?? 1)
})
</script>
