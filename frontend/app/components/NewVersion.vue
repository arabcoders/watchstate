<template>
  <Message message_class="has-background-info-90 has-text-dark">
    <span class="icon"><i class="fas fa-info-circle"></i>&nbsp;</span>
    <span>
      A New WebUI Version installed, please
      <NuxtLink class="is-bold" @click="reloadPage">click here</NuxtLink>
      to reload the page.
    </span>
  </Message>
</template>

<script setup lang="ts">
import Message from '~/components/Message.vue'

const reloadPage = () => window.location.reload()
</script>
