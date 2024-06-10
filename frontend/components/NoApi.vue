<template>
  <div class="notification has-text-dark is-background-warning-80">
    <h5 class="title is-5 is-unselectable">
      <span class="icon-text">
        <span class="icon"><i class="fas fa-exclamation-triangle"></i></span>
        <span>Warning</span>
      </span>
    </h5>
    <div class="content">
      <p>There is no <em class="is-bold">{{ api_var }}</em> configured.</p>
      <p>Please configure the API connection using the button <i class="fa fa-cog"></i> in the top right corner of the
        page.</p>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from "@vueuse/core";

const api_token = useStorage('api_token', '')
const api_var = computed(() => (!api_token.value) ? 'API Token' : 'API URL')
</script>
