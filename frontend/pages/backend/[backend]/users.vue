<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink to="/backends">Backends</NuxtLink>
        -
        <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
        : Users
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
        Show all users that are available in the backend.
      </div>
    </div>

    <div class="column is-12" v-if="items.length < 1">
      <Message message_class="has-background-info-90 has-text-dark" title="No Libraries">
        <span class="icon-text" v-if="isLoading">
          <span class="icon"><i class="fas fa-spinner fa-spin"></i></span>
          <span>Loading users list. Please wait...</span>
        </span>
        <span class="icon-text" v-else>
          <span class="icon"><i class="fas fa-info-circle"></i></span>
          <span>No users found in the backend. This is expected if the backend is plex and the token is limited.</span>
        </span>
      </Message>
    </div>

    <div class="column is-6" v-for="item in items" :key="`library-${item.id}`">
      <div class="card">
        <header class="card-header">
          <p class="card-header-title is-text-overflow">
            {{ item.name }}
          </p>
          <div class="card-header-icon"></div>
        </header>
        <div class="card-content">
          <div class="columns is-mobile is-multiline">
            <div class="column is-6">
              <strong>Admin:</strong> {{ item.admin ? 'Yes' : 'No' }}
            </div>
            <div class="column is-6 has-text-right" v-if="undefined !== item?.guest">
              <strong>Guest:</strong> {{ item.guest ? 'Yes' : 'No' }}
            </div>
            <div class="column is-6 has-text-right" v-if="undefined !== item?.hidden">
              <strong>Hidden:</strong> {{ item.guest ? 'Yes' : 'No' }}
            </div>

            <div class="column is-6" v-if="item?.updatedAt">
              <strong>Updated:</strong> {{ moment(item.updatedAt).fromNow() }}
            </div>

            <div class="column is-6 has-text-right" v-if="undefined !== item?.restricted">
              <strong>Restricted:</strong> {{ item.restricted ? 'Yes' : 'No' }}
            </div>

            <div class="column is-6 has-text-right" v-if="undefined !== item?.disabled">
              <strong>Disabled:</strong> {{ item.restricted ? 'Yes' : 'No' }}
            </div>


          </div>
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="show_page_tips">
      <Message title="Tips" message_class="has-background-info-90 has-text-dark">
        <button class="delete" @click="show_page_tips=false"></button>
        <div class="content">
          <ul>
            <li>For <code>Plex</code> backends, if the <code>X-Plex-Token</code> is limited one, the users will not show
              up. This is a limitation of the Plex API.
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
import moment from "moment";

const backend = useRoute().params.backend

const items = ref({
  "id": 14138718,
  "uuid": "145bdb152ed42627",
  "name": "wowkise",
  "admin": true,
  "guest": false,
  "restricted": false,
  "updatedAt": "2024-05-22T12:47:41+03:00"
})

const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)
const loadContent = async () => {
  isLoading.value = true
  items.value = []

  let response, json

  try {
    response = await request(`/backend/${backend}/users`)
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

onMounted(async () => loadContent())
</script>
