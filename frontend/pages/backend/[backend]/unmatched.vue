<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink to="/backends">Backends</NuxtLink>
        -
        <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
        : Unmatched
      </span>

      <div class="is-pulled-right" v-if="hasLooked">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-info" @click.prevent="loadContent" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>

      <div class="subtitle is-hidden-mobile">
        In this page you will find items <code>WatchState</code> knows that are un-matched in your backend.
      </div>
    </div>

    <template v-if="false === hasLooked">
      <div class="column is-12">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-justify-center">Request Analyze</p>
          </header>
          <div class="card-content">
            <div class="content">
              <ul>
                <li>
                  Check the items will take time, you will see the spinner while <code>WatchState</code> is analyzing
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
    </template>
    <template v-else>
      <div class="column is-12" v-if="isLoading && items.length < 1">
        <Message message_class="is-info" title="Analyzing">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-spinner fa-spin"></i></span>
            <span>Analyzing the backend content. Please wait. It will take a while...</span>
          </span>
        </Message>
      </div>
      <div class="column is-12" v-else-if="hasLooked && items.length < 1">
        <Message message_class="has-background-success-90 has-text-dark" title="Success!">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span>There are no unmatched content in the libraries we looked at.</span>
          </span>
        </Message>
      </div>

      <template v-else>
        <div class="column is-12">
          <h1 class="title is-4">
            <span class="icon-text">
              <span class="icon has-text-danger"><i class="fas fa-exclamation-triangle"></i></span>
              <span>Unmatched Content</span>
            </span>
          </h1>
        </div>

        <div class="column is-6" v-for="item in items">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-text-overflow">
                <NuxtLink target="_blank" :to="item.webUrl ?? item.url">{{ item.title }}</NuxtLink>
              </p>
              <div class="card-header-icon" @click="item.showItem = !item.showItem">
                <span class="icon has-tooltip" v-tooltip="'Toggle raw data'">
                  <i class="fas fa-film" :class="{'fa-film': 'Movie' === item.type,'fa-tv': 'Movie' !== item.type}"></i>
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="columns is-mobile is-multiline">
                <div class="column is-6">
                  <strong>Library:</strong> {{ item.library }}
                </div>
                <div class="column is-6 has-text-right">
                  <strong>Type:</strong> {{ item.type }}
                </div>
                <div class="column is-6" v-if="0 !== item.year && item.year">
                  <strong>Year:</strong> {{ item.year }}
                </div>
              </div>
            </div>
            <div class="card-content p-0 m-0" v-if="item?.showItem">
              <pre><code>{{ JSON.stringify(item, null, 2) }}</code></pre>
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
      </template>
    </template>
  </div>
</template>

<script setup>
import {notification} from "~/utils/index.js";

const backend = useRoute().params.backend
const items = ref([])
const isLoading = ref(false)
const hasLooked = ref(false)

const loadContent = async () => {
  hasLooked.value = true
  isLoading.value = true
  items.value = []

  let response, json;

  try {
    response = await request(`/backend/${backend}/unmatched`)
  } catch (e) {
    isLoading.value = false
    return notification('error', 'Error', e.message)
  }

  try {
    json = await response.json()
  } catch (e) {
    json = {
      error: {
        code: response.status,
        message: response.statusText
      }
    }
  }

  isLoading.value = false

  if (!response.ok) {
    notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    return
  }

  items.value = json
}

const fixTitle = (title) => title.replace(/([\[(]).*?([\])])/g, '').replace(/-\w+$/, '').trim()
</script>