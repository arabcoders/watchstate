<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" />

    <section
      v-for="group in groups"
      :key="group.id"
      :aria-labelledby="`guides-${group.id}`"
      class="space-y-3"
    >
      <h2 :id="`guides-${group.id}`" class="text-sm font-semibold text-highlighted">
        {{ group.title }}
      </h2>
      <ul class="ws-list-card overflow-hidden">
        <li v-for="guide in group.guides" :key="guide.url">
          <NuxtLink
            :to="{ path: guide.url, query: { title: guide.title } }"
            class="group flex items-center gap-3 p-4 transition-colors hover:bg-elevated/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
          >
            <UIcon :name="guide.icon" class="size-5 shrink-0 text-toned" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-1">
              <h3
                class="wrap-anywhere text-sm font-semibold text-highlighted group-hover:text-primary"
              >
                {{ guide.title }}
              </h3>
              <p v-if="guide.description" class="wrap-anywhere text-sm text-toned">
                {{ guide.description }}
              </p>
            </div>
            <UIcon
              name="i-lucide-chevron-right"
              class="size-4 shrink-0 text-muted"
              aria-hidden="true"
            />
          </NuxtLink>
        </li>
      </ul>
    </section>
  </main>
</template>

<script setup lang="ts">
import { useHead } from '#app';
import { NuxtLink, UIcon } from '#components';
import PageHeader from '~/components/PageHeader.vue';
import type { GuideEntry } from '~/types';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

useHead({ title: 'WatchState Guides' });

const pageShell = requireTopLevelPageShell('help');

const groups: Array<{ id: string; title: string; guides: Array<GuideEntry> }> = [
  {
    id: 'setup-sync',
    title: 'Setup & sync',
    guides: [
      {
        title: 'One-way sync',
        description: 'Send play state to other backends without changing the source.',
        url: '/help/one-way-sync',
        icon: 'i-lucide-arrow-right',
      },
      {
        title: 'Two-way sync',
        description: 'Exchange watch state and playback progress between backends.',
        url: '/help/two-way-sync',
        icon: 'i-lucide-arrow-right-left',
      },
      {
        title: 'Webhooks',
        description: 'Receive playback events alongside scheduled imports.',
        url: '/help/webhooks',
        icon: 'i-lucide-webhook',
      },
      {
        title: 'Creating identities',
        description: 'Keep separate sync configurations for each user.',
        url: '/help/identities',
        icon: 'i-lucide-users',
      },
      {
        title: 'Using WatchState as a backup',
        description: 'Preserve each user’s play state from a single backend for later restoration.',
        url: '/help/using-ws-as-backup-solution',
        icon: 'i-lucide-database-backup',
      },
      {
        title: 'Syncing a family account',
        description: 'Share family watch state without individual accounts overwriting each other.',
        url: '/help/three-way-sync',
        icon: 'i-lucide-house',
      },
    ],
  },
  {
    id: 'configuration-troubleshooting',
    title: 'Configuration & troubleshooting',
    guides: [
      {
        title: 'Path matching',
        description: 'Match shared media files when external IDs are missing or inconsistent.',
        url: '/help/path-match',
        icon: 'i-lucide-folder-search',
      },
      {
        title: 'Backend limitations',
        description: 'Check server requirements, webhook restrictions, and known sync issues.',
        url: '/help/backend-limitations',
        icon: 'i-lucide-server',
      },
      {
        title: 'Media Health',
        description:
          'Investigate conflicting IDs, duplicate records, and missing backend coverage.',
        url: '/help/media-health',
        icon: 'i-lucide-radar',
      },
      {
        title: 'systemd services',
        description: 'Start the WatchState worker at boot on a non-container installation.',
        url: '/help/systemd-services',
        icon: 'i-lucide-terminal',
      },
      {
        title: 'Authentication',
        description: 'Choose local login, OpenID Connect, or a trusted reverse proxy.',
        url: '/help/authentication',
        icon: 'i-lucide-shield-check',
      },
    ],
  },
  {
    id: 'reference',
    title: 'Reference',
    guides: [
      {
        title: 'FAQ',
        url: '/help/faq',
        icon: 'i-lucide-circle-help',
      },
      {
        title: 'API reference',
        url: '/help/api',
        icon: 'i-lucide-code',
      },
      {
        title: 'Backend OpenAPI',
        description: 'Browse and test Plex, Jellyfin, and Emby API endpoints.',
        url: '/help/openapi',
        icon: 'i-lucide-file-json',
      },
    ],
  },
];
</script>
