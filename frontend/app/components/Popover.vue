<template>
  <div class="popover-trigger" ref="triggerRef">
    <slot name="trigger" :toggle="toggle" :show="show" :hide="hide" />
  </div>

  <Teleport to="body">
    <Transition name="popover-fade">
      <div v-if="isVisible" ref="popoverRef" class="popover-container" :class="popoverClasses" :style="popoverStyle"
        @click.stop>
        <div class="popover-content" :class="contentClasses">
          <slot name="content" :hide="hide" />
        </div>
        <div v-if="showArrow" class="popover-arrow" :style="arrowStyle" />
      </div>
    </Transition>
  </Teleport>

  <Teleport to="body">
    <div v-if="shouldShowBackdrop" class="popover-backdrop" @click="hide" />
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { usePopoverManager } from '~/composables/usePopoverManager'

export interface PopoverProps {
  /** Placement of the popover relative to trigger */
  placement?: 'top' | 'bottom' | 'left' | 'right' | 'top-start' | 'top-end' | 'bottom-start' | 'bottom-end'
  /** Trigger method */
  trigger?: 'click' | 'hover' | 'manual'
  /** Delay before showing (ms) */
  showDelay?: number
  /** Delay before hiding (ms) */
  hideDelay?: number
  /** Offset from trigger element */
  offset?: number
  /** Whether to show arrow pointing to trigger */
  showArrow?: boolean
  /** Additional CSS classes for popover */
  popoverClass?: string
  /** Additional CSS classes for content */
  contentClass?: string
  /** Z-index for the popover */
  zIndex?: number
  /** Whether popover is disabled */
  disabled?: boolean
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
  disabled: false
})

const emit = defineEmits<{ (e: 'show' | 'hide'): void }>()

const triggerRef = ref<HTMLElement>()
const popoverRef = ref<HTMLElement>()
const isVisible = ref(false)
const position = ref({ x: 0, y: 0 })
const arrowPosition = ref({ x: 0, y: 0 })

const popoverId = ref(`popover-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`)
const { register, unregister, closeAll, isActive } = usePopoverManager()

let showTimer: ReturnType<typeof setTimeout> | null = null
let hideTimer: ReturnType<typeof setTimeout> | null = null
let resizeObserver: ResizeObserver | null = null

type PopoverPlacement = 'top' | 'bottom' | 'left' | 'right' | 'top-start' | 'top-end' | 'bottom-start' | 'bottom-end'

const currentPlacement = ref<PopoverPlacement>(props.placement)

const popoverClasses = computed(() => [
  'popover',
  `popover--${currentPlacement.value}`,
  props.popoverClass
])

const contentClasses = computed(() => [
  'popover-content-inner',
  props.contentClass
])

const shouldShowBackdrop = computed(() => {
  return isVisible.value && props.trigger !== 'hover'
})

const popoverStyle = computed(() => ({
  left: `${position.value.x}px`,
  top: `${position.value.y}px`,
  zIndex: props.zIndex
}))

const arrowStyle = computed(() => ({
  left: `${arrowPosition.value.x}px`,
  top: `${arrowPosition.value.y}px`
}))

const fallbackPlacements: Record<PopoverPlacement, PopoverPlacement> = {
  top: 'bottom',
  'top-start': 'bottom-start',
  'top-end': 'bottom-end',
  bottom: 'top',
  'bottom-start': 'top-start',
  'bottom-end': 'top-end',
  left: 'right',
  right: 'left'
}

const computePositionFor = (
  placement: PopoverPlacement,
  triggerRect: DOMRect,
  popoverRect: DOMRect
): { x: number, y: number, arrowX: number, arrowY: number } => {
  let x = 0
  let y = 0
  let arrowX = 0
  let arrowY = 0

  switch (placement) {
    case 'top':
      x = triggerRect.left + triggerRect.width / 2 - popoverRect.width / 2
      y = triggerRect.top - popoverRect.height - props.offset
      arrowX = popoverRect.width / 2 - 6
      arrowY = popoverRect.height
      break
    case 'top-start':
      x = triggerRect.left
      y = triggerRect.top - popoverRect.height - props.offset
      arrowX = 16
      arrowY = popoverRect.height
      break
    case 'top-end':
      x = triggerRect.right - popoverRect.width
      y = triggerRect.top - popoverRect.height - props.offset
      arrowX = popoverRect.width - 22
      arrowY = popoverRect.height
      break
    case 'bottom':
      x = triggerRect.left + triggerRect.width / 2 - popoverRect.width / 2
      y = triggerRect.bottom + props.offset
      arrowX = popoverRect.width / 2 - 6
      arrowY = -6
      break
    case 'bottom-start':
      x = triggerRect.left
      y = triggerRect.bottom + props.offset
      arrowX = 16
      arrowY = -6
      break
    case 'bottom-end':
      x = triggerRect.right - popoverRect.width
      y = triggerRect.bottom + props.offset
      arrowX = popoverRect.width - 22
      arrowY = -6
      break
    case 'left':
      x = triggerRect.left - popoverRect.width - props.offset
      y = triggerRect.top + triggerRect.height / 2 - popoverRect.height / 2
      arrowX = popoverRect.width
      arrowY = popoverRect.height / 2 - 6
      break
    case 'right':
      x = triggerRect.right + props.offset
      y = triggerRect.top + triggerRect.height / 2 - popoverRect.height / 2
      arrowX = -6
      arrowY = popoverRect.height / 2 - 6
      break
  }

  return { x, y, arrowX, arrowY }
}

const fitsViewport = (
  placement: PopoverPlacement,
  coords: { x: number, y: number },
  popoverRect: DOMRect,
  viewport: { width: number, height: number }
): boolean => {
  switch (placement) {
    case 'top':
    case 'top-start':
    case 'top-end':
      return coords.y >= 8
    case 'bottom':
    case 'bottom-start':
    case 'bottom-end':
      return coords.y + popoverRect.height <= viewport.height - 8
    case 'left':
      return coords.x >= 8
    case 'right':
      return coords.x + popoverRect.width <= viewport.width - 8
  }
}

const calculatePosition = () => {
  if (!triggerRef.value || !popoverRef.value) {
    return
  }

  const triggerRect = triggerRef.value.getBoundingClientRect()
  const popoverRect = popoverRef.value.getBoundingClientRect()
  const viewport = {
    width: window.innerWidth,
    height: window.innerHeight
  }

  let placementToUse: PopoverPlacement = props.placement
  let coords = computePositionFor(placementToUse, triggerRect, popoverRect)

  if (!fitsViewport(placementToUse, coords, popoverRect, viewport)) {
    const fallback = fallbackPlacements[placementToUse]
    if (fallback) {
      const fallbackCoords = computePositionFor(fallback, triggerRect, popoverRect)
      if (fitsViewport(fallback, fallbackCoords, popoverRect, viewport)) {
        placementToUse = fallback
        coords = fallbackCoords
      }
    }
  }

  let { x, y, arrowX, arrowY } = coords

  if (x < 8) {
    arrowX += x - 8
    x = 8
  }
  if (x + popoverRect.width > viewport.width - 8) {
    const delta = (x + popoverRect.width) - (viewport.width - 8)
    arrowX -= delta
    x = viewport.width - popoverRect.width - 8
  }
  if (y < 8) {
    arrowY += y - 8
    y = 8
  }
  if (y + popoverRect.height > viewport.height - 8) {
    const delta = (y + popoverRect.height) - (viewport.height - 8)
    arrowY -= delta
    y = viewport.height - popoverRect.height - 8
  }

  const clamp = (value: number, min: number, max: number): number => {
    if (value < min) {
      return min
    }
    if (value > max) {
      return max
    }
    return value
  }

  if (['top', 'top-start', 'top-end', 'bottom', 'bottom-start', 'bottom-end'].includes(placementToUse)) {
    arrowX = clamp(arrowX, 12, popoverRect.width - 12)
  } else {
    arrowY = clamp(arrowY, 12, popoverRect.height - 12)
  }

  currentPlacement.value = placementToUse
  position.value = { x, y }
  arrowPosition.value = { x: arrowX, y: arrowY }
}

const cleanupResizeObserver = () => {
  if (resizeObserver !== null) {
    resizeObserver.disconnect()
    resizeObserver = null
  }
}

const setupResizeObserver = () => {
  cleanupResizeObserver()
  if (!popoverRef.value || typeof ResizeObserver === 'undefined') {
    return
  }

  resizeObserver = new ResizeObserver(() => {
    calculatePosition()
  })
  resizeObserver.observe(popoverRef.value)
}

const show = async () => {
  if (props.disabled) return

  currentPlacement.value = props.placement

  // Close any other active popovers first
  if (!register(popoverId.value)) {
    closeAll()
    await nextTick()
  }

  if (isVisible.value) return

  // Cancel any pending hide timer
  if (hideTimer !== null) {
    clearTimeout(hideTimer)
    hideTimer = null
  }

  // Clear any existing show timer
  if (showTimer !== null) {
    clearTimeout(showTimer)
    showTimer = null
  }

  if (props.showDelay > 0) {
    showTimer = setTimeout(async () => {
      if (!props.disabled) {
        isVisible.value = true
        emit('show')
        await nextTick()
        calculatePosition()
      }
      showTimer = null
    }, props.showDelay)
  } else {
    isVisible.value = true
    emit('show')
    await nextTick()
    calculatePosition()
  }
}

const hide = () => {
  if (!isVisible.value) return

  // Cancel any pending show timer
  if (showTimer !== null) {
    clearTimeout(showTimer)
    showTimer = null
  }

  // Always use a delay for hiding to allow moving between trigger and popover
  const actualHideDelay = Math.max(props.hideDelay, 50)

  if (hideTimer !== null) {
    clearTimeout(hideTimer)
  }

  hideTimer = setTimeout(() => {
    const isTriggerHovered = triggerRef.value?.matches(':hover') ?? false
    const isPopoverHovered = popoverRef.value?.matches(':hover') ?? false

    if (isTriggerHovered || isPopoverHovered) {
      hideTimer = null
      return
    }

    isVisible.value = false
    unregister(popoverId.value)
    emit('hide')
    hideTimer = null
  }, actualHideDelay)
}

const cancelHide = () => {
  if (hideTimer !== null) {
    clearTimeout(hideTimer)
    hideTimer = null
  }
}

const immediateHide = () => {
  if (showTimer !== null) {
    clearTimeout(showTimer)
    showTimer = null
  }
  if (hideTimer !== null) {
    clearTimeout(hideTimer)
    hideTimer = null
  }
  unregister(popoverId.value)
  cleanupResizeObserver()
  isVisible.value = false
  emit('hide')
}

const toggle = () => {
  if (isVisible.value) {
    hide()
  } else {
    show()
  }
}

const setupTriggerEvents = () => {
  if (!triggerRef.value) return

  const trigger = triggerRef.value

  if (props.trigger === 'hover') {
    trigger.addEventListener('mouseenter', show)
    trigger.addEventListener('mouseleave', hide)
  } else if (props.trigger === 'click') {
    trigger.addEventListener('click', toggle)
  }
}

const setupPopoverEvents = () => {
  if (!popoverRef.value || props.trigger !== 'hover') return

  const popover = popoverRef.value
  popover.addEventListener('mouseenter', cancelHide)
  popover.addEventListener('mouseleave', hide)
}

const cleanupTriggerEvents = () => {
  if (!triggerRef.value) return

  const trigger = triggerRef.value
  trigger.removeEventListener('mouseenter', show)
  trigger.removeEventListener('mouseleave', hide)
  trigger.removeEventListener('click', toggle)
}

const cleanupPopoverEvents = () => {
  if (!popoverRef.value) return

  const popover = popoverRef.value
  popover.removeEventListener('mouseenter', cancelHide)
  popover.removeEventListener('mouseleave', hide)
}

const handleEscape = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && isVisible.value) {
    hide()
  }
}

const handleReposition = () => {
  if (isVisible.value) {
    calculatePosition()
  }
}

watch(() => props.trigger, () => {
  cleanupTriggerEvents()
  setupTriggerEvents()
})

watch(() => props.placement, (newPlacement) => {
  currentPlacement.value = newPlacement
  if (isVisible.value) {
    nextTick(() => {
      calculatePosition()
    })
  }
})

watch(() => isVisible.value, (visible) => {
  if (visible) {
    nextTick(() => {
      setupPopoverEvents()
      setupResizeObserver()
      calculatePosition()
    })
  } else {
    cleanupPopoverEvents()
    cleanupResizeObserver()
    unregister(popoverId.value)
  }
})

watch(() => isActive(popoverId.value), (active) => {
  if (!active && isVisible.value) {
    immediateHide()
  }
})

onMounted(() => {
  setupTriggerEvents()
  window.addEventListener('keydown', handleEscape)
  window.addEventListener('scroll', handleReposition, true)
  window.addEventListener('resize', handleReposition)
})

onUnmounted(() => {
  cleanupTriggerEvents()
  unregister(popoverId.value)
  cleanupResizeObserver()
  if (showTimer !== null) {
    clearTimeout(showTimer)
    showTimer = null
  }
  if (hideTimer !== null) {
    clearTimeout(hideTimer)
    hideTimer = null
  }
  window.removeEventListener('keydown', handleEscape)
  window.removeEventListener('scroll', handleReposition, true)
  window.removeEventListener('resize', handleReposition)
})

defineExpose({ show, hide, toggle })
</script>

<style scoped>
.popover-trigger {
  display: inline-block;
}

.popover-backdrop {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 9998;
  background: transparent;
}

.popover-container {
  position: fixed;
  max-width: 90vw;
  max-height: 90vh;
  pointer-events: auto;
}

/* Create an invisible bridge for hover states */
.popover-container::before {
  content: '';
  position: absolute;
  background: transparent;
  pointer-events: auto;
}

/* Bridge positioning based on placement */
.popover--bottom-start.popover-container::before,
.popover--bottom.popover-container::before,
.popover--bottom-end.popover-container::before {
  top: -12px;
  left: 0;
  right: 0;
  height: 12px;
}

.popover--top-start.popover-container::before,
.popover--top.popover-container::before,
.popover--top-end.popover-container::before {
  bottom: -12px;
  left: 0;
  right: 0;
  height: 12px;
}

.popover--left.popover-container::before {
  top: 0;
  bottom: 0;
  right: -12px;
  width: 12px;
}

.popover--right.popover-container::before {
  top: 0;
  bottom: 0;
  left: -12px;
  width: 12px;
}

.popover-content {
  position: relative;
  background: var(--bulma-scheme-main);
  border: 1px solid var(--bulma-border);
  border-radius: 6px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  overflow: hidden;
}

.popover-content-inner {
  padding: 1rem;
  color: var(--bulma-text);
  max-height: 80vh;
  overflow-y: auto;
}

.popover-arrow {
  position: absolute;
  width: 12px;
  height: 12px;
  background: var(--bulma-scheme-main);
  border: 1px solid var(--bulma-border);
  transform: rotate(45deg);
  pointer-events: none;
}

/* Arrow positioning based on placement */
.popover--top .popover-arrow,
.popover--top-start .popover-arrow,
.popover--top-end .popover-arrow {
  border-top: none;
  border-left: none;
}

.popover--bottom .popover-arrow,
.popover--bottom-start .popover-arrow,
.popover--bottom-end .popover-arrow {
  border-bottom: none;
  border-right: none;
}

.popover--left .popover-arrow {
  border-left: none;
  border-bottom: none;
}

.popover--right .popover-arrow {
  border-right: none;
  border-top: none;
}


.popover-fade-enter-active,
.popover-fade-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.popover-fade-enter-from,
.popover-fade-leave-to {
  opacity: 0;
  transform: scale(0.95);
}

@media (prefers-color-scheme: dark) {
  .popover-content {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  }
}
</style>
