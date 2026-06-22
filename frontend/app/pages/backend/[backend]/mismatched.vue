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
        <span class="text-highlighted normal-case tracking-normal">Misidentified</span>
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
      description="WatchState did not find any possible mismatched items in the libraries we looked at."
    />

    <div v-if="items.length > 0" class="space-y-4">
      <UAlert
        color="warning"
        variant="soft"
        icon="i-lucide-triangle-alert"
        title="Review possible mismatches"
        description="WatchState found items that might be misidentified in your backend. Review the results carefully."
      />

      <div class="grid gap-4 xl:grid-cols-2">
        <UCard
          v-for="item in items"
          :key="item.title + item.library"
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
                    <UTooltip v-if="item.webUrl" :text="String(item.title)">
                      <NuxtLink
                        target="_blank"
                        :to="item.webUrl"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item.title }}
                      </NuxtLink>
                    </UTooltip>
                    <UTooltip v-else :text="String(item.title)">
                      <span class="block truncate text-highlighted">
                        {{ item.title }}
                      </span>
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
                    {{ item.library }}
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
                    {{ item.year ?? '???' }}
                  </div>
                </div>
              </div>

              <div
                class="col-span-2 rounded-md border border-default bg-elevated/40 px-3 py-2.5 text-sm text-default"
              >
                <div class="flex gap-2 flex-row items-start justify-between sm:gap-3">
                  <div
                    class="inline-flex min-w-0 items-center gap-2 text-xs font-medium uppercase tracking-[0.16em] text-toned"
                  >
                    <UIcon name="i-lucide-percent" class="size-3.5 shrink-0" />
                    <span>Percent</span>
                  </div>

                  <div
                    class="min-w-0 font-medium ml-auto text-right"
                    :class="percentColor(item.percent)"
                  >
                    {{ item.percent.toFixed(2) }}%
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
        </UCard>
      </div>
    </div>

    <UCard class="shadow-sm" :ui="panelCardUi">
      <template #header>
        <button
          type="button"
          class="flex items-center gap-2 text-left text-sm font-semibold text-highlighted"
          @click="show_page_tips = !show_page_tips"
        >
          <UIcon
            :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
            class="size-4 text-toned"
          />
          <UIcon name="i-lucide-info" class="size-4 text-toned" />
          <span>Tips</span>
        </button>
      </template>

      <div v-if="show_page_tips" class="text-sm leading-6 text-default">
        <ul class="list-disc space-y-2 pl-5">
          <li>
            This service expects standard Plex naming conventions for series and movies, so custom
            naming layouts can increase false positives.
          </li>
          <li>
            If you see many misidentified items, verify that the source directory matches the item.
          </li>
          <li>Use the raw-data toggle on each card to inspect the payload used for the report.</li>
        </ul>
      </div>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useHead } from '#app';
import { useStorage } from '@vueuse/core';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { makeSearchLink, notification, request, parse_api_response } from '~/utils';
import { useSessionCache } from '~/utils/cache';
import type { MismatchedItem } from '~/types';

type MismatchedItemWithUI = MismatchedItem & {
  showItem?: boolean;
};

const panelCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const resultCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const route = useRoute();
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');
const items = ref<Array<MismatchedItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const hasLooked = ref<boolean>(false);
const show_page_tips = useStorage('show_page_tips', true);
const cache = useSessionCache();
const cacheKey = `backend-${backend}-mismatched`;

useHead({ title: `Backends: ${backend} - Misidentified items` });

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
      const cachedData = cache.get<Array<MismatchedItemWithUI>>(cacheKey);
      if (null !== cachedData) {
        items.value = cachedData;
      }
    } else {
      const response = await request(`/backend/${backend}/mismatched`);
      const data = await parse_api_response<Array<MismatchedItemWithUI>>(response);

      if ('error' in data) {
        notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
        return;
      }

      cache.set(cacheKey, data);
      items.value = data;
    }
  } catch (e) {
    hasLooked.value = false;
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification('error', 'Error', errorMessage);
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
