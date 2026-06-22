<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <NuxtLink to="/backends" class="hover:text-primary">{{ pageShell.pageLabel }}</NuxtLink>
        <span>/</span>
        <NuxtLink
          :to="`/backend/${backend}`"
          class="hover:text-primary normal-case tracking-normal"
          >{{ backend }}</NuxtLink
        >
        <span>/</span>
        <span class="text-highlighted normal-case tracking-normal">Unmatched</span>
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
          <span class="hidden sm:inline">Reload</span>
        </UButton>
      </template>
    </PageHeader>

    <UCard v-if="false === hasLooked" class="shadow-sm" :ui="panelCardUi">
      <template #header>
        <div class="flex items-center gap-2 text-sm font-semibold text-highlighted">
          <UIcon name="i-lucide-circle-check" class="size-4 text-toned" />
          <span>Request Analyze</span>
        </div>
      </template>

      <div class="space-y-4 text-sm leading-6 text-default">
        <p>
          Checking the items will take time. WatchState will analyze the entire backend library
          content, so avoid reloading the page during the scan.
        </p>

        <UButton
          color="primary"
          icon="i-lucide-circle-check"
          :disabled="isLoading"
          @click="() => void loadContent()"
        >
          Initiate the process
        </UButton>
      </div>
    </UCard>

    <UAlert
      v-if="isLoading && items.length < 1"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Analyzing"
      description="Analyzing the backend content. Please wait. It will take a while..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="!isLoading && hasLooked && items.length < 1"
      color="success"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Success"
      description="WatchState did not find any unmatched content in the libraries we looked at."
    />

    <div v-if="items.length > 0" class="space-y-4">
      <UAlert
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Unmatched Content"
        description="Review the items below and use the external lookups to confirm the correct match."
      />

      <div class="grid gap-4 xl:grid-cols-2">
        <UCard
          v-for="item in items"
          :key="item.id ?? item.title"
          class="h-full shadow-sm"
          :ui="resultCardUi"
        >
          <template #header>
            <div class="flex items-start gap-3">
              <div class="min-w-0 flex-1">
                <div
                  class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
                >
                  <UIcon
                    :name="'Movie' === item.type ? 'i-lucide-film' : 'i-lucide-tv'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />

                  <div class="min-w-0 flex-1">
                    <UTooltip :text="String(item.title)">
                      <NuxtLink
                        target="_blank"
                        :to="item.webUrl ?? item.url"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item.title }}
                      </NuxtLink>
                    </UTooltip>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <div class="space-y-3">
            <div class="grid grid-cols-2 gap-2.5">
              <div
                class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-library-big" class="size-3.5 shrink-0" />
                    <span>Library</span>
                  </div>

                  <div class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                    {{ item.library ?? 'Unknown' }}
                  </div>
                </div>
              </div>

              <div
                class="rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <div
                  class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
                >
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-calendar" class="size-3.5 shrink-0" />
                    <span>Year</span>
                  </div>

                  <div class="min-w-0 font-medium text-highlighted sm:ml-auto sm:text-right">
                    {{ 0 !== item.year && item.year ? item.year : 'Unknown' }}
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="item.path"
              class="col-span-2 cursor-pointer rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              @click="toggleFirstChildOverflow"
            >
              <div
                class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3"
              >
                <div
                  class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                >
                  <UIcon name="i-lucide-file-text" class="size-3.5 shrink-0" />
                  <span>Path</span>
                </div>
                <div
                  class="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap font-medium text-highlighted sm:text-right"
                >
                  <NuxtLink :to="makeSearchLink('path', item.path)" class="hover:text-primary">
                    {{ item.path }}
                  </NuxtLink>
                </div>
              </div>
            </div>
          </div>

          <template #footer>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-search"
                :to="`https://www.imdb.com/find/?q=${fixTitle(item.title)}`"
                target="_blank"
              >
                IMDb
              </UButton>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-search"
                :to="`https://www.themoviedb.org/search?query=${fixTitle(item.title)}`"
                target="_blank"
              >
                TMDB
              </UButton>

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-search"
                :to="`https://thetvdb.com/search?query=${fixTitle(item.title)}`"
                target="_blank"
              >
                TVDB
              </UButton>
            </div>
          </template>
        </UCard>
      </div>
    </div>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useHead } from '#app';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { makeSearchLink, notification, request, parse_api_response } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { UnmatchedItem } from '~/types';

type UnmatchedItemWithUI = UnmatchedItem & {
  showItem?: boolean;
};

const panelCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const resultCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default/70 px-4 py-4',
};

const backend = useRoute().params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<UnmatchedItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const hasLooked = ref<boolean>(false);
const cache = useSessionCache();
const cacheKey = `backend-${backend}-unmatched`;

useHead({ title: `Backends: ${backend} - Unmatched items.` });

const toggleFirstChildOverflow = (event: Event): void => {
  const target = event.target as HTMLElement | null;

  target?.firstElementChild?.classList?.toggle('overflow-hidden');
  target?.firstElementChild?.classList?.toggle('text-ellipsis');
  target?.firstElementChild?.classList?.toggle('whitespace-nowrap');
};

const loadContent = async (useCache: boolean = true): Promise<void> => {
  hasLooked.value = true;
  isLoading.value = true;
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
        notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
        return;
      }

      cache.set(cacheKey, data);
      items.value = data;
    }
  } catch (e) {
    hasLooked.value = false;
    return notification(
      'error',
      'Error',
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
</script>
