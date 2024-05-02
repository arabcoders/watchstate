<template>
  <div class="notification" :class="message_class">
    <h1 class="title is-5" v-if="html_title" v-html="html_title"></h1>
    <h1 class="title is-5" v-if="!html_title && title">{{ title }}</h1>
    <p v-if="html_message" v-html="html_message"></p>
    <p v-if="!html_message && message">{{ message }}</p>
  </div>
</template>

<script setup>
defineProps({
  html_title: {
    type: String,
    default: null,
    required: false
  },
  title: {
    type: String,
    default: null,
    required: false
  },
  html_message: {
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
  }
})
</script>
