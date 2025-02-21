<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-globe"></i>&nbsp;</span>
          <NuxtLink to="/logs">Logs</NuxtLink>
          : {{ filename }}
        </span>

        <div class="is-pulled-right" v-if="!error">
          <div class="field is-grouped">
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

            <p class="control" v-if="filename.includes(moment().format('YYYYMMDD'))">
              <button class="button" v-tooltip.bottom="'Watch log'" @click="watchLog"
                      :class="{ 'is-primary': !stream, 'is-danger': stream }">
                <span class="icon"><i class="fas fa-stream"/></span>
              </button>
            </p>

            <p class="control">
              <button class="button is-warning" @click="wrapLines = !wrapLines" v-tooltip.bottom="'Toggle wrap line'">
                <span class="icon"><i class="fas fa-text-width"/></span>
              </button>
            </p>

            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>

          </div>
        </div>
      </div>

      <div class="column is-12">
        <div class="notification has-background-info-90 has-text-dark" v-if="stream">
          <button class="delete" @click="watchLog"></button>
          <span class="icon-text">
            <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
            <span>Streaming log content...</span>
          </span>
        </div>

        <div class="is-relative" v-if="!error">
          <code ref="logContainer" class="box logs-container"
                :class="{ 'is-pre': !wrapLines, 'is-pre-wrap': wrapLines }">
            <span class="is-log-line is-block pt-1" v-for="(item, index) in filterItems" :key="'log_line-' + index">
              <span v-if="item.date">
                [<span class="has-tooltip" :title="item.date">{{ formatDate(item.date) }}</span>]:&nbsp;
              </span>
              <span v-if="item?.item_id">
                <NuxtLink @click="goto_history_item(item)">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-history"/></span>
                    <span>View</span>
                  </span>
                </NuxtLink>&nbsp;
              </span>
              <span>{{ item.text }}</span>
            </span>
          </code>
          <button class="button m-4" v-tooltip="'Copy logs'"
                  @click="() => copyText(filterItems.map(i => i.text).join('\n'))"
                  style="position: absolute; top:0; right:0;">
            <span class="icon"><i class="fas fa-copy"></i></span>
          </button>
        </div>
        <Message v-if="error" title="API Error" message_class="has-background-warning-90 has-text-dark" :message="error"
                 :use-close="true" @close="router.push('/logs')"/>
      </div>
    </div>
  </div>
</template>

<style scoped>
.logs-container {
  min-height: 50vh;
  max-height: 60vh;
  overflow-y: auto;
}
</style>

<script setup>
import Message from '~/components/Message'
import moment from 'moment'
import {useStorage} from '@vueuse/core'
import {goto_history_item, notification} from '~/utils/index'
import request from '~/utils/request'

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

const api_path = useStorage('api_path', '/v1/api')
const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')

watch(toggleFilter, () => {
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

const loadContent = async () => {
  try {
    isLoading.value = true
    const response = await request(`/log/${filename}`)
    if (response.ok) {
      const text = await response.text()

      if (useRoute().name !== 'logs-filename') {
        return
      }

      const lines = []

      text.trim().split('\n').forEach(i => {
        try {
          const line = String(i).trim()
          lines.push(line ? JSON.parse(line) : {
            "backend": null,
            "user": null,
            "date": null,
            "item_id": null,
            "text": line,
          });
        } catch (error) {
          console.error(error)
        }
      })

      data.value = lines;
    } else {
      try {
        const json = await response.json();
        if (useRoute().name !== 'logs-filename') {
          return
        }
        error.value = `${json.error.code}: ${json.error.message}`
      } catch (e) {
        error.value = `${response.status}: ${response.statusText}`
      }
    }
  } catch (e) {
    error.value = e
  } finally {
    isLoading.value = false
  }
}

onMounted(() => loadContent());
onBeforeUnmount(() => closeStream());
onUnmounted(() => closeStream());

const watchLog = () => {
  if (null !== stream.value) {
    closeStream();
    return;
  }

  // noinspection JSValidateTypes
  stream.value = new EventSource(`${api_url.value}${api_path.value}/log/${filename}?stream=1&apikey=${api_token.value}`)
  stream.value.addEventListener('data', e => {
    let lines = e.data.split(/\n/g);
    for (let x = 0; x < lines.length; x++) {
      try {
        const line = String(lines[x])
        if (!line.trim()) {
          continue
        }
        data.value.push(JSON.parse(line))
      } catch (error) {
        console.error(error)
      }
    }
  });
}

const closeStream = () => {
  if (stream.value) {
    stream.value.close()
    stream.value = null
  }
}

const downloadFile = () => {
  isDownloading.value = true;

  const response = request(`/log/${filename}?download=1`)

  if ('showSaveFilePicker' in window) {
    response.then(async res => {
      isDownloading.value = false;

      return res.body.pipeTo(await (await showSaveFilePicker({
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

const updateScroll = () => logContainer.value.scrollTop = logContainer.value.scrollHeight;

onUpdated(() => {
  if (error.value) {
    return
  }
  updateScroll()
});

const formatDate = dt => moment(dt).format('DD/MM HH:mm')
</script>
