<template>
  <div class="notification" :class="message_class">
    <button class="delete" @click="$emit('close')" v-if="!useToggle && useClose"></button>
    <div class="is-pulled-right is-unselectable" v-if="useToggle">
      <span class="icon">
        <i class="fas" :class="{'fa-arrow-up':toggle,'fa-arrow-down':!toggle}"></i>
      </span>
      <span>{{ toggle ? 'Close' : 'Open' }}</span>
    </div>
    <div class="notification-title is-unselectable" :class="{'is-clickable':useToggle}" v-if="title || icon"
         @click="useToggle ? $emit('toggle', toggle):null">
      <template v-if="icon">
        <span class="icon-text">
          <span class="icon"><i :class="icon"></i></span>
          <span>{{ title }}</span>
        </span>
      </template>
      <template v-else>{{ title }}</template>
    </div>
    <div class="notification-content content" v-if="false === useToggle || toggle">
      <template v-if="message">{{ message }}</template>
      <slot/>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    default: null,
    required: false
  },
  icon: {
    type: String,
    default: null,
    required: false
  },
  message: {
    type: String,
    default: null,
    required: false
  },
  message_class: {
    type: String,
    default: 'is-info',
    required: false
  },
  useToggle: {
    type: Boolean,
    default: false,
    required: false
  },
  toggle: {
    type: Boolean,
    default: false,
    required: false
  },
  useClose: {
    type: Boolean,
    default: false,
    required: false
  }
})

defineEmits(['toggle', 'close'])
</script>
