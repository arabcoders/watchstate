<template>
  <div class="columns is-multiline" v-if="url">
    <div class="column is-12">
      <Markdown :file="url"/>
    </div>
  </div>
</template>

<script setup>
const route = useRoute()
const slug = ref(`${route.params.slug?.length > 0 ? route.params.slug.join('/') : ''}`)
const url = ref('')
onMounted(async () => {
  const to_lower = String(slug.value).toLowerCase()
  if (to_lower.includes('.md')) {
    await navigateTo(to_lower.replace('.md', ''))
  }

  const special = ['faq', 'readme', 'news']

  if (special.includes(to_lower)) {
    url.value = '/guides/' + to_lower.toUpperCase() + '.md'
    return
  }

  url.value = '/guides/' + slug.value + '.md'

  if (route.query.title) {
    useHead({title: `Guide - ${route.query.title}`})
  }
})
</script>
