<template>
  <div :class="[newStyle ? 'message' : 'notification', message_class]">
    <button class="delete" @click="$emit('close')" v-if="!useToggle && useClose && !newStyle"></button>
    <div @click="$emit('toggle')" class="is-clickable is-pulled-right is-unselectable" v-if="useToggle">
      <span class="icon">
        <i class="fas" :class="{'fa-arrow-up':toggle,'fa-arrow-down':!toggle}"></i>
      </span>
      <span>{{ toggle ? 'Close' : 'Open' }}</span>
    </div>
    <div class="is-unselectable"
         :class="{'is-clickable':useToggle, 'notification-title': !newStyle, 'message-header': newStyle}"
         v-if="title || icon"
         @click="true === useToggle ? $emit('toggle', toggle): null">
      <template v-if="icon">
        <span class="icon-text">
          <span class="icon"><i :class="icon"></i></span>
          <span>{{ title }}</span>
        </span>
      </template>
      <template v-else>{{ title }}</template>
      <button class="delete" @click="$emit('close')" v-if="!useToggle && useClose && newStyle"/>
    </div>
    <div class="content is-text-break" v-if="false === useToggle || toggle"
         :class="{'notification-body': !newStyle, 'message-body': newStyle}">
      <template v-if="message">{{ message }}</template>
      <slot/>
    </div>
  </div>
</template>

<script setup lang="ts">
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
  useClose?: boolean,
  newStyle?: boolean
}>(), {
  title: null,
  icon: null,
  message: null,
  message_class: 'is-info',
  useToggle: false,
  toggle: false,
  useClose: false,
  newStyle: false
})

defineEmits<{
  /** Emitted when the toggle button is clicked */
  (e: 'toggle', value?: boolean): void
  /** Emitted when the close button is clicked */
  (e: 'close'): void
}>()
</script>
