<template>
  <div class="notification" :class="message_class">
    <h1 class="title is-5" v-if="title">{{ title }}</h1>
    <p v-if="message">{{ message }}</p>
    <slot></slot>
  </div>
</template>

<script setup>
defineProps({
  title: {
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
