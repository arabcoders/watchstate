<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-globe" :class="{ 'fa-spin': isLoading }"/>&nbsp;</span>
          <NuxtLink to="/logs">Logs</NuxtLink>
          : {{ filename }}
        </span>

        <div class="is-pulled-right" v-if="!error">
          <div class="field is-grouped">
            <div class="control">
              <button v-if="!autoScroll" @click="scrollToBottom" class="button is-primary"
                      v-tooltip.bottom="'Go to bottom'">
                <span class="icon"><i class="fas fa-arrow-down"></i></span>
              </button>
            </div>

            <div class="control has-icons-left" v-if="toggleFilter">
              <input type="search" v-model.lazy="query" class="input" id="filter" placeholder="Filter">
              <span class="icon is-left"><i class="fas fa-filter"/></span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" v-tooltip.bottom="'Filter log lines.'"
                      @click="toggleFilter = !toggleFilter">
                <span class="icon"><i class="fas fa-filter"/></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-danger" v-tooltip.bottom="'Delete Logfile.'" @click="deleteFile">
                <span class="icon"><i class="fas fa-trash"/></span>
              </button>
            </p>

            <p class="control">
              <button class="button is-purple is-light" v-tooltip.bottom="'Download the entire logfile.'"
                      @click="downloadFile" :class="{ 'is-loading': isDownloading }">
                <span class="icon"><i class="fas fa-download"/></span>
              </button>
            </p>

            <p class="control">
              <button class="button is-warning" @click="wrapLines = !wrapLines" v-tooltip.bottom="'Toggle wrap line'">
                <span class="icon"><i class="fas fa-text-width"/></span>
              </button>
            </p>

            <p class="control">
              <button class="button" v-tooltip.bottom="'Copy showing logs'"
                      @click="() => copyText(filterItems.map(i => i.text).join('\n'))">
                <span class="icon"><i class="fas fa-copy"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            <template v-if="isTodayLog">The logs are being streamed in real-time.</template>
            Scroll-up to load older logs.
          </span>
        </div>
      </div>

      <div class="column is-12">
        <div class="logbox is-grid" ref="logContainer" v-if="!error" @scroll.passive="handleScroll">
          <code id="logView" class="p-1 logline is-block" :class="{ 'is-pre-wrap': wrapLines, 'is-pre': !wrapLines }">
            <span class="is-block m-0 notification is-info is-dark has-text-centered" v-if="reachedEnd && !query">
              <span class="notification-title">
                <span class="icon"><i class="fas fa-exclamation-triangle"/></span>
                No more logs available for this file.
              </span>
            </span>
            <span v-for="item in filterItems" :key="item.id" class="is-block">
              <span v-if="item.date">[<span class="has-tooltip" :title="item.date">{{
                  formatDate(item.date)
                }}</span>]:&nbsp;</span>
              <span v-if="item?.item_id"><span class="is-clickable has-tooltip" @click="goto_history_item(item)"><span
                  class="icon"><i class="fas fa-history"/></span><span>View</span></span>&nbsp;</span>
              <span>{{ item.text }}</span>
            </span>
            <span class="is-block" v-if="filterItems.length < 1">
              <span class="is-block m-0 notification is-warning is-dark has-text-centered" v-if="query">
                <span class="notification-title is-danger">
                  <span class="icon"><i class="fas fa-filter"/></span>
                  No logs match this query: <u>{{ query }}</u>
                </span>
              </span>
              <span v-else>
                <span class="has-text-danger">No logs available</span></span>
            </span>
          </code>
          <div ref="bottomMarker"></div>
        </div>

        <Message v-if="error" title="API Error" message_class="has-background-warning-90 has-text-dark" :message="error"
                 :use-close="true" @close="router.push('/logs')"/>
      </div>
    </div>
  </div>
</template>

<style scoped>
#logView {
  min-height: 72vh;
  min-width: inherit;
  max-width: 100%;
}

#logView > span:nth-child(even) {
  color: #ffc9d4;
}

#logView > span:nth-child(odd) {
  color: #e3c981;
}

code {
  background-color: unset;
}

.logbox {
  background-color: #1f2229;
  box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.2), 0 6px 20px 0 rgba(0, 0, 0, 0.19);
  min-width: 100%;
  max-height: 73vh;
  overflow-y: auto;
  overflow-x: auto;
}

div.logbox pre {
  background-color: rgb(31, 34, 41);
}

.logline {
  word-break: break-all;
  line-height: 2.3em;
  padding: 1em;
  color: #fff1b8;
}
</style>

<script setup>
import Message from '~/components/Message.vue'
import moment from 'moment'
import {useStorage} from '@vueuse/core'
import {disableOpacity, enableOpacity, goto_history_item, notification, parse_api_response} from '~/utils/index'
import request from '~/utils/request.js'
import {fetchEventSource} from '@microsoft/fetch-event-source'

const router = useRouter()
const filename = useRoute().params.filename

useHead({title: `Logs : ${filename}`})

const query = ref()
const data = ref([])
const error = ref('')
const wrapLines = useStorage('logs_wrap_lines', false)
const isDownloading = ref(false)
const isLoading = ref(false)
const toggleFilter = ref(false)
const autoScroll = ref(true)
const isTodayLog = computed(() => filename.includes(moment().format('YYYYMMDD')))
const reachedEnd = ref(false)
const offset = ref(0)
let scrollTimeout = null

const token = useStorage('token', '')

watch(toggleFilter, async () => {
  if (!toggleFilter.value) {
    query.value = ''
  }
});

const filterItems = computed(() => {
  if (!query.value) {
    return data.value ?? []
  }
  return data.value.filter(m => m.text.toLowerCase().includes(query.value.toLowerCase()));
});

/** @type {Ref<EventSource|null>} */
const stream = ref(null)

/** @type {Ref<HTMLPreElement|null>} */
const logContainer = ref(null)

/** @type {Ref<HTMLPreElement|null>} */
const bottomMarker = ref(null)

const ctrl = new AbortController();

const loadContent = async () => {
  try {
    isLoading.value = true
    const response = await request(`/log/${filename}?offset=${offset.value}`)
    const json = await parse_api_response(response)

    if (200 !== response.status) {
      error.value = `${json.error.code}: ${json.error.message}`
      return
    }

    if (useRoute().name !== 'logs-filename') {
      return
    }

    const lines = []

    json?.lines.forEach(i => {
      try {
        const line = String(i).trim()
        lines.push(line);
      } catch (error) {
        console.error(error)
      }
    })

    if (json?.lines?.length > 0) {
      data.value.unshift(...json.lines)
    }

    if ("next" in json) {
      offset.value = json.next ?? offset.value;
      if (null === json.next) {
        reachedEnd.value = true;
      }
    }

    // Auto-scroll only if the user was already at the bottom
    await nextTick(() => {
      if (autoScroll.value && bottomMarker.value) {
        bottomMarker.value.scrollIntoView({behavior: 'auto'})
      }
    })

    watchLog()

  } catch (e) {
    error.value = e
  } finally {
    isLoading.value = false
  }
}

const handleScroll = () => {
  if (!logContainer.value || query.value) {
    return
  }

  const container = logContainer.value
  const nearBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50
  const nearTop = container.scrollTop < 50
  autoScroll.value = nearBottom

  if (nearTop && !isLoading.value && !scrollTimeout && !reachedEnd.value) {
    scrollTimeout = setTimeout(async () => {
      const previousHeight = container.scrollHeight
      await loadContent()
      await nextTick(() => {
        const newHeight = container.scrollHeight
        container.scrollTop += newHeight - previousHeight
      })
      scrollTimeout = null
    }, 300)
  }
}

const scrollToBottom = () => {
  autoScroll.value = true
  nextTick(() => {
    if (bottomMarker.value) {
      bottomMarker.value.scrollIntoView({behavior: 'smooth'})
    }
  })
}

onMounted(async () => {
  await loadContent()
  await nextTick(() => disableOpacity())
});

onBeforeUnmount(() => closeStream());

onUnmounted(async () => {
  closeStream()
  await nextTick(() => enableOpacity())
});

const watchLog = () => {
  if (!isTodayLog.value || null !== stream.value) {
    closeStream();
    return;
  }

  // noinspection JSValidateTypes
  stream.value = fetchEventSource(`/v1/api/log/${filename}?stream=1`, {
    onmessage: async evt => {
      if ('data' !== evt.event) {
        return
      }

      let lines = evt.data.split(/\n/g);

      for (let x = 0; x < lines.length; x++) {
        try {
          const line = String(lines[x])
          if (!line.trim()) {
            continue
          }
          data.value.push(JSON.parse(line))

          await nextTick(() => {
            if (autoScroll.value && bottomMarker.value) {
              bottomMarker.value.scrollIntoView({behavior: 'smooth'})
            }
          })
        } catch (error) {
          console.error(error)
        }
      }
    },
    headers: {
      Authorization: `Token ${token.value}`,
    },
    signal: ctrl.signal,
  })
}

const closeStream = () => {
  if (stream.value) {
    ctrl.abort()
    stream.value = null
  }
}

const downloadFile = () => {
  isDownloading.value = true;

  const response = request(`/log/${filename}?download=1`)

  if ('showSaveFilePicker' in window) {
    response.then(async res => {
      isDownloading.value = false;

      return res.body.pipeTo(await (await window.showSaveFilePicker({
        suggestedName: `${filename}`
      })).createWritable())

    })
  } else {
    response.then(res => res.blob()).then(blob => {
      isDownloading.value = false;
      const fileURL = URL.createObjectURL(blob)
      const fileLink = document.createElement('a')
      fileLink.href = fileURL
      fileLink.download = `${filename}`
      fileLink.click()
    });
  }
}

const deleteFile = async () => {
  if (!confirm(`Are you sure you want to delete '${filename}'? this cannot be undone.`)) {
    return;
  }

  try {
    closeStream();

    const response = await request(`/log/${filename}`, {method: 'DELETE'})

    if (response.ok) {
      notification('success', 'Information', `Logfile '${filename}' has been deleted.`)
      const router = useRouter()
      await router.push('/logs')
      return;
    }

    let json;

    try {
      json = await response.json()
    } catch (e) {
      json = {
        error: {code: response.status, message: response.statusText}
      }
    }

    notification('error', 'Error', `Request to delete logfile failed. (${json.error.code}: ${json.error.message}).`)
  } catch (e) {
    notification('error', 'Error', `Failed to request to delete a logfile. ${e}.`)
  }
}

const formatDate = dt => moment(dt).format('DD/MM HH:mm:ss')
</script>
