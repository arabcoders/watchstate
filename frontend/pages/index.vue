<template>
  <template v-if="!api_url || !api_token">
    <no-api/>
  </template>
  <template v-else>
    <p>foo</p>
  </template>
</template>


<script setup>
import {useStorage} from "@vueuse/core"
import NoApi from "~/components/NoApi.vue"

const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')

useHead({title: 'Index'})
</script>
