<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink to="/backends">Backends</NuxtLink>
        -
        <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
        : Libraries
      </span>

      <div class="is-pulled-right">
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
        This page will show all the libraries that are available in the backend.
      </div>
    </div>

    <template v-if="items.length < 1">
      <Message message_class="has-background-info-90 has-text-dark" title="No Libraries">
        <span class="icon-text">
          <span class="icon"><i class="fas fa-info-circle"></i></span>
          <span>No libraries found in the backend.</span>
        </span>
      </Message>
    </template>

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
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="show_page_tips">
      <Message title="Tips" message_class="has-background-info-90 has-text-dark">
        <button class="delete" @click="show_page_tips=false"></button>
        <div class="content">
          <ul>
            <li>Ignoring library will prevent any content from being added to the local database from that library
              during import process, and via webhook events.
            </li>
            <li>Libraries that shows <code>Supported: No</code> will not be processed by the system.
            </li>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import {notification} from '~/utils/index.js'
import {useStorage} from '@vueuse/core'
import request from '~/utils/request.js'

const backend = useRoute().params.backend
const items = ref([])
const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  isLoading.value = true
  items.value = []

  let response, json

  try {
    response = await request(`/backend/${backend}/library`)
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

const toggleIgnore = async (library) => {
  const newState = !library.ignored

  try {
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
