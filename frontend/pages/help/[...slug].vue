<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <Markdown :file="`/guides/${slug}.md`"/>
    </div>
  </div>
</template>

<script setup>
const route = useRoute()
const slug = ref(`${route.params.slug?.length > 0 ? route.params.slug?.join('/') : ''}`)
</script>
