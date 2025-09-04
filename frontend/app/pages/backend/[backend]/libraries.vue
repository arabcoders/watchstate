<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
          <NuxtLink to="/backends" v-text="'Backends'"/>
          -
          <NuxtLink :to="'/backend/' + backend" v-text="backend"/>
          : Libraries
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>

        <div class="subtitle is-hidden-mobile">
          This page will show all the libraries that are available in the backend.
        </div>
      </div>

      <div class="column is-12" v-if="items.length < 1">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
                 message="Loading libraries list. Please wait..." v-if="isLoading"/>
        <Message v-else message_class="has-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-circle"
                 message="WatchState was unable to get any libraries from the backend."/>
      </div>

      <div class="column is-6" v-for="item in items" :key="`library-${item.id}`">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-text-overflow">
              <NuxtLink target="_blank" :to="item.webUrl" v-text="item.title" v-if="item?.webUrl"/>
              <span v-else v-text="item.title"/>
            </p>
            <div class="card-header-icon">
              <span class="icon">
                <i class="fas fa-film" :class="{'fa-film': 'Movie' === item.type, 'fa-tv': 'Movie' !== item.type}"></i>
              </span>
            </div>
          </header>
          <div class="card-content">
            <div class="columns is-mobile is-multiline">
              <div class="column is-6">
                <strong>Type:</strong> {{ item.type }}
              </div>
              <div class="column is-6 has-text-right">
                <strong>Supported:</strong> {{ item.supported ? 'Yes' : 'No' }}
              </div>
              <div class="column is-6" v-if="item?.agent">
                <div class="is-text-overflow">
                  <strong>Agent:</strong> {{ item.agent }}
                </div>
              </div>
              <div class="column is-6 has-text-right" v-if="item?.scanner">
                <div class="is-text-overflow">
                  <strong>Scanner:</strong> {{ item.scanner }}
                </div>
              </div>
            </div>
          </div>
          <div class="card-footer">
            <div class="card-footer-item is-justify-content-start">
              <input :id="`ignore-${item.id}`" type="checkbox" class="switch is-success" :checked="item.ignored"
                     @change="toggleIgnore(item)">
              <label :for="`ignore-${item.id}`"></label>
              <span>Ignore content from this library.</span>
            </div>
            <div class="card-footer-item">
              <NuxtLink :to="`/backend/${backend}/stale/${item.id}?name=${item.title}`">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-sync"></i></span>
                  <span>Check Content Staleness</span>
                </span>
              </NuxtLink>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" title="Tips" icon="fas fa-info-circle"
                 :toggle="show_page_tips" @toggle="show_page_tips = !show_page_tips" :use-toggle="true">
          <ul>
            <li>Ignoring library will prevent any content from being added to the local database from the library
              during import process, and webhook events handling.
            </li>
            <li>Libraries that shows <code>Supported: No</code> will not be processed by <code>WatchState</code>.</li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import {notification} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import request from '~/utils/request.js'

const backend = useRoute().params.backend
const items = ref([])
const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  try {
    isLoading.value = true
    items.value = []

    const response = await request(`/backend/${backend}/library`)
    let json

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

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    items.value = json
  } catch (e) {
    return notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    isLoading.value = false
  }
}

const toggleIgnore = async (library) => {
  try {
    const newState = !library.ignored
    const response = await request(`/backend/${backend}/library/${library.id}`, {
      method: newState ? 'POST' : 'DELETE',
    });
    let json;
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

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success', `Library '${library.title}' has been ${newState ? 'ignored' : 'un-ignored'}.`)
    items.value[items.value.findIndex(b => b.id === library.id)].ignored = !library.ignored
  } catch (e) {
    return notification('error', 'Error', `Request error. ${e.message}`)
  }
}

onMounted(async () => loadContent())
</script>
