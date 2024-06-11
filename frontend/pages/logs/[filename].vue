<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <NuxtLink to="/logs">Logs</NuxtLink>
        : {{ filename }}
      </span>

      <div class="is-pulled-right" v-if="!error">
        <div class="field is-grouped">

          <p class="control">
            <button class="button is-danger" v-tooltip.bottom="'Delete Logfile.'" @click="deleteFile">
              <span class="icon"><i class="fas fa-trash"></i></span>
            </button>
          </p>

          <p class="control">
            <button class="button is-danger is-light" v-tooltip.bottom="'Download the entire logfile.'"
                    @click="downloadFile" :class="{'is-loading':isDownloading}">
              <span class="icon"><i class="fas fa-download"></i></span>
            </button>
          </p>

          <p class="control" v-if="filename.includes(moment().format('YYYYMMDD'))">
            <button class="button" v-tooltip.bottom="'Watch log'" @click="watchLog"
                    :class="{'is-primary':!stream,'is-danger':stream}">
              <span class="icon"><i class="fas fa-stream"></i></span>
            </button>
          </p>

          <p class="control">
            <button class="button is-warning" @click="wrapLines = !wrapLines" v-tooltip.bottom="'Toggle wrap line'">
              <span class="icon"><i class="fas fa-text-width"></i></span>
            </button>
          </p>

          <p class="control">
            <button class="button is-info" @click="loadContent" :disabled="isLoading" :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
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
      <code ref="logContainer" class="box logs-container" v-if="!error"
            :class="{'is-pre': !wrapLines, 'is-pre-wrap': wrapLines}">
        <span class="is-log-line is-block pt-1" v-for="(item, index) in data" :key="'log_line-'+index">
          {{ item }}
        </span>
      </code>
      <Message v-if="error" title="API Error" message_class="has-background-warning-90 has-text-dark"
               :message="error" :use-close="true" @close="router.push('/logs')"/>
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

import Message from '~/components/Message.vue'
import moment from 'moment'
import {useStorage} from '@vueuse/core'
import {notification} from '~/utils/index.js'
import request from '~/utils/request.js'

const router = useRouter()
const filename = useRoute().params.filename

useHead({title: `Logs : ${filename}`})

const data = ref([])
const error = ref('')
const wrapLines = useStorage('logs_wrap_lines', false)
const isDownloading = ref(false)
const isLoading = ref(false)

const api_path = useStorage('api_path', '/v1/api')
const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')

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
      data.value = text.split('\n')
    } else {
      try {
        const json = await response.json();
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
      data.value.push(lines[x]);
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
</script>
