<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-hands-helping"/></span>
          {{ 'Getting started' }}
        </span>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page contains guides to help you get started with WatchState. This is an early version, we are still
            working on the guides.
          </span>
        </div>
      </div>
    </div>

    <div class="columns is-multiline">
      <div v-for="choice in choices" :key="choice.url" class="column is-6-tablet is-12-mobile">
        <div class="box content" style="height: 100%">
          <h3 class="title is-5">
            <NuxtLink :to="`${choice.url}?title=${choice.title}`" class="has-text-link"
                      v-text="`${choice.number}. ${choice.title}`" v-if="choice.url"/>
            <span v-else>{{ `${choice.number}. ${choice.title}` }}</span>
          </h3>
          <hr>
          <Message message_class="has-background-warning-90 has-text-dark" v-if="!choice.url" class="p-1">
            <p>
              <span class="icon"><i class="fas fa-exclamation has-text-danger"/></span>
              <span>This guide is not available yet.</span>
            </p>
          </Message>
          <p>{{ choice.text }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {useHead} from '#app'
import Message from '~/components/Message.vue'

useHead({title: 'WatchState Guides'})

const choices: Array<{ number: number, title: string, text: string, url: string }> = [
  {
    number: 1,
    title: 'One-way sync',
    text: 'For example, You want to import data from plex, and send it to jellyfin/emby but not the other way around.',
    url: '/help/one-way-sync',
  },
  {
    number: 2,
    title: 'Two-way sync',
    text: 'This will allow all backends to sync with each other. I.e. plex to jellyfin, jellyfin to emby, emby to plex.',
    url: '/help/two-way-sync',
  },
  {
    number: 3,
    title: 'Webhooks',
    text: 'How to enable webhooks for your backends and for sub-users.',
    url: '/help/webhooks',
  },
  {
    number: 4,
    title: 'Creating Sub-users',
    text: 'Guide on how to create and use sub-users.',
    url: '/help/sub-users',
  },
  {
    number: 5,
    title: 'FAQ',
    text: 'Frequently asked questions.',
    url: '/help/faq',
  },
]
</script>
