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

    <div class="column is-12" v-if="data?.not_reported_by && data.not_reported_by.length>0">
      <Message message_class="has-background-warning-80 has-text-dark">
        <div class="is-pulled-right">
          <NuxtLink @click="show_history_page_warning=false" v-if="show_history_page_warning">
            <span class="icon"><i class="fas fa-arrow-up"></i></span>
            <span>Close</span>
          </NuxtLink>
          <NuxtLink @click="show_history_page_warning=true" v-else>
            <span class="icon"><i class="fas fa-arrow-down"></i></span>
            <span>Open</span>
          </NuxtLink>
        </div>
        <h5 class="title is-5 is-unselectable">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
            <span>Warning</span>
          </span>
        </h5>
        <div class="content" v-if="show_history_page_warning">
          <p>
            <span class="icon"><i class="fas fa-exclamation"></i></span>
            There are no metadata regarding this <strong>{{ data.type }}</strong> from (
            <span class="tag mr-1" v-for="backend in data.not_reported_by" :key="`nr-${backend}`">
              <NuxtLink :to="`/backend/${backend}`" v-text="backend"/>
            </span>).
          </p>
          <h5 class="has-text-dark">Possible reasons:</h5>
          <ul>
            <li>Delayed import operation. Might be yet to be imported due to webhooks not being used, or the backend
              doesn't support webhooks.
            </li>
            <li>Item mismatched at the source backend.</li>
            <li>
              There are no matching <code>{{ 'episode' === data.type ? 'Series GUIDs' : 'GUIDs' }}</code> in common
              being reported, And thus it was treated as separate item.
            </li>
          </ul>
          <p class="has-text-danger-50">
            <span class="icon"><i class="fas fa-info"></i></span> To see if your media backends are reporting different
            metadata for the same file, click on the file link which will filter your history based on that file.
          </p>
        </div>
      </Message>
    </div>

    <div class="column is-12" v-if="data?.via">
      <div class="card" :class="{ 'is-success': parseInt(data.watched), 'is-danger': !data.watched }">
        <header class="card-header">
          <div class="card-header-title is-clickable is-unselectable" @click="data._toggle = !data._toggle">
            <span class="icon">
              <i class="fas" :class="{'fa-arrow-up': data?._toggle, 'fa-arrow-down': !data?._toggle}"></i>
            </span>
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
        <div class="card-content" v-if="data?._toggle">
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
                  <span class="is-hidden-mobile">Updated:&nbsp;</span>
                  <span class="has-tooltip" v-tooltip="moment(data.updated).format('YYYY-MM-DD h:mm:ss A')">
                    {{ moment(data.updated).fromNow() }}
                  </span>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text">
                <span class="icon" v-if="'episode' === data.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
                <span>
                  <span class="is-hidden-mobile">Type:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('type',data.type)" v-text="ucFirst(data.type)"/>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-left" v-if="'episode' === data.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span><span class="is-hidden-mobile">Season:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('season',data.season)" v-text="data.season"/>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right" v-if="'episode' === data.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span><span class="is-hidden-mobile">Episode:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('episode',data.episode)" v-text="data.episode"/>
                </span>
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

            <div class="column is-12" v-if="data.rguids && Object.keys(data.rguids).length>0">
              <span class="icon-text is-clickable" v-tooltip="'Relative Globally unique identifier for this episode'">
                <span class="icon"><i class="fas fa-link"></i></span>
                <span>rGUIDs:</span>
              </span>
              <span class="tag mr-1" v-for="(guid,source) in data.rguids">
                <NuxtLink :to="makeSearchLink('rguid', `${source.split('guid_')[1]}://${guid}`)">
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

            <div class="column is-12" v-if="data?.title">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-heading"></i></span>
                <span class="is-hidden-mobile">Title:</span>
                {{ data.title }}
              </div>
            </div>

            <div class="column is-12" v-if="data?.path">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-file"></i></span>
                <span class="is-hidden-mobile">File:&nbsp;</span>
                <NuxtLink :to="makeSearchLink('path',data.path)" v-text="data.path"/>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <div class="column is-12" v-if="data?.via && Object.keys(data.metadata).length>0">
      <div class="card" v-for="(item, key) in data.metadata" :key="key"
           :class="{ 'is-success': parseInt(item.watched), 'is-danger': !parseInt(item.watched) }">
        <header class="card-header">
          <div class="card-header-title is-clickable is-unselectable" @click="item._toggle = !item._toggle">
            <span class="icon">
              <i class="fas" :class="{'fa-arrow-up': item?._toggle, 'fa-arrow-down': !item?._toggle}"></i>
            </span>
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
        <div class="card-content" v-if="item?._toggle">
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
                  <span class="is-hidden-mobile">Updated:&nbsp;</span>
                  <span class="has-tooltip"
                        v-tooltip="moment(ag(data.extra, `${key}.received_at`, data.updated)).format('YYYY-MM-DD h:mm:ss A')">
                    {{ moment(ag(data.extra, `${key}.received_at`, data.updated)).fromNow() }}
                  </span>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right">
              <span class="icon-text">
                <span class="icon" v-if="'episode' === item.type"><i class="fas fa-tv"></i></span>
                <span class="icon" v-else><i class="fas fa-film"></i></span>
                <span>
                  <span class="is-hidden-mobile">Type:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('type',item.type)" v-text="ucFirst(item.type)"/>
                </span>
              </span>
            </div>

            <div class="column is-6" v-if="'episode' === item.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span>
                  <span class="is-hidden-mobile">Season:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('season',item.season)" v-text="item.season"/>
                </span>
              </span>
            </div>

            <div class="column is-6 has-text-right" v-if="'episode' === item.type">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-tv"></i></span>
                <span>
                  <span class="is-hidden-mobile">Episode:&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('episode',item.episode)" v-text="item.episode"/>
                </span>
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

            <div class="column is-12" v-if="item?.extra?.title">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-heading"></i></span>
                <span class="is-hidden-mobile">Title:</span>
                {{ item.extra.title }}
              </div>
            </div>

            <div class="column is-12" v-if="item?.path">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-file"></i></span>
                <span class="is-hidden-mobile">File:&nbsp;</span>
                <NuxtLink :to="makeSearchLink('path',item.path)" v-text="item.path"/>
              </div>
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

    <div class="column is-12">
      <Message message_class="has-background-info-90 has-text-dark">
        <div class="is-pulled-right">
          <NuxtLink @click="show_page_tips=false" v-if="show_page_tips">
            <span class="icon"><i class="fas fa-arrow-up"></i></span>
            <span>Close</span>
          </NuxtLink>
          <NuxtLink @click="show_page_tips=true" v-else>
            <span class="icon"><i class="fas fa-arrow-down"></i></span>
            <span>Open</span>
          </NuxtLink>
        </div>
        <h5 class="title is-5 is-unselectable">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <span>Tips</span>
          </span>
        </h5>
        <div class="content" v-if="show_page_tips">
          <ul>
            <li>Clicking on the ID in <code>metadata via</code> boxes will take you directly to the item in the source
              backend. While clicking on the GUIDs will take you to that source link, similarly clicking on the series
              GUIDs will take you to the series link that was provided by the external source.
            </li>
            <li>
              <code>rGUIDSs</code> are relative globally unique identifiers for episodes based on <code>series
              GUID</code>. They are formatted as <code>GUID://seriesID/season_number/episode_number</code>. We use
              <code>rGUIDs</code>, to identify specific episode. This is more reliable than using episode specific
              <code>GUID</code>, as they are often misreported in the source data.
            </li>
            <template v-if="data?.not_reported_by && data.not_reported_by.length > 0">
              <li>
                The warning on top of the page usually is accurate, and it is recommended to check the backend metadata
                for the item.
                <template v-if="'episode' === data.type">
                  For episodes, we use <code>rGUIDs</code> to identify the episode, and <strong>important part</strong>
                  of that GUID is the <code>series GUID</code>. We need at least one reported series GUIDs to match
                  between your backends. If none are matching, it will be treated as separate series.
                </template>
              </li>
            </template>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import {ag, formatDuration, makeGUIDLink, makeSearchLink, notification, ucFirst} from '~/utils/index.js'
import moment from 'moment'
import {useStorage} from "@vueuse/core";

const id = useRoute().params.id

useHead({title: `History : ${id}`})

const isLoading = ref(false)
const showRawData = ref(false)
const show_page_tips = useStorage('show_page_tips', true)
const show_history_page_warning = useStorage('show_history_page_warning', true)

const data = ref({
  id: id,
  title: `${id}`,
  via: null,
  metadata: {},
  guids: {},
  parent: {},
  rguids: {},
  not_reported_by: [],
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
  data.value._toggle = true

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
