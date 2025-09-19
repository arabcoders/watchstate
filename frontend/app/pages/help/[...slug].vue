<template>
  <div class="columns is-multiline" v-if="url">
    <div class="column is-12">
      <Markdown :file="url" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useHead, navigateTo } from '#app'
import Markdown from '~/components/Markdown.vue'

const route = useRoute()
const slug = ref<string>('')
const url = ref<string>('')

if (route.params.slug) {
  if (Array.isArray(route.params.slug) && route.params.slug.length > 0) {
    slug.value = route.params.slug.join('/')
  } else if (typeof route.params.slug === 'string') {
    slug.value = route.params.slug
  }
}

onMounted(async (): Promise<void> => {
  const to_lower = String(slug.value).toLowerCase()
  if (to_lower.includes('.md')) {
    await navigateTo(to_lower.replace('.md', ''))
  }

  const special: Array<string> = ['faq', 'readme', 'news']

  if (special.includes(to_lower)) {
    url.value = '/guides/' + to_lower.toUpperCase() + '.md'
    return
  }

  url.value = '/guides/' + slug.value + '.md'

  if (route.query.title) {
    useHead({ title: `Guide - ${route.query.title}` })
  }
})
</script>
