<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink to="/backends">Backends</NuxtLink>
        -
        <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
        : Mismatched
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
        This page will show items that <code>WatchState</code> thinks are possible mismatches.
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
        <Message message_class="has-background-success-90" title="Success!">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span>WatchState did not find possible mismatched items in the libraries we looked at.</span>
          </span>
        </Message>
      </div>

      <template v-else>
        <div class="column is-12">
          <h1 class="title is-4">
            <span class="icon-text">
              <span class="icon has-text-warning"><i class="fas fa-exclamation-triangle"></i></span>
              <span>Possible Mismatches</span>
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
                <span class="icon has-tooltip">
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
                <div class="column is-6">
                  <strong>Year:</strong> {{ item.year ?? '???' }}
                </div>
                <div class="column is-6 has-text-right">
                  <strong>Percent:</strong> <span :class="percentColor(item.percent)">
                  {{ item.percent.toFixed(2) }}%
                </span>
                </div>
                <div class="column is-12" v-if="item.path">
                  <strong>Path:</strong> {{ item.path }}
                </div>
              </div>
            </div>
            <div class="card-content p-0 m-0" v-if="item?.showItem">
              <pre><code>{{ JSON.stringify(item, null, 2) }}</code></pre>
            </div>
          </div>
        </div>
      </template>
    </template>


    <div class="column is-12" v-if="show_page_tips">
      <Message title="Tips" message_class="has-background-info-90 has-text-dark">
        <button class="delete" @click="show_page_tips=false"></button>
        <div class="content">
          <ul>
            <li>
              This service expects standard plex naming conventions. So if you libraries doesn't follow the same
              conventions, you will see a lot of items being reported as mismatches.
            </li>
            <li>
              If you see a lot of mismatches, you might want to check the that the source directory matches the item.
            </li>
            <li>
              Clicking on the icon next to the title will show you the raw data that was used to generate the report.
            </li>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import {notification} from "~/utils/index.js";
import {useStorage} from "@vueuse/core";

const backend = useRoute().params.backend
const items = ref([])
const isLoading = ref(false)
const hasLooked = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  hasLooked.value = true
  isLoading.value = true
  items.value = []

  let response, json;

  try {
    response = await request(`/backend/${backend}/mismatched`)
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
