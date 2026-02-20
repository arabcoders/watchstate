<template>
  <vTooltip @show="loadContent" @hide="stopTimer">
    <slot />
    <template #popper>
      <span class="icon" v-if="!url"><i class="fas fa-circle-notch fa-spin"></i></span>
      <template v-else>
        <img
          :src="url"
          class="card-image"
          :class="item_class"
          :alt="props.title"
          @error="clearCache"
          :crossorigin="props.privacy ? 'anonymous' : 'use-credentials'"
          :referrerpolicy="props.privacy ? 'no-referrer' : 'origin'"
        />
      </template>
    </template>
  </vTooltip>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { request, notification, awaiter } from '~/utils';
import { useSessionCache } from '~/utils/cache';

const props = defineProps<{
  /** Image URL to display */
  image?: string;
  /** Alt text for image */
  title?: string;
  /** Custom loader function */
  loader?: () => Promise<void>;
  /** If true, use anonymous CORS and no-referrer */
  privacy?: boolean;
  /** CSS class for the image */
  item_class?: string;
}>();

const cache = useSessionCache();

const url = ref<string | undefined>();
const error = ref<boolean>(false);
const isPreloading = ref<boolean>(false);

const loadTimer: ReturnType<typeof setTimeout> | null = null;
const cancelRequest = new AbortController();

const defaultLoader = async (): Promise<void> => {
  try {
    if (props.image && cache.has(props.image)) {
      url.value = cache.get(props.image) as string;
      return;
    }

    if (!props.image) {
      return;
    }

    const cb = props.image.startsWith('/') ? request : fetch;
    const response = await cb(props.image, { signal: cancelRequest.signal });

    if (!response.ok) {
      return;
    }

    const objUrl = URL.createObjectURL(await response.blob());
    cache.set(props.image, objUrl);
    url.value = objUrl;
  } catch (e: any) {
    if (e === 'not_needed') {
      return;
    }
    console.error(e);
    notification('error', 'Error', `ImageView Request failure. ${e}`);
  } finally {
    isPreloading.value = false;
  }
};

const stopTimer = async (): Promise<void> => {
  if (error.value) {
    return;
  }

  if (url.value) {
    isPreloading.value = false;
    url.value = undefined;
    return;
  }

  await awaiter(() => isPreloading.value);
  if (loadTimer) {
    clearTimeout(loadTimer);
  }
  isPreloading.value = false;
  url.value = undefined;
  cancelRequest.abort('not_needed');
};

const loadContent = async (): Promise<void> => {
  if (props.loader) {
    return props.loader();
  }
  return defaultLoader();
};

const clearCache = async (): Promise<void> => {
  if (props.image) {
    cache.remove(props.image);
  }
  url.value = undefined;
  return loadContent();
};
</script>
