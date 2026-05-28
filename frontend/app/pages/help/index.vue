<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="space-y-1">
      <div
        class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
      >
        <UIcon :name="pageShell.icon" class="size-4" />
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <span>{{ pageShell.pageLabel }}</span>
      </div>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
      <UCard
        v-for="choice in choices"
        :key="choice.number"
        class="h-full border border-default/70 shadow-sm"
        :ui="cardUi"
      >
        <template #header>
          <div class="min-w-0 space-y-1">
            <div class="inline-flex items-center gap-2 text-base font-semibold text-highlighted">
              <UIcon name="i-lucide-book-open" class="size-4 shrink-0 text-toned" />
              <UTooltip :text="`${choice.number}. ${choice.title}`">
                <NuxtLink
                  v-if="choice.url"
                  :to="`${choice.url}?title=${choice.title}`"
                  class="block truncate hover:text-primary"
                >
                  {{ `${choice.number}. ${choice.title}` }}
                </NuxtLink>
                <span v-else class="block truncate">{{ `${choice.number}. ${choice.title}` }}</span>
              </UTooltip>
            </div>
          </div>
        </template>
        <template #default>
          {{ choice.text }}
          <UAlert
            v-if="!choice.url"
            color="warning"
            variant="soft"
            icon="i-lucide-triangle-alert"
            title="Not available yet"
            description="This guide is not available yet."
          />
        </template>
      </UCard>
    </div>
  </main>
</template>

<script setup lang="ts">
import { useHead } from '#app';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

useHead({ title: 'WatchState Guides' });

const pageShell = requireTopLevelPageShell('help');

const cardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const choices: Array<{ number: number; title: string; text: string; url: string }> = [
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
    text: 'How to enable webhooks for your backends and identities.',
    url: '/help/webhooks',
  },
  {
    number: 4,
    title: 'Creating Identities',
    text: 'Guide on how to create and use identities (multi-users).',
    url: '/help/identities',
  },
  {
    number: 5,
    title: 'FAQ',
    text: 'Frequently asked questions.',
    url: '/help/faq',
  },
  {
    number: 6,
    title: 'Using WatchState as backup solution',
    text: 'Guide on how to setup watchstate for single backend with multiple users for backups.',
    url: '/help/using-ws-as-backup-solution',
  },
  {
    number: 7,
    title: 'Syncing a family account',
    text: 'Guide on how to sync a family account with multiple users.',
    url: '/help/three-way-sync',
  },
  {
    number: 8,
    title: 'API Reference',
    text: 'API documentation current version.',
    url: '/help/api',
  },
  {
    number: 9,
    title: 'Backend OpenAPI',
    text: 'Browse the bundled Plex, Jellyfin, and Emby upstream routes.',
    url: '/help/openapi',
  },
  {
    number: 10,
    title: 'Path matching',
    text: 'How to enable path-based matching.',
    url: '/help/path-match',
  },
  {
    number: 11,
    title: 'Backend limitations',
    text: 'Requirements and known limitations for supported backends.',
    url: '/help/backend-limitations',
  },
];
</script>
