<template>
  <Message title="Warning" message_class="has-background-warning-80 has-text-dark" icon="fas fa-exclamation-triangle">
    There is no <em class="is-bold">{{ api_var }}</em> configured. Please configure the API connection using the button
    <i class="fa fa-cog"></i> in the top right corner of the page.
  </Message>
</template>

<script setup>
import {useStorage} from "@vueuse/core";

const api_token = useStorage('api_token', '')
const api_var = computed(() => (!api_token.value) ? 'API Token' : 'API URL')
</script>
