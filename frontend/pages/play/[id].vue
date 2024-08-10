<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-play"></i></span>
          Play :
          <template v-if="item">{{ makeName(item) }}</template>
          <template v-else>{{ id }}</template>
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle" v-if="!isPlaying && urls.length > 0">
            Select video file to play.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="!isPlaying">
        <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark" icon="fas fa-spinner fa-spin"
                 title="Loading" message="Loading data. Please wait..."/>

        <Message v-if="!isLoading && urls.length < 1" title="Warning"
                 message_class="is-background-warning-80 has-text-dark"
                 icon="fas fa-exclamation-triangle">
          No video URLs were found.
        </Message>
      </div>
    </div>

    <div class="columns is-multiline">

      <div class="column is-12" v-if="isPlaying">
        <Player :link="playUrl"/>
      </div>

      <div class="column is-12" v-if="!isPlaying">
        <div class="field is-grouped">
          <div class="control">
            <div class="select">
              <select v-model="selectedUrl">
                <option value="">Select video file</option>
                <template v-for="item in urls" :key="item.source">
                  <optgroup :label="item.source">
                    <option :value="item.url" v-text="basename(item.url)"/>
                  </optgroup>
                </template>
              </select>
            </div>
          </div>
          <div class="control">
            <button type="button" class="button is-primary" @click="generateToken" :disabled="'' === selectedUrl"
                    :class="{'is-loading':isGenerating}">
              <span class="icon"><i class="fas fa-play"></i></span>
              <span>Play</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

</template>

<script setup>
import Message from '~/components/Message'
import {basename, makeName, notification} from '~/utils/index'
import {useStorage} from "@vueuse/core";
import Player from "~/components/Player.vue";

const route = useRoute()

const id = route.params.id
const item = ref({})
const urls = ref([])
const isLoading = ref(false)
const isPlaying = ref(false)
const isGenerating = ref(false)
const selectedUrl = ref('')
const playUrl = ref('')

const loadContent = async () => {
  isLoading.value = true
  try {
    const response = await request(`/history/${id}`)
    const json = await response.json()
    item.value = json
    let vUrls = [];
    for (const key in json.metadata) {
      if ('path' in json.metadata[key]) {
        const url = json.metadata[key]['path']
        const index = vUrls.findIndex((v) => v.url === url)

        if (index === -1) {
          vUrls.push({source: key, url: url})
        } else {
          vUrls[index].source = `${vUrls[index].source}, ${key}`
        }
      }
    }

    if (1 === vUrls.length) {
      selectedUrl.value = vUrls[0].url
      await generateToken()
      return
    }

    urls.value = vUrls;
  } catch (error) {
    console.error(error)
    notification('error', 'Error', 'Failed to load item.')
  } finally {
    isLoading.value = false
  }
}

const generateToken = async () => {
  isGenerating.value = true
  try {
    const response = await request(`/system/sign/${id}`, {
      method: 'POST',
      body: JSON.stringify({path: selectedUrl.value}),
    })

    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Token generation', 'Failed to generate token.')
      return;
    }

    const api_path = useStorage('api_path', '/v1/api').value
    const api_url = useStorage('api_url', '').value

    let url = `${api_url}${api_path}/player/playlist/${json.token}/master.m3u8`
    if (true === json?.secure) {
      url = `${url}?apikey=${useStorage('api_token', '').value}`
    }
    playUrl.value = url
    isPlaying.value = true

  } catch (error) {
    console.error(error)
    notification('error', 'Error', 'Failed to generate token.')
  } finally {
    isGenerating.value = false
  }
}
onMounted(async () => await loadContent())
</script>
