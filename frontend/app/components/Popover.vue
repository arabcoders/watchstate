<template>
  <template v-if="disabled">
    <slot name="trigger" :toggle="toggle" :show="show" :hide="hide" />
  </template>

  <UPopover
    v-else
    v-model:open="isOpen"
    :mode="popoverMode"
    :open-delay="showDelay"
    :close-delay="hideDelay"
    :arrow="showArrow"
    :dismissible="activeTrigger !== 'hover'"
    :content="popoverContent"
    :ui="popoverUi"
  >
    <template #default>
      <slot name="trigger" :toggle="toggle" :show="show" :hide="hide" />
    </template>

    <template #content>
      <div :class="contentClass">
        <slot name="content" :hide="hide" />
      </div>
    </template>
  </UPopover>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useBreakpoints } from '@vueuse/core';

export interface PopoverProps {
  /** Placement of the popover relative to trigger */
  placement?:
    | 'top'
    | 'bottom'
    | 'left'
    | 'right'
    | 'top-start'
    | 'top-end'
    | 'bottom-start'
    | 'bottom-end';
  /** Trigger method */
  trigger?: 'click' | 'hover' | 'manual';
  /** Delay before showing (ms) */
  showDelay?: number;
  /** Delay before hiding (ms) */
  hideDelay?: number;
  /** Offset from trigger element */
  offset?: number;
  /** Whether to show arrow pointing to trigger */
  showArrow?: boolean;
  /** Additional CSS classes for popover */
  popoverClass?: string;
  /** Additional CSS classes for content */
  contentClass?: string;
  /** Z-index for the popover */
  zIndex?: number;
  /** Whether popover is disabled */
  disabled?: boolean;
}

const props = withDefaults(defineProps<PopoverProps>(), {
  placement: 'bottom',
  trigger: 'hover',
  showDelay: 0,
  hideDelay: 200,
  offset: 8,
  showArrow: true,
  popoverClass: '',
  contentClass: '',
  zIndex: 9999,
  disabled: false,
});

const emit = defineEmits<{ (e: 'show' | 'hide'): void }>();

const isOpen = ref(false);
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });

const resolvedTrigger = computed<'click' | 'hover' | 'manual'>(() => {
  if ('hover' !== props.trigger) {
    return props.trigger;
  }

  return 'mobile' === breakpoints.active().value ? 'click' : 'hover';
});

const popoverMode = computed(() => ('hover' === resolvedTrigger.value ? 'hover' : 'click'));

const placementInfo = computed(() => {
  const [side, align] = props.placement.split('-') as [
    'top' | 'bottom' | 'left' | 'right',
    'start' | 'end' | undefined,
  ];

  return {
    side,
    align: (align ?? 'center') as 'start' | 'center' | 'end',
  };
});

const popoverContent = computed(() => ({
  side: placementInfo.value.side,
  align: placementInfo.value.align,
  sideOffset: props.offset,
  collisionPadding: 8,
}));

const popoverUi = computed(() => ({
  content: [`z-[${props.zIndex}]`, props.popoverClass].filter(Boolean).join(' '),
}));

const contentClass = computed(() => props.contentClass);

const activeTrigger = resolvedTrigger;

const show = () => {
  if (props.disabled) {
    return;
  }

  isOpen.value = true;
};

const hide = () => {
  isOpen.value = false;
};

const toggle = () => {
  if (props.disabled) {
    return;
  }

  isOpen.value = !isOpen.value;
};

watch(isOpen, (value) => emit(value ? 'show' : 'hide'));
</script>
