<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4 ">
          <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
          <NuxtLink to="/backends" v-text="'Backends'"/>
          -
          <NuxtLink :to="`/backend/${backend}`" v-text="backend"/>
          : Staleness
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click.prevent="loadContent()" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page will show items from local database that no longer exists in the remote backend library.
          </span>
        </div>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="items.length>0">
          <div class="column is-12">
            <Message message_class="has-background-warning-80 has-text-dark" title="Warning"
                     icon="fas fa-exclamation-triangle">
              <b>WatchState</b>, has found '<span class="has-text-danger is-bold"><u>{{ counts.stale }}</u></span>'
              items in local database, that no longer exists in <b>{{ remote.name }}</b>
              library <b>{{ remote.library?.title }}</b>.
            </Message>
          </div>
          <template v-for="item in items" :key="item.id">
            <Lazy :unrender="true" :min-height="343" class="column is-6-tablet">
              <div class="card" :class="{ 'is-success': item.watched }">
                <header class="card-header">
                  <p class="card-header-title is-text-overflow pr-1">
                    <NuxtLink :to="'/history/'+item.id" v-text="makeName(item)"/>
                  </p>
                  <span class="card-header-icon" @click="item.showRawData = !item?.showRawData">
                    <span class="icon">
                      <i class="fas"
                         :class="{ 'fa-tv': 'episode' === item.type.toLowerCase(), 'fa-film': 'movie' === item.type.toLowerCase()}"></i>
                    </span>
                  </span>
                </header>
                <div class="card-content">
                  <div class="columns is-multiline is-mobile">
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_title, 'is-text-contents': item?.expand_title}"
                             @click="item.expand_title = !item?.expand_title">
                          <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                          <template v-if="item?.content_title">
                            <NuxtLink :to="makeSearchLink('subtitle', item.content_title)" v-text="item.content_title"/>
                          </template>
                          <template v-else>
                            <NuxtLink :to="makeSearchLink('subtitle', item.title)" v-text="item.title"/>
                          </template>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable"
                                @click="copyText(item?.content_title ?? item.title, false)">
                            <i class="fas fa-copy"></i></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-clickable"
                             :class="{'is-text-overflow': !item?.expand_path, 'is-text-contents': item?.expand_path}"
                             @click="item.expand_path = !item?.expand_path">
                          <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                          <NuxtLink v-if="item?.content_path" :to="makeSearchLink('path', item.content_path)"
                                    v-text="item.content_path"/>
                          <span v-else>No path found.</span>
                        </div>
                        <div class="control">
                          <span class="icon is-clickable"
                                @click="copyText(item?.content_path ?item.content_path : null, false)">
                            <i class="fas fa-copy"></i></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-expanded is-unselectable">
                          <span class="icon"><i class="fas fa-info"></i>&nbsp;</span>
                          <span>Has metadata from</span>
                        </div>
                        <div class="control">
                          <NuxtLink v-for="backend in item.reported_by" :key="`${item.id}-rb-${backend}`"
                                    :to="'/backend/'+backend" v-text="backend" class="tag is-primary ml-1"/>
                          <NuxtLink v-for="backend in item.not_reported_by" :key="`${item.id}-nrb-${backend}`"
                                    :to="'/backend/'+backend" v-text="backend" class="tag is-danger ml-1"/>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="card-content p-0 m-0" v-if="item?.showRawData">
                <pre style="position: relative; max-height: 343px;"><code>{{ JSON.stringify(item, null, 2) }}</code>
                  <button class="button is-small m-4" @click="() => copyText(JSON.stringify(item, null, 2))"
                          style="position: absolute; top:0; right:0;">
                    <span class="icon"><i class="fas fa-copy"></i></span>
                  </button>
                </pre>
                </div>
                <div class="card-footer">
                  <div class="card-footer-item">
                    <span class="icon">
                      <i class="fas" :class="{'fa-eye':item.watched,'fa-eye-slash':!item.watched}"></i>&nbsp;
                    </span>
                    <span class="has-text-success" v-if="item.watched">Played</span>
                    <span class="has-text-danger" v-else>Unplayed</span>
                  </div>
                  <div class="card-footer-item">
                    <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                    <span class="has-tooltip"
                          v-tooltip="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`">
                      {{ moment.unix(item.updated_at).fromNow() }}
                    </span>
                  </div>
                </div>
              </div>
            </lazy>
          </template>
        </div>

        <div class="column is-12" v-else>
          <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                   icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
          <template v-else>
            <Message message_class="has-background-success-90 has-text-dark" v-if="items.length < 1"
                     title="Success" icon="fas fa-check">
              Great, WatchState checked '<u>{{ counts.local }}</u>' items against
              <b>{{ remote.name }}</b> library <b>{{ remote.library?.title }}</b>
              '<u>{{ counts.remote }}</u>' items and did not find any local reference that isn't in the remote library.
            </Message>
          </template>
        </div>

        <div class="column is-12">
          <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                   @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
            <ul>
              <li>
                This page is used to show stale references to items in the local database compared to the backend.
              </li>
              <li>Remote data is cached in memory to speed up reloads.</li>
              <li>Is there harm in having stale references? there is no harm, however somethings might not work as
                expected if the item has changed id, for example, pushing via webhooks will fail as it's reference old
                item that no longer exists.
              </li>
            </ul>
          </Message>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import Message from '~/components/Message.vue'
import {copyText, makeName, makeSearchLink, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import moment from 'moment'
import {useStorage} from '@vueuse/core'
import Lazy from '~/components/Lazy.vue'

const route = useRoute()


const id = route.params.id
const backend = route.params.backend

const items = ref([])
const remote = ref([])
const counts = ref({remote: 0, local: 0, stale: 0})
const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  useHead({title: `Backends - ${backend}: ${route.query.name ?? ''} Staleness`})
  isLoading.value = true

  try {
    const response = await request(`/backend/${backend}/stale/${id}`)
    const json = await response.json()
    items.value = json.items
    remote.value = json.backend
    counts.value = json.counts
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => loadContent())
</script>
