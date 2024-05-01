<template>
  <div class="notification is-warning">
    <h1 class="title is-5">There is no <em class="is-bold">{{ api_var }}</em> configured.</h1>
    <p>Please configure the API connection using the button <i class="fa fa-cog"></i> in the top right corner of the
      page.</p>
  </div>
</template>

<script setup>
import {useStorage} from "@vueuse/core";

const api_url = useStorage('api_url', '')
const api_var = computed(() => (!api_url.value) ? 'API URL' : 'API token')
</script>
