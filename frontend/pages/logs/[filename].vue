<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink href="/logs">Logs</NuxtLink>
        : {{ filename }}
      </span>

      <div class="is-pulled-right" v-if="!error">
        <div class="field is-grouped">

          <p class="control" v-if="filename.includes(moment().format('YYYYMMDD'))">
            <button class="button" v-tooltip="'Watch log'" @click="watchLog"
                    :class="{'is-info':!stream,'is-danger':stream}">
              <span class="icon">
                <i class="fas fa-stream"></i>
              </span>
            </button>
          </p>

          <p class="control">
            <button class="button is-warning" @click.prevent="wrapLines = !wrapLines" v-tooltip="'Toggle wrap line'">
              <span class="icon">
                <i class="fas fa-text-width"></i>
              </span>
            </button>
          </p>

          <p class="control">
            <button class="button is-primary" @click.prevent="loadContent">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
    </div>

    <div class="column is-12">
      <template v-if="stream">
        <Message message_class="is-info">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
            <span>Streaming log content...</span>
          </span>
        </Message>
      </template>
      <code ref="logContainer" class="box logs-container" v-if="!error" :class="{'is-pre': !wrapLines}">
        <span class="is-block" v-for="(item, index) in data" :key="'log_line-'+index">
          {{ item }}
        </span>
      </code>
      <template v-else>
        <Message title="Request Error" message_class="is-danger" :message="error"/>
      </template>
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

import Message from "~/components/Message.vue";
import moment from "moment";
import {useStorage} from "@vueuse/core";

const filename = useRoute().params.filename
const data = ref([])
const error = ref('')
const wrapLines = ref(true)

const api_path = useStorage('api_path', '/v1/api')
const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')

/** @type {Ref<EventSource|null>} */
const stream = ref(null)

/** @type {Ref<HTMLPreElement|null>} */
const logContainer = ref(null)

const loadContent = async () => {
  try {
    const response = await request(`/log/${filename}`)
    if (response.ok) {
      const text = await response.text()
      data.value = text.split('\n')
    } else {
      error.value = `${response.status}: ${response.statusText}`
    }
  } catch (e) {
    error.value = e
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
  stream.value.addEventListener('data', (event) => {
    data.value.push(event.data)
  });
}

const closeStream = () => {
  if (stream.value) {
    stream.value.close()
    stream.value = null
  }
}

const updateScroll = () => logContainer.value.scrollTop = logContainer.value.scrollHeight;

onUpdated(() => updateScroll());
</script>
