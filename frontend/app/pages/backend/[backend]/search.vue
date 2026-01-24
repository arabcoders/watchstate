<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <NuxtLink to="/backends">Backends</NuxtLink>
          -
          <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
          : Search
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click="searchContent(true)"
                :disabled="isLoading || !searchField || !query" :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This page search the remote backend data not the locally stored data.</span>
        </div>
      </div>

      <div class="column is-12">
        <form @submit.prevent="searchContent(false)">
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
                    :class="{ 'is-loading': isLoading }">
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

      <div class="column is-12" v-if="items?.length < 1 && hasSearched">
        <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark" icon="fas fa-spinner fa-spin"
          title="Loading" message="Loading data please wait..." />
        <Message v-else class="has-background-warning-80 has-text-dark" title="Warning"
          icon="fas fa-exclamation-triangle" :use-close="true" @close="clearSearch">
          <span v-if="error?.message" v-text="error.message"></span>
          <template v-else>
            <span>No items found.</span>
            <span v-if="query">
              Search query <code><strong>{{ searchField }}</strong> : <strong>{{ query }}</strong></code>
            </span>
          </template>
        </Message>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="items?.length > 0">
          <div class="column is-6-tablet" v-for="item in items" :key="item.id">
            <div class="card" :class="{ 'is-success': item.watched }">
              <header class="card-header">
                <p class="card-header-title is-text-overflow">
                  <NuxtLink :to="item.webUrl" target="_blank">{{ makeName(item) }}</NuxtLink>
                </p>
                <span class="card-header-icon" @click="item.showItem = !item.showItem">
                  <span class="icon">
                    <i class="fas"
                      :class="{ 'fa-folder': 'show' === item.type, 'fa-tv': 'episode' === item.type, 'fa-film': 'movie' === item.type }"></i>
                  </span>
                </span>
              </header>
              <div class="card-content">
                <div class="columns is-multiline is-mobile has-text-centered">
                  <div class="column is-12 has-text-left" v-if="item?.title">
                    <div class="is-text-overflow is-clickable"
                      @click="(e: Event) => (e.target as HTMLElement)?.classList?.toggle('is-text-overflow')">
                      <div class="is-text-overflow">
                        <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                        <NuxtLink :to="makeSearchLink('title', item.title)">{{ item.title }}</NuxtLink>
                      </div>
                    </div>
                  </div>
                  <div class="column is-12 is-clickable has-text-left" v-if="item?.content_path"
                    @click="(e: Event) => (e.target as HTMLElement)?.firstElementChild?.classList?.toggle('is-text-overflow')">
                    <div class="is-text-overflow">
                      <span class="icon"><i class="fas fa-file"></i></span>
                      <NuxtLink :to="makeSearchLink('path', item.content_path)">{{ item.content_path }}</NuxtLink>
                    </div>
                  </div>
                </div>
              </div>
              <div class="card-content p-0 m-0" v-if="item?.showItem">
                <div class="mt-2" style="position: relative; max-height: 343px; overflow-y: auto;">
                  <code class="is-terminal is-block is-pre-wrap" v-text="JSON.stringify(item, null, 2)" />
                  <button class="button m-4" v-tooltip="'Copy text'" style="position: absolute; top:0; right:0;"
                    @click="() => copyText(JSON.stringify(item, null, 2))">
                    <span class="icon"><i class="fas fa-copy" /></span>
                  </button>
                </div>
              </div>
              <div class="card-footer">
                <div class="card-footer-item">
                  <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                  <span class="has-tooltip" v-tooltip="moment.unix(getItemTimestamp(item)).format(TOOLTIP_DATE_FORMAT)">
                    {{ moment.unix(getItemTimestamp(item)).fromNow() }}
                  </span>
                </div>
                <div class="card-footer-item">
                  <span class="icon">
                    <i class="fas"
                      :class="{ 'fa-folder': 'show' === item.type, 'fa-tv': 'episode' === item.type, 'fa-film': 'movie' === item.type }"></i>
                    &nbsp;
                  </span>
                  <span class="is-capitalized">{{ item.type }}</span>
                </div>
                <div class="card-footer-item">
                  <span class="icon"><i class="fas fa-database"></i>&nbsp;</span>
                  <span>
                    <NuxtLink
                      :to="makeSearchLink(`metadata`, `${item.via}.show://${ag(item, `metadata.${item.via}.id`)}`)"
                      v-if="'show' === item.type">
                      View linked items
                    </NuxtLink>
                    <NuxtLink :to="`/history/${item.id}`" v-else-if="item.id">
                      View local item
                    </NuxtLink>
                    <span v-else class="has-text-danger">
                      Not imported
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" title="Tips" icon="fas fa-info-circle"
          :toggle="show_page_tips" @toggle="show_page_tips = !show_page_tips" :use-toggle="true">
          <ul>
            <li>
              items with <code>Not imported</code> text are items not yet imported to local database.
            </li>
            <li>
              The items shown here are from the remote backend data queried directly.
            </li>
            <li>Clicking directly on the <code>item title</code> will take you to the page associated with that link in
              the backend.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter, useHead } from '#app'
import { useStorage } from '@vueuse/core'
import moment from 'moment'
import { request, makeName, makeSearchLink, notification, TOOLTIP_DATE_FORMAT, ag, copyText, parse_api_response } from '~/utils'
import Message from '~/components/Message.vue'
import type { SearchItem } from '~/types'

type SearchItemWithUI = SearchItem & {
  /** UI state: Whether to show full item details */
  showItem?: boolean
}

const route = useRoute()
const router = useRouter()

const items = ref<Array<SearchItemWithUI>>([])
const limits = ref<Array<number>>([25, 50, 100, 250, 500])
const limit = ref<number>(parseInt(route.query.limit as string ?? '25'))
const searchable = ref<Array<string>>(['id', 'title'])
const backend = route.params.backend as string
const query = ref<string>(route.query.q as string ?? '')
const searchField = ref<string>(route.query.key as string ?? 'title')
const isLoading = ref<boolean>(false)
const hasSearched = ref<boolean>(false)
const error = ref<{ message?: string, code?: number }>({})
const show_page_tips = useStorage('show_page_tips', true)

useHead({ title: `Backends: ${backend} - Search` })

// Helper function to get the timestamp for an item
const getItemTimestamp = (item: SearchItemWithUI): number => item.updated_at ?? item.updated ?? 0

const searchContent = async (fromPopState: boolean = false): Promise<void> => {
  const search = new URLSearchParams()

  if (!query.value || '' === searchField.value) {
    notification('error', 'Error', 'Search field and query are required.')
    return
  }

  hasSearched.value = true
  isLoading.value = true
  items.value = []

  search.set('limit', limit.value.toString())
  search.set('id' === searchField.value ? 'id' : 'q', query.value)

  const title = `Backends: ${backend} - (Search - ${searchField.value}: ${query.value})`
  useHead({ title })

  try {
    const response = await request(`/backend/${backend}/search?${search.toString()}`)
    const data = await parse_api_response<Array<SearchItemWithUI>>(response)
    const currentUrl = window.location.pathname + '?' + (new URLSearchParams(window.location.search)).toString()
    const newUrl = window.location.pathname + '?' + search.toString()

    if (false === fromPopState && currentUrl !== newUrl) {
      await router.push({
        path: `/backend/${backend}/search`,
        query: { limit: limit.value.toString(), key: searchField.value, q: query.value }
      })
    }

    if ('error' in data) {
      error.value = { message: data.error.message, code: data.error.code }
      return
    }

    items.value = data
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred'
    notification('error', 'Error', `Request error. ${errorMessage}`)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  if (query.value && searchField.value) {
    searchContent(false)
  }
  window.addEventListener('popstate', stateCallBack)
})

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack))

const clearSearch = async (): Promise<void> => {
  query.value = ''
  items.value = []
  hasSearched.value = false
  error.value = {}
  const title = `Backends: ${backend} - Search`
  useHead({ title })
  await router.push({ path: `/backend/${backend}/search`, query: {} })
}

const stateCallBack = async (): Promise<void> => {
  const route = useRoute()

  if (route.query.key) {
    searchField.value = route.query.key as string
  }

  if (route.query.limit) {
    limit.value = parseInt(route.query.limit as string)
  }

  if (route.query.q) {
    query.value = route.query.q as string
    await searchContent(true)
  }
}

</script>
