<template>
  <div
    ref="target"
    class="relative aspect-2/3 overflow-hidden rounded-md bg-elevated ring-1 ring-inset ring-default"
  >
    <img
      v-if="url"
      :src="url"
      :alt="title"
      loading="lazy"
      decoding="async"
      class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
      @error="imageError"
    />
    <div
      v-else
      class="flex h-full flex-col items-center justify-center gap-2 p-3 text-center text-dimmed"
    >
      <UIcon
        :name="isLoading ? 'i-lucide-loader-circle' : 'i-lucide-image-off'"
        :class="['size-7', isLoading ? 'animate-spin' : '']"
      />
      <span class="text-xs">{{ isLoading ? 'Loading poster' : 'No poster' }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useIntersectionObserver } from '@vueuse/core';
import { UIcon } from '#components';
import { request } from '~/utils';
import { useSessionCache } from '~/utils/cache';

const props = defineProps<{ historyId: number; title: string; user: string }>();
const target = ref<HTMLElement | null>(null);
const url = ref<string | null>(null);
const isLoading = ref(false);
const cache = useSessionCache(`artwork:${props.user}`);
const path = `/history/${props.historyId}/images/poster`;
const controller = new AbortController();
let fromCache = false;

const load = async (): Promise<void> => {
  isLoading.value = true;

  try {
    const cached = cache.get<string>(path);
    if (cached) {
      fromCache = true;
      url.value = cached;
      return;
    }

    const response = await request(path, {
      headers: { 'X-User': props.user },
      signal: controller.signal,
    });
    if (false === response.ok) {
      return;
    }

    const blob = await response.blob();
    if (controller.signal.aborted) {
      return;
    }
    url.value = URL.createObjectURL(blob);
    cache.set(path, url.value);
  } catch (cause: unknown) {
    if (false === controller.signal.aborted) {
      console.debug('Poster unavailable', cause);
    }
  } finally {
    isLoading.value = false;
  }
};

const imageError = (): void => {
  url.value = null;
  cache.remove(path);
  if (fromCache) {
    fromCache = false;
    void load();
  }
};

const { stop } = useIntersectionObserver(
  target,
  (entries) => {
    if (entries.some((entry) => entry.isIntersecting)) {
      stop();
      void load();
    }
  },
  { rootMargin: '200px' },
);

onBeforeUnmount(() => controller.abort());
</script>
