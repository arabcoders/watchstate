<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <NuxtLink to="/backends" v-text="'Backends'"/>
          -
          <NuxtLink :to="'/backend/' + backend" v-text="backend"/>
          : Misidentified
        </span>
        <div class="is-pulled-right" v-if="hasLooked">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click="loadContent(false)" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="subtitle is-hidden-mobile">
          This page will show items that <code>WatchState</code> thinks are possibly mis-identified in your backend.
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
            <button class="button is-fullwidth is-primary" @click="loadContent" :disabled="isLoading">
              <span class="icon"><i class="fas fa-check"></i></span>
              <span>Initiate The process</span>
            </button>
          </div>
        </div>
      </div>
      <div class="column is-12" v-if="items.length < 1">
        <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark"
                 title="Analyzing" icon="fas fa-spinner fa-spin"
                 message="Analyzing the backend content. Please wait. It will take a while..."/>
        <Message v-else-if="!isLoading && hasLooked" message_class="has-background-success-90 has-text-dark"
                 title="Success!" icon="fas fa-check"
                 message="WatchState did not find any possible mismatched items in the libraries we looked at."/>
      </div>

      <template v-if="items.length > 1">
        <div class="column is-12">
          <Message class="has-background-warning-80 has-text-dark" title="Warning" icon="fas fa-exclamation-triangle"
                   message="WatchState found some items that might be mis-identified in your backend. Please review the results."/>
        </div>

        <div class="column is-6" v-for="item in items">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-text-overflow">
                <NuxtLink target="_blank" :to="item.webUrl" v-if="item.webUrl" v-text="item.title"/>
                <span v-else>{{ item.title }}</span>
              </p>
              <div class="card-header-icon" @click="item.showItem = !item.showItem">
                <span class="icon has-tooltip">
                  <i class="fas fa-film" :class="{'fa-film': 'Movie' === item.type,'fa-tv': 'Movie' !== item.type}"></i>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="columns is-mobile is-multiline">
                <div class="column is-6">
                  <strong class="is-unselectable">Library:</strong> {{ item.library }}
                </div>
                <div class="column is-6 has-text-right">
                  <strong class="is-unselectable">Type:</strong> {{ item.type }}
                </div>
                <div class="column is-6">
                  <strong class="is-unselectable">Year:</strong> {{ item.year ?? '???' }}
                </div>
                <div class="column is-6 has-text-right">
                  <strong class="is-unselectable">Percent:</strong> <span :class="percentColor(item.percent)">
                  {{ item.percent.toFixed(2) }}%
                </span>
                </div>
                <div class="column is-12" v-if="item.path"
                     @click="(e) => e.target.firstChild?.classList?.toggle('is-text-overflow')">
                  <div class="is-text-overflow">
                    <strong class="is-unselectable">Path:&nbsp;</strong>
                    <NuxtLink :to="makeSearchLink('path',item.path)" v-text="item.path"/>
                  </div>
                </div>
              </div>
            </div>
            <div class="card-content p-0 m-0" v-if="item?.showItem">
              <pre><code>{{ JSON.stringify(item, null, 2) }}</code></pre>
            </div>
          </div>
        </div>
      </template>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              This service expects standard plex naming conventions
              <NuxtLink target="_blank" to="https://support.plex.tv/articles/naming-and-organizing-your-tv-show-files/"
                        v-text="'for series'"/>
              , and
              <NuxtLink target="_blank"
                        to="https://support.plex.tv/articles/naming-and-organizing-your-movie-media-files/"
                        v-text="'for movies'"/>
              . So if you libraries doesn't follow the same conventions, you will see a lot of items being reported as
              misidentified.
            </li>
            <li>
              If you see a lot of misidentified items, you might want to check the that the source directory matches the
              item.
            </li>
            <li>
              Clicking on the icon next to the title will show you the raw data that was used to generate the report.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import {makeSearchLink, notification} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message'
import {useSessionCache} from '~/utils/cache'

const backend = useRoute().params.backend
const items = ref([])
const isLoading = ref(false)
const hasLooked = ref(false)
const show_page_tips = useStorage('show_page_tips', true)
const cache = useSessionCache()
const cacheKey = `backend-${backend}-mismatched`

useHead({title: `Backends: ${backend} - Misidentified items`})
const loadContent = async (useCache = true) => {
  hasLooked.value = true
  isLoading.value = true
  items.value = []

  let response, json;


  try {
    if (useCache && cache.has(cacheKey)) {
      items.value = cache.get(cacheKey)
    } else {
      response = await request(`/backend/${backend}/mismatched`)
      json = await response.json()
      cache.set(cacheKey, json)

      if (!response.ok) {
        notification('error', 'Error', `${json.error.code ?? response.status}: ${json.error.message ?? response.statusText}`)
        return
      }
      items.value = json
    }
  } catch (e) {
    hasLooked.value = false
    return notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

const percentColor = (percent) => {
  percent = parseInt(percent)
  if (percent > 90) {
    return 'has-text-success'
  } else if (percent > 50 && 90 < percent) {
    return 'has-text-warning'
  } else {
    return 'has-text-danger'
  }
}
</script>
