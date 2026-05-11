<template>
  <UPopover
    :mode="popoverMode"
    :open-delay="150"
    :close-delay="150"
    :arrow="false"
    :content="{ side: 'top', sideOffset: 10, collisionPadding: 8 }"
    :ui="{ content: 'w-auto rounded-md border border-default bg-default/95 p-2 shadow-xl' }"
    @update:open="handleOpenChange"
  >
    <slot />

    <template #content>
      <div class="flex min-h-12 min-w-12 items-center justify-center">
        <UIcon
          v-if="isPreloading && !url"
          name="i-lucide-loader-circle"
          class="size-5 animate-spin text-info"
        />

        <img
          v-else-if="url"
          :src="url"
          class="poster-image max-w-full rounded-md border border-default bg-elevated/60 shadow-sm"
          :alt="title"
          @error="clearCache"
          :crossorigin="privacy ? 'anonymous' : 'use-credentials'"
          :referrerpolicy="privacy ? 'no-referrer' : 'origin'"
        />
      </div>
    </template>
  </UPopover>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useBreakpoints } from '@vueuse/core';
import { awaiter, notification, request } from '~/utils';
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
}>();

const cache = useSessionCache();
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });

const url = ref<string | undefined>();
const error = ref<boolean>(false);
const isPreloading = ref<boolean>(false);
let cancelRequest = new AbortController();

const popoverMode = computed<'click' | 'hover'>(() =>
  'mobile' === breakpoints.active().value ? 'click' : 'hover',
);

const isAborted = (error: unknown, controller: AbortController): boolean => {
  if (true === controller.signal.aborted) {
    return true;
  }

  if ('not_needed' === error) {
    return true;
  }

  return error instanceof Error && 'AbortError' === error.name;
};

const defaultLoader = async (): Promise<void> => {
  const controller = new AbortController();

  try {
    if (props.image && cache.has(props.image)) {
      url.value = cache.get(props.image) as string;
      return;
    }

    if (!props.image || isPreloading.value) {
      return;
    }

    cancelRequest = controller;
    isPreloading.value = true;

    const cb = props.image.startsWith('/') ? request : fetch;
    const response = await cb(props.image, { signal: controller.signal });

    if (!response.ok) {
      return;
    }

    const objUrl = URL.createObjectURL(await response.blob());
    cache.set(props.image, objUrl);
    url.value = objUrl;
  } catch (e: unknown) {
    if (true === isAborted(e, controller)) {
      return;
    }

    console.error(e);
    notification('error', 'Error', `ImageView Request failure. ${String(e)}`);
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
  error.value = true;

  if (props.image) {
    cache.remove(props.image);
  }

  url.value = undefined;
  await loadContent();
  error.value = false;
};

const handleOpenChange = async (value: boolean) => {
  if (value) {
    await loadContent();
    return;
  }

  await stopTimer();
};

const { privacy, title } = props;
</script>

<style scoped>
.poster-image {
  width: 100%;
  height: auto;
  max-width: 180px;
  max-height: 270px;
  display: block;
  margin: 0 auto;
}

@media screen and (max-width: 1024px) {
  .poster-image {
    max-width: 120px;
    max-height: 180px;
  }
}
</style>
