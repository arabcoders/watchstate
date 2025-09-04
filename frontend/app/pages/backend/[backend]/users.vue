<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <NuxtLink to="/backends" v-text="'Backends'"/>
          -
          <NuxtLink :to="'/backend/' + backend" v-text="backend"/>
          : Users
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
          Show all users that are available in the backend.
        </div>
      </div>

      <div class="column is-12" v-if="!items || items?.length < 1">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
                 message="Loading users list. Please wait..." v-if="isLoading"/>
        <Message v-else message_class="has-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-circle"
                 message="WatchState was unable to get any users from the backend. This is expected if the backend is plex and the token is limited."/>
      </div>

      <div class="column is-6" v-for="item in items" :key="`users-${item.id}`">
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
                <strong>Updated:&nbsp;</strong>
                <span v-if="item.updatedAt === 'external_user' || item.updatedAt === 'never'">
                  {{ item.updatedAt }}
                </span>
                <span class="has-tooltip" v-tooltip="moment(item.updatedAt).format(TOOLTIP_DATE_FORMAT)" v-else>
                  {{ moment(item.updatedAt).fromNow() }}
                </span>
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

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <div class="notification-content content" v-if="show_page_tips">
            <ul>
              <li>For <code>Plex</code> backends, if the <code>X-Plex-Token</code> is limited one, the users will not
                show
                up. This is a limitation of the Plex API.
              </li>
            </ul>
          </div>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import moment from 'moment'
import {notification, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import request from '~/utils/request.js'

const backend = useRoute().params.backend
const items = ref()
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
