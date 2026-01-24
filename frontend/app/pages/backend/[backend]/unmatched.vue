<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <NuxtLink to="/backends">Backends</NuxtLink>
          -
          <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
          : Unmatched
        </span>
        <div class="is-pulled-right" v-if="hasLooked">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click.prevent="loadContent(false)" :disabled="isLoading"
                :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="subtitle is-hidden-mobile">
          In this page you will find items <code>WatchState</code> knows that are un-matched in your backend.
        </div>
      </div>

      <div class="column is-12" v-if="false === hasLooked">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-justify-center">Request Analyze</p>
          </header>
          <div class="card-content">
            <div class="content">
              <ul>
                <li>
                  Checking the items will take time, you will see the spinner while <code>WatchState</code> is analyzing
                  the entire backend libraries content. Do not reload the page.
                </li>
              </ul>
            </div>
          </div>
          <div class="control">
            <button class="button is-fullwidth is-primary" @click="() => loadContent()" :disabled="isLoading">
              <span class="icon"><i class="fas fa-check"></i></span>
              <span>Initiate The process</span>
            </button>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="1 > items.length">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Analyzing"
          icon="fas fa-spinner fa-spin" message="Analyzing the backend content. Please wait. It will take a while..." />
        <Message v-if="!isLoading && hasLooked" message_class="has-background-success-90 has-text-dark" title="Success!"
          icon="fas fa-check" message="WatchState did not find any unmatched content in the libraries we looked at." />
      </div>

      <div class="column is-12" v-if="1 < items.length">
        <h1 class="title is-4">
          <span class="icon-text">
            <span class="icon has-text-danger"><i class="fas fa-exclamation-triangle"></i></span>
            <span>Unmatched Content</span>
          </span>
        </h1>
      </div>

      <div class="column is-6" v-for="item in items" :key="item.id ?? item.title">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-text-overflow">
              <NuxtLink target="_blank" :to="item.webUrl ?? item.url">{{ item.title }}</NuxtLink>
            </p>
            <div class="card-header-icon" @click="item.showItem = !item.showItem">
              <span class="icon has-tooltip" v-tooltip="'Toggle raw data'">
                <i class="fas fa-film" :class="{ 'fa-film': 'Movie' === item.type, 'fa-tv': 'Movie' !== item.type }"></i>
              </span>
            </div>
          </header>
          <div class="card-content">
            <div class="columns is-mobile is-multiline">
              <div class="column is-6">
                <strong class="is-unselectable">Library:</strong> {{ item.library ?? 'Unknown' }}
              </div>
              <div class="column is-6 has-text-right">
                <strong class="is-unselectable">Type:</strong> <span class="is-capitalized">{{
                  item.type ?? 'Unknown'
                  }}</span>
              </div>
              <div class="column is-6" v-if="0 !== item.year && item.year">
                <strong class="is-unselectable">Year:</strong> {{ item.year ?? 'Unknown' }}
              </div>
              <div class="column is-12 is-clickable has-text-left" v-if="item?.path"
                @click="(e: Event) => (e.target as HTMLElement)?.firstElementChild?.classList?.toggle('is-text-overflow')">
                <div class="is-text-overflow">
                  <strong class="is-unselectable">Path:&nbsp;</strong>
                  <NuxtLink :to="makeSearchLink('path', item.path)">{{ item.path }}</NuxtLink>
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
              <NuxtLink target="_blank" :to="`https://www.imdb.com/find/?q=${fixTitle(item.title)}`">
                <span class="icon"><i class="fas fa-search"></i></span>
                <span>IMDb</span>
              </NuxtLink>
            </div>
            <div class="card-footer-item">
              <NuxtLink target="_blank" :to="`https://www.themoviedb.org/search?query=${fixTitle(item.title)}`">
                <span class="icon"><i class="fas fa-search"></i></span>
                <span>TMDB</span>
              </NuxtLink>
            </div>
            <div class="card-footer-item">
              <NuxtLink target="_blank" :to="`https://thetvdb.com/search?query=${fixTitle(item.title)}`">
                <span class="icon"><i class="fas fa-search"></i></span>
                <span>TVDB</span>
              </NuxtLink>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useHead } from '#app'
import { makeSearchLink, notification, copyText, request, parse_api_response } from '~/utils'
import { useSessionCache } from '~/utils/cache'
import type { UnmatchedItem } from '~/types'

type UnmatchedItemWithUI = UnmatchedItem & {
  /** UI: Whether to show raw item data */
  showItem?: boolean
}

const backend = useRoute().params.backend as string
const items = ref<Array<UnmatchedItemWithUI>>([])
const isLoading = ref<boolean>(false)
const hasLooked = ref<boolean>(false)
const cache = useSessionCache()
const cacheKey = `backend-${backend}-unmatched`

useHead({ title: `Backends: ${backend} - Unmatched items.` })

const loadContent = async (useCache: boolean = true): Promise<void> => {
  hasLooked.value = true
  isLoading.value = true
  items.value = []

  try {
    if (useCache && cache.has(cacheKey)) {
      const cachedData = cache.get(cacheKey) as Array<UnmatchedItemWithUI>
      if (cachedData) {
        items.value = cachedData
      }
    } else {
      const response = await request(`/backend/${backend}/unmatched`)
      const data = await parse_api_response<Array<UnmatchedItemWithUI>>(response)

      if ('error' in data) {
        notification('error', 'Error', `${data.error.code}: ${data.error.message}`)
        return
      }

      cache.set(cacheKey, data)
      items.value = data
    }
  } catch (e) {
    hasLooked.value = false
    return notification('error', 'Error', e instanceof Error ? e.message : 'Unknown error occurred')
  } finally {
    isLoading.value = false
  }
}

const fixTitle = (title: string): string => title.replace(/[[(].*?[\])]/g, '').replace(/-\w+$/, '').trim()
</script>
