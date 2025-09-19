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
          <div class="field is-grouped" v-if="isPlaying">
            <div class="control">
              <button class="button is-warning" @click="closeStream" v-tooltip.bottom="'Go back.'">
                <span class="icon"><i class="fas fa-backspace"></i></span>
              </button>
            </div>
            <p class="control">
              <button class="button" @click="toggleWatched"
                :class="{ 'is-success': !item.watched, 'is-danger': item.watched }"
                v-tooltip.bottom="'Toggle watch state'">
                <span class="icon">
                  <i class="fas" :class="{ 'fa-eye-slash': item.watched, 'fa-eye': !item.watched }"></i>
                </span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle" v-if="item?.content_title">
            {{ item?.content_title }}
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="!isPlaying">
        <Message v-if="isLoading" message_class="is-background-info-90 has-text-dark" icon="fas fa-spinner fa-spin"
          title="Loading" message="Loading data. Please wait..." />

        <Message v-if="!isLoading && (item?.files?.length ?? 0) < 1" title="Warning"
          message_class="is-background-warning-80 has-text-dark" icon="fas fa-exclamation-triangle">
          No video URLs were found.
        </Message>
      </div>
    </div>

    <div class="columns is-multiline">

      <div class="column is-12" v-if="isPlaying">
        <Player :link="playUrl" />
      </div>

      <div class="column is-12" v-if="!isPlaying">
        <div class="card">
          <div class="card-header">
            <p class="card-header-title">Select settings.</p>
            <p class="card-header-icon"></p>
          </div>

          <div class="card-content">
            <div class="field">
              <label class="label">Select source file</label>
              <div class="control has-icons-left">
                <div class="select is-fullwidth">
                  <select v-model="config.path" @change="(e) => changeStream(e)">
                    <option value="">Select...</option>
                    <template v-for="item in item?.files" :key="item.path">
                      <optgroup :label="`In: ${item.source.join(', ')}`">
                        <option :value="item.path" v-text="basename(item.path)" />
                      </optgroup>
                    </template>
                  </select>
                </div>
                <div class="icon is-left">
                  <i class="fas fa-file-video"></i>
                </div>
              </div>
            </div>

            <div class="field" v-if="selectedItem?.ffprobe?.streams">
              <label class="label">Select audio stream</label>
              <div class="control has-icons-left">
                <div class="select is-fullwidth">
                  <select v-model="config.audio">
                    <option value="">Select audio stream...</option>
                    <template v-for="item in filterStreams('audio')" :key="`audio-${item.index}`">
                      <option :value="item.index">
                        {{ item.index }} - {{ String(item.codec_name).toUpperCase() }}
                        <template v-if="ag(item.tags || {}, 'title')">
                          - {{ ucFirst(String(ag(item.tags || {}, 'title'))) }}
                        </template>
                        <template v-if="ag(item.tags || {}, 'language')">
                          - ({{ String(ag(item.tags || {}, 'language')).toUpperCase() }})
                        </template>
                      </option>
                    </template>
                  </select>
                </div>
                <div class="icon is-left">
                  <i class="fas fa-file-audio"></i>
                </div>
              </div>
            </div>

            <div class="field" v-if="filterStreams('subtitle').length > 0 || selectedItem?.subtitles?.length > 0">
              <label class="label">Burn subtitles</label>
              <div class="control has-icons-left">
                <div class="select is-fullwidth">
                  <select v-model="config.subtitle">
                    <option value="">Select subtitle...</option>
                    <template v-if="filterStreams('subtitle').length > 0">
                      <optgroup label="Internal Subtitles">
                        <option v-for="item in filterStreams('subtitle')" :key="`subtitle-${item.index}`"
                          :value="item.index">
                          {{ item.index }} - {{ String(item.codec_name).toUpperCase() }}
                          <template v-if="ag(item.tags || {}, 'title')">
                            - {{ ucFirst(String(ag(item.tags || {}, 'title'))) }}
                          </template>
                          <template v-if="ag(item.tags || {}, 'language')">
                            - ({{ String(ag(item.tags || {}, 'language')).toUpperCase() }})
                          </template>
                        </option>
                      </optgroup>
                    </template>
                    <template v-if="selectedItem?.subtitles.length > 0">
                      <optgroup label="External Subtitles">
                        <option v-for="item in selectedItem.subtitles" :key="`subtitle-${item}`" :value="item">
                          {{ basename(item) }}
                        </option>
                      </optgroup>
                    </template>
                  </select>
                </div>
                <div class="icon is-left">
                  <i class="fas fa-closed-captioning"></i>
                </div>
              </div>
              <p class="help">
                <span class="icon"><i class="fas fa-info"></i></span>
                We recommend using the burn subtitle function only when you are using a picture based subtitles,
                Text based subtitles are able to be selected and converted on the fly using the player. We plan to
                support direct play of compatible streams in the future.
              </p>
            </div>

            <template v-if="showAdvanced">
              <div class="field">
                <label class="label">Video transcoding codec.</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="video_codec" @change="e => updateHwAccel((e.target as HTMLSelectElement)?.value)">
                      <option value="" disabled>Select codec...</option>
                      <option v-for="item in item.hardware?.codecs" :key="`codec-${item.codec}`" :value="item.codec"
                        v-text="item.name" />
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-closed-captioning"></i>
                  </div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  We don't do pre-checks on codecs, so some of those codecs may not work or you don't have the hardware
                  for it. the standard <code>H264 (CPU)</code> is the default and should work on most systems.
                </p>
              </div>

              <div class="field" v-if="'h264_vaapi' === config.video_codec">
                <label class="label">Select VAAPI rendering device</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="vaapi_device">
                      <option value="" disabled>Select device...</option>
                      <option v-for="item in item.hardware?.devices" :key="`codec-${item}`" :value="item"
                        v-text="basename(item)" />
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-closed-captioning"></i>
                  </div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  We don't do pre-checks on codecs, so some of those codecs may not work or you don't have the hardware
                  for it. the standard <code>H264 (CPU)</code> is the default and should work on most systems.
                </p>
              </div>

              <div class="field">
                <label class="label" for="debug">Include debug information in response headers</label>
                <div class="control">
                  <input id="debug" type="checkbox" class="switch is-success" v-model="session_debug">
                  <label for="debug">Enable</label>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  Useful to know what options and ffmpeg command being run.
                </p>
              </div>
            </template>

            <div class="is-justify-content-end field is-grouped" v-if="config?.path">
              <div class="control">
                <button class="button is-warning" @click="showAdvanced = !showAdvanced">
                  <span class="icon"><i class="fas fa-cog"></i></span>
                  <span>Advanced settings</span>
                </button>
              </div>

              <div class="control">
                <button class="button has-text-white has-background-danger-50" @click="generateToken"
                  :disabled="isGenerating">
                  <span class="icon"><i class="fas fa-play"></i></span>
                  <span>Play</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="!isPlaying">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
          @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>Selecting subtitle for burn in will force the video stream to be converted. We attempt to direct play
              compatible streams when possible. Text based subtitles can be converted on the fly in the player. and
              require no burn in.
            </li>
            <li>
              Right now the transcoding is done via CPU and is not optimized for best performance. We have plans to
              include GPU acceleration in the future.
            </li>
            <li>If you select subtitle for burn in the player will no longer show text based subtitles for selection.
            </li>
            <li>Right now we are transcoding all streams to <code>H264</code> for video and <code>AAC</code> for audio,
              regardless of the stream is compatible with the browser or not, this will hopefully change in the feature
              to allow direct play of compatible streams. we have the code in place to allow such thing, i just haven't
              be able to get reliable results with it yet.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter, navigateTo } from '#app'
import { useStorage } from '@vueuse/core'
import Message from '~/components/Message.vue'
import Player from '~/components/Player.vue'
import { request, basename, makeName, notification, ag, ucFirst } from '~/utils'
import type { PlayableItem, FFProbeStream, MediaFile } from '~/types'

const route = useRoute()

const id = route.params.id as string
const item = ref<PlayableItem>({} as PlayableItem)
const isLoading = ref<boolean>(false)
const isPlaying = ref<boolean>(false)
const isGenerating = ref<boolean>(false)
const playUrl = ref<string>('')
const showAdvanced = useStorage('play_showAdvanced', false)
const show_page_tips = useStorage('show_page_tips', true)
const video_codec = useStorage('play_vcodec', 'libx264')
const vaapi_device = useStorage('play_vaapi_device', '')
const session_debug = useStorage('play_debug', false)

const config = ref<{
  /** Selected file path */
  path: string
  /** Selected audio stream index */
  audio: string | number
  /** Selected subtitle stream index or external subtitle path */
  subtitle: string | number
  /** Video codec to use */
  video_codec: string
  /** VAAPI device path */
  vaapi_device: string
  /** Whether to use hardware acceleration */
  hwaccel: boolean
  /** Whether to include debug information */
  debug: boolean
}>({
  path: '',
  audio: '',
  subtitle: '',
  video_codec: video_codec.value,
  vaapi_device: vaapi_device.value,
  hwaccel: false,
  debug: session_debug.value,
})

const selectedItem = ref<MediaFile>({} as MediaFile)

const loadContent = async (): Promise<void> => {
  isLoading.value = true
  try {
    const response = await request(`/history/${id}?files=true`)
    item.value = await response.json() as PlayableItem
  } catch (error) {
    console.error(error)
    notification('error', 'Error', 'Failed to load item.')
  } finally {
    isLoading.value = false
  }

  if (1 === item.value.files?.length) {
    const firstFile = item.value.files[0]
    if (firstFile) {
      config.value.path = firstFile.path
      selectedItem.value = firstFile
      await changeStream(null, firstFile.path)
    }
  }
}

const generateToken = async (): Promise<void> => {
  isGenerating.value = true
  try {
    const userConfig: {
      path: string
      config: {
        audio: string | number
        video_codec: string
        hwaccel: boolean
        debug: boolean
        vaapi_device?: string
        subtitle?: string | number
        external?: string | number
      }
    } = {
      path: config.value.path,
      config: {
        audio: config.value.audio,
        video_codec: config.value.video_codec,
        hwaccel: config.value.hwaccel,
        debug: Boolean(config.value.debug),
      }
    }

    if (config.value.vaapi_device && 'h264_vaapi' === config.value.video_codec) {
      userConfig.config.vaapi_device = config.value.vaapi_device
    }

    if (config.value.subtitle) {
      // -- check if the value is number it's internal subtitle
      if (String(config.value.subtitle).match(/^\d+$/)) {
        userConfig.config.subtitle = config.value.subtitle
      } else {
        userConfig.config.external = config.value.subtitle
      }
    }

    const response = await request(`/system/sign/${id}`, {
      method: 'POST',
      body: JSON.stringify(userConfig),
    })

    const json = await parse_api_response<{ token: string }>(response)

    if ('error' in json) {
      notification('error', 'Token generation', 'Failed to generate token.')
      return
    }

    playUrl.value = `/v1/api/player/playlist/${json.token}/master.m3u8`
    isPlaying.value = true

    await navigateTo({
      path: `/play/${id}`,
      query: { token: json.token }
    })
  } catch (error) {
    console.error(error)
    notification('error', 'Error', 'Failed to generate token.')
  } finally {
    isGenerating.value = false
  }
}

const changeStream = async (e: Event | null, path: string | null = null): Promise<void> => {
  if (!path) {
    const target = e?.target as HTMLSelectElement
    path = target?.value
  }
  if (!path) {
    selectedItem.value = {} as MediaFile
    return
  }

  selectedItem.value = item.value.files?.find(item => item.path === path) || {} as MediaFile
  filterStreams(['subtitle', 'audio']).forEach(s => {
    if (1 === parseInt(ag(s, 'disposition.default', 0))) {
      config.value['audio' === s.codec_type ? 'audio' : 'subtitle'] = s.index
    }
  })
}

const filterStreams = (type?: string | Array<string>): Array<FFProbeStream> => {
  if (!selectedItem?.value || !selectedItem.value?.ffprobe?.streams) {
    return []
  }

  if (!type) {
    return selectedItem.value?.ffprobe?.streams
  }

  if ('string' === typeof type) {
    type = [type]
  }

  return selectedItem.value?.ffprobe?.streams.filter(s => type.includes(s.codec_type))
}

const closeStream = async (): Promise<void> => {
  isPlaying.value = false
  playUrl.value = ''
  await navigateTo({ path: `/history/${id}` })
}

const toggleWatched = async (): Promise<void> => {
  if (!item.value) {
    return
  }
  if (!confirm(`Mark '${makeName(item.value)}' as ${item.value.watched ? 'unplayed' : 'played'}?`)) {
    return
  }
  try {
    const response = await request(`/history/${item.value.id}/watch`, {
      method: item.value.watched ? 'DELETE' : 'POST'
    })

    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    item.value.watched = !item.value.watched
    notification('success', '', `Marked '${makeName(item.value)}' as ${item.value.watched ? 'played' : 'unplayed'}`)
  } catch (e) {
    notification('error', 'Error', `Request error. ${e}`)
  }
}

const onPopState = (): void => {
  if (route.query?.token) {
    playUrl.value = `${useStorage('api_url', '').value}${useStorage('api_path', '/v1/api').value}/player/playlist/${route.query.token}/master.m3u8`
    isPlaying.value = true
  } else {
    isPlaying.value = false
    playUrl.value = ''
  }
}

const updateHwAccel = (codec: string): void => {
  const codecInfo = item.value.hardware?.codecs?.filter(c => c.codec === codec)
  if (!codecInfo || codecInfo.length < 1) {
    config.value.hwaccel = false
    return
  }
  config.value.hwaccel = Boolean(codecInfo[0]?.hwaccel)
}

onMounted(async () => {
  window.addEventListener('popstate', onPopState)
  await loadContent()
  if (route.query?.token) {
    playUrl.value = `${useStorage('api_url', '').value}${useStorage('api_path', '/v1/api').value}/player/playlist/${route.query.token}/master.m3u8`
    isPlaying.value = true
  }
  updateHwAccel(video_codec.value)
})

onUnmounted(() => window.removeEventListener('popstate', onPopState))
</script>
