<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4 is-unselectable">
        <NuxtLink to="/backends">Backends</NuxtLink>
        -
        <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
        : Search
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-info" @click.prevent="searchContent">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile is-unselectable">
        <span class="subtitle">This page search the remote backend data not the locally stored data.</span>
      </div>
    </div>

    <div class="column is-12">
      <form @submit.prevent="searchContent">
        <div class="field">
          <div class="field-body">
            <div class="field is-grouped-tablet">
              <div class="control has-icons-left">
                <div class="select is-fullwidth">
                  <select v-model="searchField" class="is-capitalized" :disabled="isLoading">
                    <option value="" disabled>Select Field</option>
                    <option v-for="field in searchable" :key="'search-' + field" :value="field">
                      {{ field }}
                    </option>
                  </select>
                </div>
                <div class="icon is-left">
                  <i class="fas fa-folder-tree"></i>
                </div>
              </div>
              <div class="control has-icons-left">
                <div class="select is-fullwidth">
                  <select v-model="limit" :disabled="isLoading">
                    <option value="" disabled>Select Limit</option>
                    <option v-for="limiter in limits" :key="'search-' + limiter" :value="limiter">
                      {{ limiter }}
                    </option>
                  </select>
                </div>
                <div class="icon is-left">
                  <i class="fas fa-sitemap"></i>
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
                        :class="{'is-loading':isLoading}" @click="updateUrl">
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
        </div>
      </form>
    </div>

    <div class="column is-12" v-if="items?.length<1 && hasSearched">
      <Message message_class="is-info" v-if="true === isLoading">
        <span class="icon-text">
          <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
          <span>Loading data please wait...</span>
        </span>
      </Message>
      <Message v-else class="has-background-warning-80 has-text-dark">
        <button v-if="query" class="delete" @click="clearSearch"></button>

        <div class="icon-text">
          <span class="icon"><i class="fas fa-info"></i></span>
          <span>No items found.</span>
          <span v-if="query">For <code><strong>{{ searchField }}</strong> : <strong>{{ query }}</strong></code></span>
        </div>
        <template v-if="error">
          <div class="content mt-4">
            <h5 class="has-text-dark">API Response ({{ error?.code ?? 0 }})</h5>
            <code class="is-pre-wrap is-block mt-4">
              {{ error?.message ?? error }}
            </code>
          </div>
        </template>
      </Message>
    </div>

    <div class="column is-12">
      <div class="columns is-multiline" v-if="items?.length>0">
        <div class="column is-6-tablet" v-for="item in items" :key="item.id">
          <div class="card" :class="{ 'is-success': item.watched }">
            <header class="card-header">
              <p class="card-header-title is-text-overflow">
                <NuxtLink :to="item.url" v-text="item.full_title ?? item.title" target="_blank"/>
              </p>
              <span class="card-header-icon">
                <span class="icon">
                  <i class="fas"
                     :class="{'fa-folder': 'show' === item.type, 'fa-tv': 'episode' === item.type, 'fa-film': 'movie' === item.type}"></i>
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
                  <span class="icon"><i class="fas fa-file"></i></span>
                  <span class="is-hidden-mobile">Path:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('path',item.path)" v-text="item.path"/>
                </div>
              </div>
            </div>
            <div class="card-footer">
              <div class="card-footer-item">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                  {{ moment.unix(item.updated).fromNow() }}
                </span>
              </div>
              <div class="card-footer-item">
                <span class="icon-text">
                  <span class="icon">
                    <i class="fas"
                       :class="{'fa-folder': 'show' === item.type, 'fa-tv': 'episode' === item.type, 'fa-film': 'movie' === item.type}"></i>
                  </span>
                  <span class="is-capitalized">{{ item.type }}</span>
                </span>
              </div>
              <div class="card-footer-item">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-database"></i></span>
                  <span v-if="'show' === item.type" class="is-unselectable">Not applicable</span>
                  <span v-else>
                    <NuxtLink :to="`/history/${item.id}`" v-if="item.id">
                      Referenced locally
                    </NuxtLink>
                    <span v-else class="has-text-danger">
                      Not referenced locally
                    </span>
                  </span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="column is-12">
      <Message message_class="has-background-info-90 has-text-dark">
        <div class="is-pulled-right">
          <NuxtLink @click="show_page_tips=false" v-if="show_page_tips">
            <span class="icon"><i class="fas fa-arrow-up"></i></span>
            <span>Close</span>
          </NuxtLink>
          <NuxtLink @click="show_page_tips=true" v-else>
            <span class="icon"><i class="fas fa-arrow-down"></i></span>
            <span>Open</span>
          </NuxtLink>
        </div>
        <h5 class="title is-5 is-unselectable">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <span>Tips</span>
          </span>
        </h5>
        <div class="content" v-if="show_page_tips">
          <ul>
            <li>
              Items with <code>Referenced locally</code> link are items we were able to find local match for. While
              items with <code>Not referenced locally</code> are items we were not able to link locally.
            </li>
            <li>
              The items shown here are from the remote backend data queried directly.
            </li>
            <li>Clicking directly on the <code>item title</code> will take you to the page associated with that link in
              the backend. While clicking <code>Referenced locally</code> will take you to the local item page.
            </li>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import moment from 'moment'
import {makeSearchLink, notification} from '~/utils/index.js'
import Message from "~/components/Message.vue";
import {useStorage} from "@vueuse/core";

const route = useRoute()

const items = ref([])
const limits = ref([25, 50, 100, 250, 500])
const limit = ref(parseInt(route.query.limit ?? 25))
const searchable = ref(['id', 'title'])
const backend = route.params.backend
const query = ref(route.query.q ?? '')
const searchField = ref(route.query.key ?? 'title')
const isLoading = ref(false)
const hasSearched = ref(false)
const error = ref({});
const show_page_tips = useStorage('show_page_tips', true)

useHead({title: `Backends: ${backend} - Search`})

const searchContent = async () => {
  let search = new URLSearchParams()

  if (!query.value || '' === searchField.value) {
    notification('error', 'Error', 'Search field and query are required.')
    return
  }

  hasSearched.value = true
  isLoading.value = true
  items.value = []

  search.set('limit', limit.value)

  if ('id' === searchField.value) {
    search.set('id', query.value)
  } else {
    search.set('q', query.value)
  }

  useHead({title: `Backends: ${backend} - (Search - ${searchField.value}: ${query.value})`})

  try {
    const response = await request(`/backend/${backend}/search?${search.toString()}`)
    const json = await response.json()

    if (200 !== response.status) {
      error.value = json.error
      return
    }

    items.value = json
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    isLoading.value = false
  }
}

const updateUrl = () => useRouter().push({query: {key: searchField.value, q: query.value, limit: limit.value}})

onMounted(() => {
  if (query.value && searchField.value) {
    searchContent()
  }
})

const clearSearch = () => {
  query.value = ''
  items.value = []
  hasSearched.value = false
  error.value = {}
  useHead({title: `Backends: ${backend} - Search`})
}
</script>
