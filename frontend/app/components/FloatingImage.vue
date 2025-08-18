<template>
  <vTooltip @show="loadContent" @hide="stopTimer">
    <slot/>
    <template #popper>
      <span class="icon" v-if="!url"><i class="fas fa-circle-notch fa-spin"></i></span>
      <template v-else>
        <img :src="url" class="card-image" :class="item_class"
             :alt="props.title"
             @error="clearCache"
             :crossorigin="props.privacy ? 'anonymous': 'use-credentials'"
             :referrerpolicy="props.privacy ? 'no-referrer': 'origin'"/>
      </template>
    </template>
  </vTooltip>
</template>

<script setup>
import {notification} from '~/utils/index'
import awaiter from '~/utils/awaiter'
import {useSessionCache} from '~/utils/cache'
import request from '~/utils/request'

const props = defineProps({
  image: {
    type: String,
    required: false,
  },
  title: {
    type: String,
    required: false
  },
  loader: {
    Type: Function,
    required: false,
  },
  privacy: {
    type: Boolean,
    required: false,
    default: true
  },
  item_class: {
    type: String,
    required: false,
    default: '',
  }
});

const cache = useSessionCache()

const url = ref()
const error = ref(false)
const isPreloading = ref(false)

let loadTimer = null;
const cancelRequest = new AbortController();

const defaultLoader = async () => {
  try {
    if (cache.has(props.image)) {
      url.value = cache.get(props.image)
      return
    }

    const cb = props.image.startsWith('/') ? request : fetch;
    const response = await cb(props.image, {
      signal: cancelRequest.signal
    })

    if (!response.ok) {
      return;
    }

    const objUrl = URL.createObjectURL(await response.blob());

    cache.set(props.image, objUrl)

    url.value = objUrl
  } catch (e) {
    if ('not_needed' === e) {
      return
    }
    console.error(e)
    notification('error', 'Error', `ImageView Request failure. ${e}`)
  } finally {
    isPreloading.value = false
  }
}

const stopTimer = async () => {
  if (error.value) {
    return
  }

  if (url.value) {
    isPreloading.value = false
    url.value = null;
    return;
  }

  await awaiter(() => isPreloading.value)
  clearTimeout(loadTimer)
  isPreloading.value = false
  url.value = null;
  cancelRequest.abort('not_needed')
}

const loadContent = async () => {
  if (props.loader) {
    return props.loader()
  }

  return defaultLoader()
}

const clearCache = async () => {
  cache.remove(props.image)
  url.value = '';
  return loadContent()
}
</script>
