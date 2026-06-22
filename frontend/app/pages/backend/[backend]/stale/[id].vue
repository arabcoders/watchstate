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
        <span class="text-highlighted normal-case tracking-normal">Staleness</span>
      </template>

      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="() => void loadContent()"
          label="Reload"
        />
      </template>
    </PageHeader>

    <UAlert
      v-if="0 < items.length"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Stale references found"
    >
      <template #description>
        <span>
          WatchState found <strong>{{ counts.stale }}</strong> items in the local database that no
          longer exist in <strong>{{ remote.name }}</strong>
          <template v-if="remote.library?.title"
            >library <strong>{{ remote.library.title }}</strong></template
          >.
        </span>
      </template>
    </UAlert>

    <div v-if="0 < items.length" class="grid gap-4 xl:grid-cols-2">
      <Lazy v-for="item in items" :key="item.id" :unrender="true" :min-height="343" class="block">
        <UCard
          class="h-full border shadow-sm"
          :class="item.watched ? 'border-success/40' : 'border-default/70'"
          :ui="resultCardUi"
        >
          <template #header>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div
                  class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
                >
                  <UIcon
                    :name="'episode' === item.type.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film'"
                    class="mt-0.5 size-4 shrink-0 text-toned"
                  />

                  <div class="min-w-0 flex-1">
                    <FloatingImage
                      :image="`/history/${item.id}/images/poster`"
                      v-if="poster_enable"
                    >
                      <UTooltip :text="String(makeName(item))">
                        <NuxtLink
                          :to="`/history/${item.id}`"
                          class="block truncate text-highlighted hover:text-primary"
                        >
                          {{ makeName(item) }}
                        </NuxtLink>
                      </UTooltip>
                    </FloatingImage>

                    <UTooltip v-else :text="String(makeName(item))">
                      <NuxtLink
                        :to="`/history/${item.id}`"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ makeName(item) }}
                      </NuxtLink>
                    </UTooltip>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <div class="space-y-3">
            <div
              class="flex items-start gap-2 rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
            >
              <button
                type="button"
                class="mt-0.5 shrink-0 text-toned hover:text-primary"
                @click="item.expand_title = !item.expand_title"
              >
                <UIcon name="i-lucide-heading" class="size-4" />
              </button>

              <div
                class="min-w-0 flex-1"
                :class="item.expand_title ? 'wrap-break-word' : 'truncate'"
              >
                <NuxtLink
                  :to="makeSearchLink('subtitle', item.content_title ?? item.title)"
                  class="hover:text-primary"
                >
                  {{ item.content_title ?? item.title }}
                </NuxtLink>
              </div>

              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                square
                icon="i-lucide-copy"
                @click="copyText(item.content_title ?? item.title, false)"
              />
            </div>

            <div
              class="flex items-start gap-2 rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
            >
              <button
                type="button"
                class="mt-0.5 shrink-0 text-toned hover:text-primary"
                @click="item.expand_path = !item.expand_path"
              >
                <UIcon name="i-lucide-file-text" class="size-4" />
              </button>

              <div
                class="min-w-0 flex-1"
                :class="item.expand_path ? 'wrap-break-word' : 'truncate'"
              >
                <NuxtLink
                  v-if="item.content_path"
                  :to="makeSearchLink('path', item.content_path)"
                  class="hover:text-primary"
                >
                  {{ item.content_path }}
                </NuxtLink>
                <span v-else>No path found.</span>
              </div>

              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                square
                icon="i-lucide-copy"
                @click="copyText(item.content_path || '', false)"
              />
            </div>

            <div
              class="rounded-md border border-default bg-elevated/20 px-3 py-2 text-sm text-default"
            >
              <div class="mb-2 flex items-center gap-2 font-medium text-highlighted">
                <UIcon name="i-lucide-server" class="size-4 text-toned" />
                <span>Has metadata from</span>
              </div>

              <div class="flex flex-wrap gap-2">
                <NuxtLink
                  v-for="reportedBackend in item.reported_by"
                  :key="`${item.id}-rb-${reportedBackend}`"
                  :to="`/backend/${reportedBackend}`"
                  class="inline-flex items-center gap-1.5 rounded-md border border-default bg-elevated/40 px-2.5 py-1 text-xs font-medium text-default"
                >
                  <UIcon name="i-lucide-server" class="size-3.5" />
                  {{ reportedBackend }}
                </NuxtLink>

                <NuxtLink
                  v-for="missingBackend in item.not_reported_by"
                  :key="`${item.id}-nrb-${missingBackend}`"
                  :to="`/backend/${missingBackend}`"
                  class="inline-flex items-center gap-1.5 rounded-md border border-error/30 bg-error/10 px-2.5 py-1 text-xs font-medium text-error"
                >
                  <UIcon name="i-lucide-circle-alert" class="size-3.5" />
                  {{ missingBackend }}
                </NuxtLink>
              </div>
            </div>
          </div>

          <template #footer>
            <div class="grid grid-cols-2 gap-2.5">
              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon
                  :name="item.watched ? 'i-lucide-eye' : 'i-lucide-eye-off'"
                  class="size-4 shrink-0 text-toned"
                />
                <span :class="item.watched ? 'text-success' : 'text-error'">
                  {{ item.watched ? 'Played' : 'Unplayed' }}
                </span>
              </div>

              <div
                class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
              >
                <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
                <UTooltip
                  :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  <span class="cursor-help">{{ moment.unix(item.updated_at).fromNow() }}</span>
                </UTooltip>
              </div>
            </div>
          </template>
        </UCard>
      </Lazy>
    </div>

    <UAlert
      v-else-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="hasLoaded"
      color="success"
      variant="soft"
      icon="i-lucide-circle-check"
      title="Success"
    >
      <template #description>
        <span>
          WatchState checked <strong>{{ counts.local }}</strong> local items against
          <strong>{{ remote.name }}</strong>
          <template v-if="remote.library?.title"
            >library <strong>{{ remote.library.title }}</strong></template
          >
          with <strong>{{ counts.remote }}</strong> remote items and found no stale local
          references.
        </span>
      </template>
    </UAlert>

    <UCard class="shadow-sm" :ui="tipsCardUi">
      <template #header>
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 text-left"
          @click="show_page_tips = !show_page_tips"
        >
          <span class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted">
            <UIcon name="i-lucide-info" class="size-4 text-toned" />
            <span>Tips</span>
          </span>

          <span class="inline-flex items-center gap-1 text-xs font-medium text-toned">
            <UIcon
              :name="show_page_tips ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
              class="size-4"
            />
            <span>{{ show_page_tips ? 'Hide' : 'Show' }}</span>
          </span>
        </button>
      </template>

      <ul v-if="show_page_tips" class="list-disc space-y-2 pl-5 text-sm leading-6 text-default">
        <li>This page compares local database references against the selected backend library.</li>
        <li>Remote data is cached in memory to speed up reloads.</li>
        <li>
          Stale references are usually harmless, but workflows like webhook pushes can fail if they
          still point to items that no longer exist remotely.
        </li>
      </ul>
    </UCard>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useHead } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import FloatingImage from '~/components/FloatingImage.vue';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import {
  request,
  copyText,
  makeName,
  makeSearchLink,
  notification,
  TOOLTIP_DATE_FORMAT,
  parse_api_response,
} from '~/utils';
import type {
  StaleItem,
  StaleResponse,
  StaleCounts,
  StaleBackendInfo,
  ExpandableUIState,
} from '~/types';

const resultCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
  footer: 'border-t border-default px-4 py-4',
};

const tipsCardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const route = useRoute();
const id = route.params.id as string;
const backend = route.params.backend as string;
const pageShell = requireTopLevelPageShell('backends');

type StaleItemWithUI = StaleItem & ExpandableUIState;

const items = ref<Array<StaleItemWithUI>>([]);
const remote = ref<StaleBackendInfo>({} as StaleBackendInfo);
const counts = ref<StaleCounts>({ remote: 0, local: 0, stale: 0 });
const isLoading = ref<boolean>(false);
const hasLoaded = ref<boolean>(false);
const show_page_tips = useStorage('show_page_tips', true);
const poster_enable = useStorage('poster_enable', true);

useHead({ title: `Backends: ${backend} - Staleness` });

const loadContent = async (): Promise<void> => {
  isLoading.value = true;

  try {
    const response = await request(`/backend/${backend}/stale/${id}`);
    const data = await parse_api_response<StaleResponse>(response);

    if ('error' in data) {
      notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
      return;
    }

    items.value = data.items;

    remote.value = data.backend;
    counts.value = data.counts;
    hasLoaded.value = true;

    useHead({
      title: `Backends: ${backend} - ${data.backend.library?.title ?? route.query.name ?? id} Staleness`,
    });
  } catch (e) {
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    notification('error', 'Error', `Request error. ${errorMessage}`);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async (): Promise<void> => await loadContent());
</script>
