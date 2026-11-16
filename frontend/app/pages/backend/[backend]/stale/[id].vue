<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" page-label="Stale references" description="">
      <template #kicker>
        <NuxtLink to="/backends" class="hover:text-primary">Backends</NuxtLink>
        <span>/</span>
        <NuxtLink
          :to="`/backend/${backend}`"
          class="hover:text-primary normal-case tracking-normal"
          >{{ backend }}</NuxtLink
        >
        <span>/</span>
        <NuxtLink :to="`/backend/${backend}/libraries`" class="hover:text-primary">
          {{ remote.library?.title ?? route.query.name ?? id }}
        </NuxtLink>
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
          label="Refresh"
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
          longer exist in <strong>{{ remote.name }}</strong
          >&nbsp;
          <template v-if="remote.library?.title"
            >library <strong>{{ remote.library.title }}</strong></template
          >.
        </span>
      </template>
    </UAlert>

    <div v-if="0 < items.length" class="ws-card divide-y divide-default">
      <Lazy v-for="item in items" :key="item.id" :unrender="true" :min-height="216">
        <article class="flex items-start gap-3 p-4 sm:p-5">
          <StatisticsPoster
            v-if="poster_enable"
            :key="`${apiUser}:${item.id}`"
            :history-id="Number(item.id)"
            :title="String(makeName(item))"
            :user="apiUser"
            preview
            class="hidden w-16 shrink-0 sm:block"
          />
          <div class="min-w-0 flex-1 space-y-4">
            <div class="space-y-2">
              <h2 class="flex min-w-0 items-start gap-2 text-sm font-semibold text-highlighted">
                <UIcon
                  :name="'episode' === item.type.toLowerCase() ? 'i-lucide-tv' : 'i-lucide-film'"
                  class="mt-0.5 size-4 shrink-0 text-toned"
                />
                <NuxtLink :to="`/history/${item.id}`" class="wrap-anywhere hover:text-primary">{{
                  makeName(item)
                }}</NuxtLink>
              </h2>
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-toned">
                <span class="font-mono">#{{ item.id }}</span>
                <UBadge :color="item.watched ? 'success' : 'neutral'" variant="soft" size="xs">
                  {{ item.watched ? 'Watched' : 'Unwatched' }}
                </UBadge>
                <UTooltip
                  :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
                >
                  <span class="cursor-help">{{ moment.unix(item.updated_at).fromNow() }}</span>
                </UTooltip>
              </div>
            </div>

            <dl class="min-w-0 space-y-3 text-sm">
              <div class="grid min-w-0 gap-1 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start">
                <dt class="text-xs text-muted">Subtitle</dt>
                <dd class="flex min-w-0 items-start gap-2">
                  <NuxtLink
                    :to="makeSearchLink('subtitle', item.content_title ?? item.title)"
                    class="min-w-0 flex-1 hover:text-primary"
                    :class="item.expand_title ? 'wrap-anywhere' : 'truncate'"
                  >
                    {{ item.content_title ?? item.title }}
                  </NuxtLink>
                  <UTooltip :text="item.expand_title ? 'Collapse subtitle' : 'Expand subtitle'">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      :icon="item.expand_title ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                      :aria-label="item.expand_title ? 'Collapse subtitle' : 'Expand subtitle'"
                      :aria-expanded="!!item.expand_title"
                      @click="item.expand_title = !item.expand_title"
                    />
                  </UTooltip>
                  <UTooltip text="Copy subtitle">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      icon="i-lucide-copy"
                      aria-label="Copy subtitle"
                      @click="copyText(item.content_title ?? item.title, false)"
                    />
                  </UTooltip>
                </dd>
              </div>
              <div class="grid min-w-0 gap-1 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start">
                <dt class="text-xs text-muted">File</dt>
                <dd class="flex min-w-0 items-start gap-2">
                  <NuxtLink
                    v-if="item.content_path"
                    :to="makeSearchLink('path', item.content_path)"
                    class="min-w-0 flex-1 font-mono text-xs hover:text-primary"
                    :class="item.expand_path ? 'wrap-anywhere' : 'truncate'"
                  >
                    {{ item.content_path }}
                  </NuxtLink>
                  <span v-else class="flex-1 text-toned">No path</span>
                  <UTooltip :text="item.expand_path ? 'Collapse file path' : 'Expand file path'">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      :icon="item.expand_path ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                      :aria-label="item.expand_path ? 'Collapse file path' : 'Expand file path'"
                      :aria-expanded="!!item.expand_path"
                      :disabled="!item.content_path"
                      @click="item.expand_path = !item.expand_path"
                    />
                  </UTooltip>
                  <UTooltip text="Copy file path">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      icon="i-lucide-copy"
                      aria-label="Copy file path"
                      :disabled="!item.content_path"
                      @click="copyText(item.content_path || '', false)"
                    />
                  </UTooltip>
                </dd>
              </div>
              <div class="grid min-w-0 gap-1 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start">
                <dt class="text-xs text-muted">Metadata from</dt>
                <dd class="flex flex-wrap gap-2">
                  <NuxtLink
                    v-for="reportedBackend in item.reported_by"
                    :key="reportedBackend"
                    :to="`/backend/${reportedBackend}`"
                    class="inline-flex items-center gap-1.5 text-xs text-default hover:text-primary"
                  >
                    <UIcon name="i-lucide-server" class="size-3.5" />
                    {{ reportedBackend }}
                  </NuxtLink>
                  <span v-if="0 === item.reported_by.length" class="text-toned">None</span>
                </dd>
              </div>
              <div
                v-if="0 < item.not_reported_by.length"
                class="grid min-w-0 gap-1 sm:grid-cols-[8rem_minmax(0,1fr)] sm:items-start"
              >
                <dt class="text-xs text-muted">Missing metadata</dt>
                <dd class="flex flex-wrap gap-2">
                  <NuxtLink
                    v-for="missingBackend in item.not_reported_by"
                    :key="missingBackend"
                    :to="`/backend/${missingBackend}`"
                    class="inline-flex items-center gap-1.5 text-xs text-error hover:underline underline-offset-2"
                  >
                    <UIcon name="i-lucide-circle-alert" class="size-3.5" />
                    {{ missingBackend }}
                  </NuxtLink>
                </dd>
              </div>
            </dl>
          </div>
        </article>
      </Lazy>
    </div>

    <UAlert
      v-else-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Checking stale references"
      :ui="{ icon: 'animate-spin' }"
    />

    <UEmpty
      v-else-if="hasLoaded && !loadError"
      icon="i-lucide-circle-check"
      title="No stale references"
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
    </UEmpty>

    <UAlert
      v-if="loadError"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Couldn't check stale references"
      :description="loadError"
    />

    <p v-if="0 < items.length" class="text-sm text-toned">
      Stale references can cause webhook updates to fail when they point to items no longer on the
      backend.
    </p>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useHead } from '#app';
import { NuxtLink, UAlert, UBadge, UButton, UEmpty, UIcon, UTooltip } from '#components';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
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
const loadError = ref('');
const poster_enable = useStorage('poster_enable', true);
const apiUser = useStorage<string>('api_user', 'main');

useHead({ title: `Backends: ${backend} - Staleness` });

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request(`/backend/${backend}/stale/${id}`);
    const data = await parse_api_response<StaleResponse>(response);

    if ('error' in data) {
      loadError.value = `${data.error.code}: ${data.error.message}`;
      notification(
        'error',
        'Stale reference check failed',
        `${data.error.code}: ${data.error.message}`,
      );
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
    loadError.value = errorMessage;
    notification('error', 'Stale reference check failed', errorMessage);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async (): Promise<void> => await loadContent());
</script>
