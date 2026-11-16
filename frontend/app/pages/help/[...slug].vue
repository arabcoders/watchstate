<template>
  <main v-if="url" class="w-full min-w-0 max-w-full space-y-6">
    <NuxtLink
      to="/help"
      class="inline-flex items-center gap-2 text-sm text-toned hover:text-primary"
    >
      <UIcon name="i-lucide-arrow-left" class="size-4" aria-hidden="true" />
      Guides
    </NuxtLink>

    <Markdown :file="url" />
  </main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { navigateTo, useHead, useRoute } from '#app';
import { NuxtLink, UIcon } from '#components';
import Markdown from '~/components/Markdown.vue';

const route = useRoute();
const slug = ref<string>('');
const url = ref<string>('');

const pageTitle = computed<string>(() => {
  const queryTitle = route.query.title;

  if ('string' === typeof queryTitle && queryTitle.length > 0) {
    return queryTitle;
  }

  return slug.value
    .split('/')
    .filter(Boolean)
    .map((part) => part.replace(/-/g, ' '))
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' / ');
});

useHead({ title: computed(() => `Guide - ${pageTitle.value}`) });

watch(
  () => [route.params.slug, route.query.title],
  async () => {
    slug.value = '';

    if (route.params.slug) {
      if (Array.isArray(route.params.slug) && route.params.slug.length > 0) {
        slug.value = route.params.slug.join('/');
      } else if ('string' === typeof route.params.slug) {
        slug.value = route.params.slug;
      }
    }

    const toLower = String(slug.value).toLowerCase();

    if (toLower.includes('.md')) {
      await navigateTo(toLower.replace('.md', ''));
      return;
    }

    const special: Array<string> = ['api', 'faq', 'readme', 'news'];

    if (special.includes(toLower)) {
      url.value = '/guides/' + toLower.toUpperCase() + '.md';
    } else {
      url.value = '/guides/' + slug.value + '.md';
    }
  },
  { immediate: true },
);
</script>
