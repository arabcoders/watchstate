<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" page-label="Misidentified" description="">
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
      title="Checking backend content for mismatches"
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
      title="No possible mismatches"
    />

    <div v-if="0 < items.length" class="space-y-4">
      <UAlert
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Review possible mismatches"
        description="These items might be misidentified on the backend. Review each match."
      />

      <section aria-label="Possible mismatches" class="ws-card divide-y divide-default">
        <Lazy
          v-for="item in items"
          :key="`${item.library}:${item.path ?? item.webUrl ?? item.title}:${item.title}:${item.year ?? ''}`"
          :unrender="true"
          :min-height="136"
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
                      v-if="item.webUrl"
                      target="_blank"
                      :to="item.webUrl"
                      :title="item.title"
                      class="line-clamp-2 text-sm font-semibold text-highlighted hover:text-primary"
                    >
                      {{ item.title }}
                    </NuxtLink>
                    <span
                      v-else
                      :title="item.title"
                      class="line-clamp-2 text-sm font-semibold text-highlighted"
                    >
                      {{ item.title }}
                    </span>
                  </div>
                </div>
                <dl class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-toned sm:ml-6">
                  <div class="flex min-w-0 gap-1.5">
                    <dt>Library</dt>
                    <dd class="wrap-break-word text-highlighted">{{ item.library }}</dd>
                  </div>
                  <div class="flex gap-1.5">
                    <dt>Year</dt>
                    <dd class="text-highlighted">{{ item.year ?? 'Unknown' }}</dd>
                  </div>
                  <div class="flex gap-1.5">
                    <dt>Similarity</dt>
                    <dd class="font-medium tabular-nums" :class="percentColor(item.percent)">
                      {{ item.percent.toFixed(2) }}%
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
            </div>
          </article>
        </Lazy>
      </section>
    </div>

    <details
      :open="show_page_tips"
      class="space-y-3 border-t border-default pt-4"
      @toggle="show_page_tips = ($event.target as HTMLDetailsElement).open"
    >
      <summary class="cursor-pointer text-sm font-medium text-highlighted">Naming tips</summary>
      <div class="text-sm leading-6 text-toned">
        <ul class="list-disc space-y-2 pl-5">
          <li>
            This service expects standard Plex naming conventions for series and movies, so custom
            naming layouts can increase false positives.
          </li>
          <li>
            If you see many misidentified items, verify that the source directory matches the item.
          </li>
        </ul>
      </div>
    </details>
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
import type { MismatchedItem } from '~/types';

const route = useRoute();
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<MismatchedItem>>([]);
const isLoading = ref<boolean>(false);
const hasLooked = ref<boolean>(false);
const loadError = ref('');
const show_page_tips = useStorage('show_page_tips', true);
const posterEnable = useStorage<boolean>('poster_enable', true);
const apiUser = useStorage<string>('api_user', 'main');
const cache = useSessionCache();
const cacheKey = `backend-${backend}-mismatched`;

useHead({ title: `Backends: ${backend} - Misidentified items` });

const loadContent = async (useCache: boolean = true): Promise<void> => {
  hasLooked.value = true;
  isLoading.value = true;
  loadError.value = '';
  items.value = [];

  try {
    if (useCache && cache.has(cacheKey)) {
      const cachedData = cache.get<Array<MismatchedItem>>(cacheKey);
      if (null !== cachedData) {
        items.value = cachedData;
      }
    } else {
      const response = await request(`/backend/${backend}/mismatched`);
      const data = await parse_api_response<Array<MismatchedItem>>(response);

      if ('error' in data) {
        loadError.value = `${data.error.code}: ${data.error.message}`;
        notification(
          'error',
          'Mismatch analysis failed',
          `${data.error.code}: ${data.error.message}`,
        );
        return;
      }

      cache.set(cacheKey, data);
      items.value = data;
    }
  } catch (e) {
    hasLooked.value = false;
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    loadError.value = errorMessage;
    return notification('error', 'Mismatch analysis failed', errorMessage);
  } finally {
    isLoading.value = false;
  }
};

const percentColor = (percent: number): string => {
  const percentInt = Number.parseInt(percent.toString(), 10);

  if (90 < percentInt) {
    return 'text-success';
  } else if (50 < percentInt && percentInt < 90) {
    return 'text-warning';
  }

  return 'text-error';
};
</script>
