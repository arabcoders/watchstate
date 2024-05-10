<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink href="/backends">Backends</NuxtLink>
        : {{ backend }}
      </span>

      <div class="is-pulled-right">
        <div class="field is-grouped"></div>
      </div>
    </div>
  </div>

  <div class="column is-12">
    This page is not yet implemented. It will be used to display the details of a specific backend.
  </div>
</template>

<script setup>
const backend = useRoute().params.backend
useHead({title: `Backends: ${backend}`})
</script>
