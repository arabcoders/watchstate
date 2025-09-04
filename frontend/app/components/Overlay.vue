<template>
  <div class="modal is-active">
    <div class="modal-background" @click="closeOverLay()"></div>
    <div class="modal-card" style="min-width: calc(100% - 30%);">
      <header class="modal-card-head">
        <p class="modal-card-title" v-text="model_title"></p>
        <button class="delete" @click="closeOverLay"></button>
      </header>
      <div class="modal-card-body">
        <slot/>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount} from 'vue'

const props = defineProps<{
  /** Title for the overlay modal */
  title: string
}>()

const emit = defineEmits<{
  /** Emitted when the overlay should be closed */
  (e: 'closeOverlay'): void
}>()

const model_title = ref<string>(props.title.replace(/^\//g, ''))

const closeOverLay = (): void => emit('closeOverlay')

const eventHandler = (e: KeyboardEvent): void => {
  if (e.key !== 'Escape') {
    return
  }
  closeOverLay()
}

onMounted(() => {
  disableOpacity()
  window.addEventListener('keydown', eventHandler)
})

onBeforeUnmount(() => {
  enableOpacity()
  window.removeEventListener('keydown', eventHandler)
})
</script>
