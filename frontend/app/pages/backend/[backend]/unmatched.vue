<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" page-label="Unmatched" description="">
      <template #kicker>
        <NuxtLink to="/backends" class="hover:text-primary">Backends</NuxtLink>
        <span>/</span>
        <NuxtLink
          :to="`/backend/${backend}`"
          class="hover:text-primary normal-case tracking-normal"
          >{{ backend }}</NuxtLink
        >
      </template>

      <template #actions>
        <UButton
          v-if="hasLooked"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent(false)"
        >
          Refresh
        </UButton>
      </template>
    </PageHeader>

    <section
      v-if="false === hasLooked"
      aria-label="Analysis setup"
      class="flex flex-wrap items-center justify-between gap-4 border-b border-default pb-5"
    >
      <p class="max-w-2xl text-sm leading-6 text-toned">
        WatchState scans the entire backend library. This takes time; do not reload the page during
        the scan.
      </p>
      <UButton
        color="primary"
        variant="solid"
        icon="i-lucide-circle-check"
        :disabled="isLoading"
        @click="() => void loadContent()"
      >
        Start analysis
      </UButton>
    </section>

    <UAlert
      v-if="isLoading && 1 > items.length"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Checking backend content for unmatched items"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Analysis failed"
      :description="loadError"
    />

    <UEmpty
      v-else-if="!isLoading && hasLooked && 1 > items.length"
      icon="i-lucide-circle-check"
      title="No unmatched content"
    />

    <div v-if="0 < items.length" class="space-y-4">
      <UAlert
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Review unmatched content"
        description="Review the items below and use the external lookups to confirm the correct match."
      />

      <section aria-label="Unmatched content" class="ws-list-card">
        <Lazy
          v-for="item in items"
          :key="
            item.id ??
            `${item.library ?? ''}:${item.path ?? item.webUrl ?? item.url ?? item.title}:${item.title}:${item.year ?? ''}`
          "
          :unrender="true"
          :min-height="172"
        >
          <article class="flex items-start gap-3 p-4 sm:p-5">
            <StatisticsPoster
              v-if="posterEnable"
              :key="`${apiUser}:${item.poster ?? item.path ?? item.title}`"
              :image="item.poster"
              :title="item.title"
              :user="apiUser"
              preview
              class="hidden w-16 shrink-0 sm:block"
            />
            <div class="min-w-0 flex-1 space-y-3">
              <div class="space-y-2">
                <div class="flex items-start gap-2">
                  <UIcon
                    :name="'Movie' === item.type ? 'i-lucide-film' : 'i-lucide-tv'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />
                  <div class="min-w-0 flex-1">
                    <NuxtLink
                      target="_blank"
                      :to="item.webUrl ?? item.url"
                      :title="item.title"
                      class="line-clamp-2 text-sm font-semibold text-highlighted hover:text-primary"
                    >
                      {{ item.title }}
                    </NuxtLink>
                  </div>
                </div>
                <dl class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-toned sm:ml-6">
                  <div class="flex min-w-0 gap-1.5">
                    <dt>Library</dt>
                    <dd class="wrap-break-word text-highlighted">
                      {{ item.library ?? 'Unknown' }}
                    </dd>
                  </div>
                  <div class="flex gap-1.5">
                    <dt>Year</dt>
                    <dd class="text-highlighted">
                      {{ 0 !== item.year && item.year ? item.year : 'Unknown' }}
                    </dd>
                  </div>
                </dl>
              </div>
              <div v-if="item.path" class="flex min-w-0 items-start gap-2 text-xs">
                <span class="shrink-0 py-1 text-toned">Path</span>
                <NuxtLink
                  :to="makeSearchLink('path', item.path)"
                  :title="item.path"
                  class="min-w-0 flex-1 py-1 font-mono text-toned hover:text-primary"
                  :class="item.showItem ? 'wrap-break-word' : 'truncate'"
                >
                  {{ item.path }}
                </NuxtLink>
                <UTooltip :text="item.showItem ? 'Collapse path' : 'Expand path'">
                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    square
                    :icon="item.showItem ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                    :aria-label="`${item.showItem ? 'Collapse' : 'Expand'} path for ${item.title}`"
                    :aria-expanded="!!item.showItem"
                    @click="item.showItem = !item.showItem"
                  />
                </UTooltip>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-search"
                  :to="imdbSearchLink(item.title)"
                  target="_blank"
                >
                  IMDb
                </UButton>

                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-search"
                  :to="tmdbSearchLink(item.title)"
                  target="_blank"
                >
                  TMDB
                </UButton>

                <UButton
                  color="neutral"
                  variant="outline"
                  size="sm"
                  icon="i-lucide-search"
                  :to="tvdbSearchLink(item.title)"
                  target="_blank"
                >
                  TVDB
                </UButton>
              </div>
            </div>
          </article>
        </Lazy>
      </section>
    </div>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useHead } from '#app';
import { NuxtLink, UAlert, UButton, UEmpty, UIcon, UTooltip } from '#components';
import { useStorage } from '@vueuse/core';
import PageHeader from '~/components/PageHeader.vue';
import Lazy from '~/components/Lazy.vue';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { makeSearchLink, notification, request, parse_api_response } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { UnmatchedItem } from '~/types';

type UnmatchedItemWithUI = UnmatchedItem & {
  showItem?: boolean;
};

const backend = useRoute().params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<UnmatchedItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const hasLooked = ref<boolean>(false);
const loadError = ref('');
const posterEnable = useStorage<boolean>('poster_enable', true);
const apiUser = useStorage<string>('api_user', 'main');
const cache = useSessionCache();
const cacheKey = `backend-${backend}-unmatched`;

useHead({ title: `Backends: ${backend} - Unmatched items.` });

const loadContent = async (useCache: boolean = true): Promise<void> => {
  hasLooked.value = true;
  isLoading.value = true;
  loadError.value = '';
  items.value = [];

  try {
    if (useCache && cache.has(cacheKey)) {
      const cachedData = cache.get(cacheKey) as Array<UnmatchedItemWithUI>;
      if (cachedData) {
        items.value = cachedData;
      }
    } else {
      const response = await request(`/backend/${backend}/unmatched`);
      const data = await parse_api_response<Array<UnmatchedItemWithUI>>(response);

      if ('error' in data) {
        loadError.value = `${data.error.code}: ${data.error.message}`;
        notification(
          'error',
          'Unmatched content analysis failed',
          `${data.error.code}: ${data.error.message}`,
        );
        return;
      }

      cache.set(cacheKey, data);
      items.value = data;
    }
  } catch (e) {
    hasLooked.value = false;
    loadError.value = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification(
      'error',
      'Unmatched content analysis failed',
      e instanceof Error ? e.message : 'Unknown error occurred',
    );
  } finally {
    isLoading.value = false;
  }
};

const fixTitle = (title: string): string =>
  title
    .replace(/[[(].*?[\])]/g, '')
    .replace(/-\w+$/, '')
    .trim();

const imdbSearchLink = (title: string): string => `https://www.imdb.com/find/?q=${fixTitle(title)}`;

const tmdbSearchLink = (title: string): string =>
  `https://www.themoviedb.org/search?query=${fixTitle(title)}`;

const tvdbSearchLink = (title: string): string =>
  `https://thetvdb.com/search?query=${fixTitle(title)}`;
</script>
