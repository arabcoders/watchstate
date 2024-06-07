<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink to="/history">History</NuxtLink>
        : {{ data?.full_title ?? data?.title ?? id }}
      </span>
      <div class="is-pulled-right" v-if="data?.via">
        <div class="field is-grouped">
          <p class="control">
            <button class="button" @click="toggleWatched"
                    :class="{ 'is-success': !data.watched, 'is-danger': data.watched }"
                    v-tooltip="'Toggle played/unplayed'">
              <span class="icon">
                <i class="fas" :class="{'fa-eye-slash':data.watched,'fa-eye':!data.watched}"></i>
              </span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click="loadContent(id)" :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="subtitle" v-if="data?.via && getTitle !== data.title">
        {{ getTitle }}
      </div>
    </div>

    <div class="column is-12" v-if="!data?.via && isLoading">
      <Message>
        <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
        <span>Loading data. Please wait...</span>
      </Message>
    </div>

    <div class="column is-12" v-if="data?.via">
      <div class="card" :class="{ 'is-success': parseInt(data.watched), 'is-danger': !data.watched }">
        <header class="card-header">
          <div class="card-header-title">
            <span>Latest local metadata via</span>
          </div>
          <div class="card-header-icon">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"></i></span>
              <span>
                <NuxtLink :to="`/backend/${data.via}`" v-text="data.via"/>
              </span>
            </span>
          </div>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile">
            <div class="column is-6">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-passport"></i></span>
                <span>
                  <span class="is-hidden-mobile">ID:&nbsp;</span>
                  <NuxtLink :to="`/history/${data.id}`" v-text="data.id"/>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text" v-if="parseInt(data.progress)">
                <span class="icon"><i class="fas fa-bars-progress"></i></span>
                <span><span class="is-hidden-mobile">Progress:</span> {{ formatDuration(data.progress) }}</span>
              </span>
              <span v-else>-</span>
            </div>

            <div class="column is-6 has-text-left">
              <span class="icon-text">
                <span class="icon">
                  <i class="fas fa-eye-slash" v-if="!data.watched"></i>
                  <i class="fas fa-eye" v-else></i>
                </span>
                <span>
                  <span class="is-hidden-mobile">Status:</span>
                  {{ data.watched ? 'Played' : 'Unplayed' }}
                </span>
              </span>
            </div>
            <div class="column is-6 has-text-right">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-envelope"></i></span>
                <span>
                  <span class="is-hidden-mobile">Event:</span>
                  {{ ag(data.extra, `${data.via}.event`, 'Unknown') }}
                </span>
              </span>
            </div>
            <div class="column is-6 has-text-left">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-calendar"></i></span>
                <span>
                  <span class="is-hidden-mobile">Updated:</span>
                  {{ moment(data.updated).fromNow() }}
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text">
                <span class="icon" v-if="'episode' === data.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
                <span>
                  <span class="is-hidden-mobile">Type:</span>
                  {{ ucFirst(data.type) }}
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-left" v-if="'episode' === data.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span><span class="is-hidden-mobile">Season:</span> {{ data.season }}</span>
              </span>
            </div>

            <div class="column is-6 has-text-right" v-if="'episode' === data.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span><span class="is-hidden-mobile">Episode:</span> {{ data.episode }}</span>
              </span>
            </div>

            <div class="column is-12" v-if="data.guids && Object.keys(data.guids).length>0">
              <span class="icon-text is-clickable" v-tooltip="'Globally unique identifier for this item'">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>GUIDs:</span>
              </span>
              <span class="tag mr-1" v-for="(guid,source) in data.guids">
                <NuxtLink target="_blank" :to="makeGUIDLink( data.type, source.split('guid_')[1], guid, data)">
                  {{ source.split('guid_')[1] }}-{{ guid }}
                </NuxtLink>
              </span>
            </div>

            <div class="column is-12" v-if="data.parent && Object.keys(data.parent).length>0">
              <span class="icon-text is-clickable" v-tooltip="'Globally unique identifier for the series'">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>Series GUIDs:</span>
              </span>
              <span class="tag mr-1" v-for="(guid,source) in data.parent">
                <NuxtLink target="_blank" :to="makeGUIDLink( 'series', source.split('guid_')[1], guid, data)">
                  {{ source.split('guid_')[1] }}-{{ guid }}
                </NuxtLink>
              </span>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="data?.via && Object.keys(data.metadata).length>0">
      <div class="card" v-for="(item, key) in data.metadata" :key="key"
           :class="{ 'is-success': parseInt(item.watched), 'is-danger': !parseInt(item.watched) }">
        <header class="card-header">
          <div class="card-header-title">
            Metadata via
          </div>
          <div class="card-header-icon">
            <span class="icon-text">
              <span class="icon"><i class="fas fa-server"></i></span>
              <span>
                <NuxtLink :to="`/backend/${key}`" v-text="key"/>
              </span>
            </span>
          </div>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile">

            <div class="column is-6">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-passport"></i></span>
                <span>
                  <span class="is-hidden-mobile">ID:&nbsp;</span>
                  <NuxtLink :to="item?.webUrl" target="_blank" v-text="item.id" v-if="item?.webUrl"/>
                  <span v-else v-text="item.id"/>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text" v-if="parseInt(item?.progress)">
                <span class="icon"><i class="fas fa-bars-progress"></i></span>
                <span><span class="is-hidden-mobile">Progress:</span> {{ formatDuration(item.progress) }}</span>
              </span>
              <span v-else>-</span>
            </div>

            <div class="column is-6">
              <span class="icon-text">
                <span class="icon">
                  <i class="fas fa-eye-slash" v-if="!parseInt(item.watched)"></i>
                  <i class="fas fa-eye" v-else></i>
                </span>
                <span>
                  <span class="is-hidden-mobile">Status:</span>
                  {{ parseInt(item.watched) ? 'Played' : 'Unplayed' }}
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-envelope"></i></span>
                <span>
                  <span class="is-hidden-mobile">Event:</span>
                  {{ ag(data.extra, `${key}.event`, 'Unknown') }}
                </span>
              </span>
            </div>

            <div class="column is-6">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-calendar"></i></span>
                <span>
                  <span class="is-hidden-mobile">Updated:</span>
                  {{ moment(ag(data.extra, `${key}.received_at`, data.updated)).fromNow() }}
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text">
                <span class="icon" v-if="'episode' === item.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
                <span>
                  <span class="is-hidden-mobile">Type:</span>
                  {{ ucFirst(item.type) }}
                </span>
              </span>
            </div>

            <div class="column is-6" v-if="'episode' === item.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span><span class="is-hidden-mobile">Season:</span> {{ item.season }}</span>
              </span>
            </div>

            <div class="column is-6 has-text-right" v-if="'episode' === item.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span><span class="is-hidden-mobile">Episode:</span> {{ item.episode }}</span>
              </span>
            </div>

            <div class="column is-12" v-if="item.guids && Object.keys(item.guids).length>0">
              <span class="icon-text is-clickable" v-tooltip="'Globally unique identifier for this item'">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>GUIDs:</span>
              </span>
              <span class="tag mr-1" v-for="(guid,source) in item.guids">
                <NuxtLink target="_blank" :to="makeGUIDLink( item.type, source.split('guid_')[1], guid, item)">
                  {{ source.split('guid_')[1] }}-{{ guid }}
                </NuxtLink>
              </span>
            </div>

            <div class="column is-12" v-if="item.parent && Object.keys(item.parent).length>0">
              <span class="is-clickable icon-text" v-tooltip="'Globally unique identifier for the series'">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>Series GUIDs:</span>
              </span>
              <span class="tag mr-1" v-for="(guid,source) in item.parent">
                <NuxtLink target="_blank" :to="makeGUIDLink( 'series', source.split('guid_')[1], guid, item)">
                  {{ source.split('guid_')[1] }}-{{ guid }}
                </NuxtLink>
              </span>
            </div>

            <div class="column is-12" v-if="item?.extra.title">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-heading"></i></span>
                <span><span class="is-hidden-mobile">Title:</span> {{ item.extra.title }}</span>
              </span>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="column is-12">
      <span class="title is-4 is-clickable" @click="showRawData = !showRawData">
        <span class="icon-text">
          <span class="icon">
            <i v-if="showRawData" class="fas fa-arrow-up"></i>
            <i v-else class="fas fa-arrow-down"></i>
          </span>
          <span>Show raw data...</span>
        </span>
      </span>
      <div v-if="showRawData" class="mt-2">
        <pre><code>{{ JSON.stringify(data, null, 2) }}</code></pre>
      </div>
    </div>

    <div class="column is-12" v-if="show_page_tips">
      <Message title="Tips" message_class="has-background-info-90 has-text-dark">
        <button class="delete" @click="show_page_tips=false"></button>
        <div class="content">
          <ul>
            <li>Clicking on the ID in <code>metadata via</code> boxes will take you directly to the item in the source
              backend. While clicking on the GUIDs will take you to that source link, similarly clicking on the series
              GUIDs will take you to the series link that was provided by the external source.
            </li>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import {ag, formatDuration, makeGUIDLink, notification, ucFirst} from '~/utils/index.js'
import moment from 'moment'
import {useStorage} from "@vueuse/core";

const id = useRoute().params.id

useHead({title: `History : ${id}`})

const isLoading = ref(false)
const showRawData = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const data = ref({
  id: id,
  title: `${id}`,
  via: null,
  metadata: {},
  guids: {},
  parent: {},
});

const loadContent = async (id) => {
  isLoading.value = true

  const response = await request(`/history/${id}`)
  const json = await response.json()

  isLoading.value = false

  if (200 !== response.status) {
    notification('Error', 'Error loading data', `${json.error.code}: ${json.error.message}`);
    return
  }

  data.value = json

  useHead({title: `History : ${json.full_title ?? json.title ?? id}`})
}

const getTitle = computed(() => {
  if (!data.value) {
    return id
  }

  if (data.value?.via) {
    return ag(data.value, `metadata.${data.value.via}.extra.title`, data.value.title)
  }

  return data.value.title
})

const toggleWatched = async () => {
  if (!data.value) {
    return
  }
  if (!confirm(`Mark '${data.value.full_title}' as ${data.value.watched ? 'unplayed' : 'played'}?`)) {
    return
  }
  try {
    const response = await request(`/history/${data.value.id}/watch`, {
      method: data.value.watched ? 'DELETE' : 'POST'
    })

    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    data.value = json
    notification('success', '', `Marked '${data.value.full_title}' as ${data.value.watched ? 'played' : 'unplayed'}`)

  } catch (e) {
    notification('error', 'Error', `Failed to update watched status. ${e}`)
  }
}

onMounted(async () => loadContent(id))
</script>
