<!--suppress CssUnusedSymbol, CssInvalidPseudoSelector -->
<style>
:root {
  --plyr-captions-background: rgba(0, 0, 0, 0.6);
  --plyr-captions-text-color: #f3db4d;
  --webkit-text-track-display: none;
}

.plyr__caption {
  text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.7);
  font-size: 140%;
  font-weight: bold;
}

.plyr--full-ui ::-webkit-media-text-track-container {
  display: var(--webkit-text-track-display);
}
</style>

<template>
  <video ref="video" :poster="poster" :controls="isControls" :title="title" preload="auto">
    <source :src="link" type="application/x-mpegURL"/>
  </video>
</template>

<script setup>
import Hls from 'hls.js'
import 'plyr/dist/plyr.css'
import {notification} from '~/utils/index'
import request from '~/utils/request'
import Plyr from 'plyr'

const props = defineProps({
  link: {
    type: String,
    required: true,
  },
  title: {
    type: String,
    required: false,
  },
  poster: {
    type: String,
    required: false,
  },
  isControls: {
    type: Boolean,
    default: true
  },
  debug: {
    type: Boolean,
    default: false
  },
  reference: {},
})

const video = ref(null)
/** @type {Plyr} */
let player;
/** @type {Hls} */
let hls;
const poster = ref()

const destroyPlayer = () => {
  console.debug('Destroying video player');
  if (player) {
    player.destroy()
  }
  if (hls) {
    hls.destroy()
  }
}

onMounted(() => {
  if (/(iPhone|iPod|iPad).*AppleWebKit/i.test(navigator.userAgent)) {
    document.documentElement.style.setProperty('--webkit-text-track-display', 'block');
  }
  Promise.all([getPoster(), prepareVideoPlayer()])
})
onUpdated(() => prepareVideoPlayer())
onUnmounted(() => destroyPlayer())

const getPoster = async () => {
  if (props.poster) {
    const cb = props.poster.startsWith('/') ? request : fetch;
    const response = await cb(props.poster)

    if (200 === response.status) {
      poster.value = URL.createObjectURL(await response.blob());
    }
  }
}

const prepareVideoPlayer = async () => {
  player = new Plyr(video.value, {
    debug: props.debug,
    clickToPlay: true,
    autoplay: true,
    controls: [
      'play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'captions', 'settings', 'pip', 'airplay', 'fullscreen'
    ],
    keyboard: {focused: true, global: true},
    fullscreen: {
      enabled: true,
      fallback: true,
      iosNative: true,
    },
    storage: {
      enabled: true,
      key: 'plyr'
    },

    mediaMetadata: {
      title: props.title
    },
    captions: {active: true, update: true, language: 'auto'},
  });

  hls = new Hls({
    debug: props.debug,
    enableWorker: true,
    lowLatencyMode: true,
    backBufferLength: 98,
    fragLoadingTimeOut: 100000,
  });

  hls.on(Hls.Events.ERROR, (_, data) => {
    console.warn(data);
    notification('warning', 'HLS.js', `HLs Error: ${data.error ?? 'Unknown error'}`);
  });

  hls.loadSource(props.link)

  if (video.value) {
    hls.attachMedia(video.value)
  }
}
</script>
