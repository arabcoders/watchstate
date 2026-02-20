<template>
  <div ref="targetEl" :style="`min-height:${fixedMinHeight ? fixedMinHeight : minHeight}px`">
    <slot v-if="shouldRender" />
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick, onBeforeUnmount } from 'vue';
import { useIntersectionObserver } from '@vueuse/core';

const props = defineProps<{
  /** If true, render content on browser idle */
  renderOnIdle?: boolean;
  /** If true, unrender content when out of view */
  unrender?: boolean;
  /** Minimum height of the container (px) */
  minHeight?: number;
  /** Delay before unrendering (ms) */
  unrenderDelay?: number;
}>();

const shouldRender = ref<boolean>(false);
const targetEl = ref<HTMLElement | null>(null);
const fixedMinHeight = ref<number>(0);
let unrenderTimer: ReturnType<typeof setTimeout> | undefined;
let renderTimer: ReturnType<typeof setTimeout> | undefined;

function onIdle(cb: () => void = () => {}): void {
  if ('requestIdleCallback' in window) {
    (window as any).requestIdleCallback(cb);
  } else {
    setTimeout(() => nextTick(cb), 300);
  }
}

const { stop } = useIntersectionObserver(
  targetEl,
  (entries) => {
    const entry = entries[0];
    if (!entry) {
      return;
    }
    if (entry.isIntersecting) {
      if (unrenderTimer) {
        clearTimeout(unrenderTimer);
      }
      renderTimer = setTimeout(
        () => {
          shouldRender.value = true;
        },
        props.unrender ? 200 : 0,
      );
      shouldRender.value = true;
      if (!props.unrender) {
        stop();
      }
    } else if (props.unrender) {
      if (renderTimer) {
        clearTimeout(renderTimer);
      }
      unrenderTimer = setTimeout(() => {
        if (targetEl.value?.clientHeight) {
          fixedMinHeight.value = targetEl.value.clientHeight;
        }
        shouldRender.value = false;
      }, props.unrenderDelay ?? 6000);
    }
  },
  {
    rootMargin: '600px',
  },
);

if (props.renderOnIdle) {
  onIdle(() => {
    shouldRender.value = true;
    if (!props.unrender) {
      stop();
    }
  });
}

onBeforeUnmount(() => {
  if (unrenderTimer) {
    clearTimeout(unrenderTimer);
  }
  if (renderTimer) {
    clearTimeout(renderTimer);
  }
});
</script>
