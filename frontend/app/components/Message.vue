<template>
  <div class="notification" :class="message_class">
    <button class="delete" @click="$emit('close')" v-if="!useToggle && useClose"></button>
    <div @click="$emit('toggle')" class="is-clickable is-pulled-right is-unselectable" v-if="useToggle">
      <span class="icon">
        <i class="fas" :class="{'fa-arrow-up':toggle,'fa-arrow-down':!toggle}"></i>
      </span>
      <span>{{ toggle ? 'Close' : 'Open' }}</span>
    </div>
    <div class="notification-title is-unselectable" :class="{'is-clickable':useToggle}" v-if="title || icon"
         @click="true === useToggle ? $emit('toggle', toggle): null">
      <template v-if="icon">
        <span class="icon-text">
          <span class="icon"><i :class="icon"></i></span>
          <span>{{ title }}</span>
        </span>
      </template>
      <template v-else>{{ title }}</template>
    </div>
    <div class="notification-content content is-text-break" v-if="false === useToggle || toggle">
      <template v-if="message">{{ message }}</template>
      <slot/>
    </div>
  </div>
</template>

<script setup lang="ts">
import {defineProps, defineEmits} from 'vue'

withDefaults(defineProps<{
  /** Title text for the notification */
  title?: string | null
  /** Icon class for the notification */
  icon?: string | null
  /** Main message content */
  message?: string | null
  /** CSS class for the notification */
  message_class?: string
  /** If true, show toggle button */
  useToggle?: boolean
  /** Current toggle state */
  toggle?: boolean
  /** If true, show close button */
  useClose?: boolean
}>(), {
  title: null,
  icon: null,
  message: null,
  message_class: 'is-info',
  useToggle: false,
  toggle: false,
  useClose: false,
})

defineEmits<{
  /** Emitted when the toggle button is clicked */
  (e: 'toggle', value?: boolean): void
  /** Emitted when the close button is clicked */
  (e: 'close'): void
}>()
</script>
