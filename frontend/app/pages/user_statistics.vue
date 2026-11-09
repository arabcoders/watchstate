<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell">
      <template #kicker>
        <span>{{ pageShell.sectionLabel }}</span>
        <span>/</span>
        <span>{{ pageShell.pageLabel }}</span>
        <span>/</span>
        <span class="text-highlighted">{{ ucFirst(apiUser) }}</span>
      </template>
      <template #actions>
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          :loading="isLoading"
          :disabled="isLoading"
          @click="loadReport"
        >
          Reload
        </UButton>
        <UDropdownMenu v-if="report" :items="exportItems" :content="{ align: 'end' }">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-download"
            trailing-icon="i-lucide-chevron-down"
            :loading="null !== exporting"
            :disabled="null !== exporting"
          >
            Export
          </UButton>
        </UDropdownMenu>
        <UButton
          color="primary"
          icon="i-lucide-play"
          :loading="isQueueing"
          :disabled="isLoading || isQueueing || true === state?.queued"
          @click="queueReport"
        >
          {{ state?.queued ? 'Queued' : report ? 'Refresh statistics' : 'Generate statistics' }}
        </UButton>
      </template>
    </PageHeader>

    <UAlert
      v-if="state?.queued"
      color="info"
      variant="soft"
      icon="i-lucide-clock-3"
      description="Statistics are generated. Reload after a few minutes."
    />

    <UAlert
      v-if="false === isLoading && !error && null === report"
      color="warning"
      variant="soft"
      icon="i-lucide-file-warning"
      title="No generated statistics found"
    />

    <UAlert
      v-if="report && state?.stale"
      color="warning"
      variant="soft"
      icon="i-lucide-refresh-cw"
      title="Statistics need updating"
      description="Refresh to see your latest watch progress."
    />
    <UAlert
      v-if="error"
      color="error"
      variant="soft"
      icon="i-lucide-triangle-alert"
      title="Couldn't load statistics"
      :description="report ? `${error}. Your previous statistics are still shown.` : error"
    />
    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading statistics"
      :description="`Loading statistics for ${ucFirst(apiUser)}.`"
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-if="report">
      <section aria-labelledby="summary-heading" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 id="summary-heading" class="text-base font-semibold text-highlighted">Summary</h2>
          <UTooltip :text="formatDate(report.completed_at)">
            <span class="text-xs text-toned"
              >Updated {{ moment.unix(report.completed_at).fromNow() }}</span
            >
          </UTooltip>
        </div>
        <div class="grid grid-cols-1 gap-3 min-[400px]:grid-cols-2 xl:grid-cols-4">
          <StatCard
            label="Movies watched"
            :value="formatNumber(report.summary.movies.watched)"
            :hint="`of ${formatNumber(report.summary.movies.total)}`"
            icon="i-lucide-film"
            color="success"
          />
          <StatCard
            label="Episodes watched"
            :value="formatNumber(report.summary.episodes.watched)"
            :hint="`of ${formatNumber(report.summary.episodes.total)}`"
            icon="i-lucide-clapperboard"
            color="success"
          />
          <StatCard
            label="Shows started"
            :value="formatNumber(report.summary.shows.started)"
            :hint="`of ${formatNumber(report.summary.shows.total)}`"
            icon="i-lucide-tv"
            color="info"
          />
          <StatCard
            label="Fully watched shows"
            :value="formatNumber(report.summary.shows.fully_watched)"
            icon="i-lucide-circle-check"
            color="success"
          />
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-toned">
          <span class="inline-flex items-center gap-1.5"
            ><UIcon name="i-lucide-circle-play" class="size-4 text-info" />In progress</span
          >
          <a
            href="#progress-heading"
            class="text-primary hover:underline"
            @click="showInProgress('movies')"
            >{{ formatNumber(report.summary.movies.in_progress) }} movies</a
          >
          <a
            href="#progress-heading"
            class="text-primary hover:underline"
            @click="showInProgress('episodes')"
            >{{ formatNumber(report.summary.episodes.in_progress) }} episodes</a
          >
          <a
            href="#progress-heading"
            class="text-primary hover:underline"
            @click="showInProgress('shows')"
            >{{ formatNumber(report.summary.shows.in_progress) }} shows</a
          >
        </div>
      </section>

      <UAlert
        v-if="0 === report.summary.movies.total + report.summary.episodes.total"
        color="neutral"
        variant="soft"
        icon="i-lucide-library"
        title="Your library is empty"
        description="Sync your library, then refresh statistics."
      />

      <section aria-labelledby="recent-heading" class="space-y-4">
        <h2
          id="recent-heading"
          class="flex items-center gap-2 text-base font-semibold text-highlighted"
        >
          <UIcon name="i-lucide-history" class="size-4 text-primary" />Recently watched
        </h2>
        <ul
          v-if="report.recent.length"
          class="flex gap-3 overflow-x-auto pb-3"
          aria-label="Recently watched titles"
          tabindex="0"
        >
          <li
            v-for="item in report.recent"
            :key="`${apiUser}:${item.history_id}`"
            class="group ws-card w-36 shrink-0 space-y-2 p-2 sm:w-40"
          >
            <NuxtLink
              :to="`/history/${item.history_id}`"
              class="block"
              :aria-label="
                'episode' === item.type && null !== item.season && null !== item.episode
                  ? `View ${item.title}, season ${item.season}, episode ${item.episode}`
                  : `View ${item.title}`
              "
            >
              <StatisticsPoster
                v-if="posterEnable"
                :history-id="item.history_id"
                :title="item.title"
                :user="apiUser"
              />
              <p
                class="mt-2 line-clamp-2 min-h-10 text-sm font-medium leading-5 text-highlighted hover:text-primary"
                :title="item.title"
              >
                {{ item.title }}
              </p>
            </NuxtLink>
            <div class="flex items-center justify-between gap-2 text-xs text-toned">
              <span v-if="'movie' === item.type">Movie</span>
              <span v-else-if="null !== item.season && null !== item.episode" class="font-mono">
                {{ item.season }}x{{ String(item.episode).padStart(2, '0') }}
              </span>
              <span v-else>Episode</span>
              <span v-if="item.year">{{ item.year }}</span>
            </div>
            <UTooltip :text="formatDate(item.played_at)"
              ><p class="truncate pb-1 text-xs text-dimmed">
                {{ moment.unix(item.played_at).fromNow() }}
              </p></UTooltip
            >
          </li>
        </ul>
        <UAlert
          v-else
          color="warning"
          variant="soft"
          icon="i-lucide-file-warning"
          title="No watch dates available"
        />
      </section>

      <section aria-labelledby="progress-heading" class="space-y-4">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div class="space-y-1">
            <h2
              id="progress-heading"
              class="flex items-center gap-2 text-base font-semibold text-highlighted"
            >
              <UIcon name="i-lucide-tv" class="size-4 text-primary" />Watch progress
            </h2>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row lg:shrink-0">
            <USelect
              v-model="progressType"
              :items="progressTypeItems"
              aria-label="Watch progress type"
              class="w-full sm:w-32"
            />
            <UInput
              v-model="query"
              type="search"
              icon="i-lucide-search"
              placeholder="Find a title"
              aria-label="Find a title"
              class="w-full sm:w-48"
            />
            <USelect
              v-if="'shows' === progressType"
              v-model="status"
              :items="statusItems"
              aria-label="Show progress filter"
              class="w-full sm:w-44"
            />
          </div>
        </div>
        <p class="text-xs text-toned" aria-live="polite">
          {{ formatNumber(progressPage.total) }}
          {{ 1 === progressPage.total ? progressType.slice(0, -1) : progressType
          }}{{ query ? ` matching “${query}”` : '' }}
        </p>
        <div
          v-if="progressPage.items.length"
          class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6"
        >
          <article
            v-for="item in progressPage.items"
            :key="`${apiUser}:${item.type}:${item.history_id}`"
            class="group ws-card min-w-0 overflow-hidden bg-elevated/20"
          >
            <NuxtLink
              v-if="posterEnable"
              :to="
                'show' === item.type
                  ? { path: '/history', query: { title: item.title, type: 'episode' } }
                  : `/history/${item.history_id}`
              "
              class="relative block p-2 pb-0"
              :aria-label="
                'show' === item.type ? `View episodes of ${item.title}` : `View ${item.title}`
              "
            >
              <StatisticsPoster :history-id="item.history_id" :title="item.title" :user="apiUser" />
              <UBadge
                v-if="'show' !== item.type && 0 < item.progress"
                color="neutral"
                variant="solid"
                size="sm"
                class="absolute bottom-2 right-4 shadow-sm"
                :aria-label="`Playback position ${formatDuration(item.progress)}`"
              >
                <span class="inline-flex items-center gap-1 tabular-nums">
                  <UIcon name="i-lucide-gauge" class="size-3.5" />
                  <span>{{ formatDuration(item.progress) }}</span>
                </span>
              </UBadge>
            </NuxtLink>
            <div class="space-y-3 p-3">
              <div class="space-y-1">
                <NuxtLink
                  :to="
                    'show' === item.type
                      ? { path: '/history', query: { title: item.title, type: 'episode' } }
                      : `/history/${item.history_id}`
                  "
                  :title="item.title"
                  class="line-clamp-2 min-h-10 text-sm font-semibold leading-5 text-highlighted hover:text-primary"
                  >{{ item.title }}</NuxtLink
                >
                <div class="flex items-center gap-2 text-xs text-toned">
                  <span v-if="'movie' === item.type">Movie</span>
                  <template v-else-if="'episode' === item.type">
                    <span v-if="null !== item.season && null !== item.episode" class="font-mono">
                      {{ item.season }}x{{ String(item.episode).padStart(2, '0') }}
                    </span>
                    <span v-else>Episode</span>
                  </template>
                  <span v-if="null !== item.year">{{ item.year }}</span>
                </div>
              </div>
              <UBadge
                :color="
                  'fully_watched' === item.status
                    ? 'success'
                    : 'in_progress' === item.status
                      ? 'info'
                      : 'neutral'
                "
                variant="soft"
                size="sm"
              >
                <span class="inline-flex items-center gap-1">
                  <UIcon :name="statusIcons[item.status]" class="size-3.5" />
                  <span>{{ statusLabels[item.status] }}</span>
                </span>
              </UBadge>
              <UBadge
                v-if="!posterEnable && 'show' !== item.type && 0 < item.progress"
                color="neutral"
                variant="soft"
                size="sm"
                :aria-label="`Playback position ${formatDuration(item.progress)}`"
              >
                <span class="inline-flex items-center gap-1 tabular-nums">
                  <UIcon name="i-lucide-gauge" class="size-3.5" />
                  <span>{{ formatDuration(item.progress) }}</span>
                </span>
              </UBadge>
              <div v-if="'show' === item.type" class="space-y-2">
                <UProgress
                  :model-value="item.watched"
                  :max="Math.max(1, item.total)"
                  :color="'fully_watched' === item.status ? 'success' : 'primary'"
                  size="xs"
                  :aria-label="`${item.title}: ${item.watched} of ${item.total} episodes watched`"
                />
                <p class="text-xs tabular-nums text-toned">
                  {{ formatNumber(item.watched) }} / {{ formatNumber(item.total) }} watched
                </p>
                <p v-if="0 < item.in_progress" class="text-xs text-info">
                  {{ formatNumber(item.in_progress) }} episodes in progress
                </p>
              </div>
              <UTooltip
                v-if="'show' === item.type && null !== item.last_played_at"
                :text="formatDate(item.last_played_at)"
                ><p class="truncate text-xs text-dimmed">
                  Last watched {{ moment.unix(item.last_played_at).fromNow() }}
                </p></UTooltip
              >
            </div>
          </article>
        </div>
        <UAlert
          v-else
          color="warning"
          variant="soft"
          icon="i-lucide-file-warning"
          :title="
            progressItems.length
              ? 'No titles match these filters.'
              : `No ${progressType} to display.`
          "
        >
          <template v-if="progressItems.length" #actions>
            <UButton color="neutral" variant="ghost" size="sm" @click="clearProgressFilters">
              Clear filters
            </UButton>
          </template>
        </UAlert>
        <div
          v-if="1 < progressPage.lastPage"
          class="flex flex-wrap items-center justify-between gap-3"
        >
          <span class="text-xs text-toned"
            >Page {{ progressPage.page }} of {{ formatNumber(progressPage.lastPage) }} · 24
            {{ progressType }} per page</span
          >
          <Pager
            :page="progressPage.page"
            :last_page="progressPage.lastPage"
            @navigate="page = $event"
          />
        </div>
      </section>

      <section aria-labelledby="genres-heading" class="ws-card space-y-5 p-4 sm:p-5">
        <div class="flex items-start justify-between gap-3">
          <h2
            id="genres-heading"
            class="flex items-center gap-2 text-base font-semibold text-highlighted"
          >
            <UIcon name="i-lucide-tags" class="size-4 text-primary" />Genres
          </h2>
          <UBadge color="neutral" variant="soft" class="shrink-0"
            >{{ formatNumber(report.genres.length) }} genres</UBadge
          >
        </div>
        <div v-if="genres.length" class="grid gap-x-8 gap-y-4 md:grid-cols-2">
          <div v-for="genre in genres" :key="genre.name" class="space-y-2">
            <div class="flex items-baseline justify-between gap-3 text-sm">
              <span class="truncate font-medium text-highlighted">{{ genre.name }}</span>
              <UTooltip
                :text="`${formatNumber(genre.movies)} movies · ${formatNumber(genre.shows)} shows`"
                ><span class="shrink-0 tabular-nums text-toned"
                  >{{ formatNumber(genre.total) }} titles</span
                ></UTooltip
              >
            </div>
            <UProgress
              :model-value="genre.total"
              :max="genreMax"
              :aria-label="`${genre.name}: ${genre.total} titles`"
              size="sm"
            />
          </div>
        </div>
        <p v-else class="py-4 text-sm text-toned">
          No genres available for watched movies or shows.
        </p>
        <UButton
          v-if="10 < report.genres.length"
          color="neutral"
          variant="ghost"
          size="sm"
          :trailing-icon="showAllGenres ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          @click="showAllGenres = !showAllGenres"
          >{{
            showAllGenres ? 'Show top 10' : `Show all ${formatNumber(report.genres.length)} genres`
          }}</UButton
        >
      </section>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useHead } from '#app';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import {
  NuxtLink,
  UAlert,
  UBadge,
  UButton,
  UDropdownMenu,
  UIcon,
  UInput,
  UProgress,
  USelect,
  UTooltip,
} from '#components';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import StatCard from '~/components/StatCard.vue';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import { useUserStatistics } from '~/composables/useUserStatistics';
import type {
  UserStatisticsProgressItem,
  UserStatisticsShow,
  UserStatisticsShowFilter,
} from '~/types';
import { formatDuration, ucFirst } from '~/utils';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';

const pageShell = requireTopLevelPageShell('user-statistics');
const apiUser = useStorage<string>('api_user', 'main');
const posterEnable = useStorage<boolean>('poster_enable', true);
const {
  state,
  report,
  isLoading,
  isQueueing,
  exporting,
  error,
  loadReport,
  queueReport,
  exportReport,
} = useUserStatistics(apiUser);
const query = ref('');
const progressType = ref<'movies' | 'episodes' | 'shows'>('shows');
const status = ref<UserStatisticsShowFilter>('all');
const page = ref(1);
const showAllGenres = ref(false);
const formatNumber = (value: number): string => new Intl.NumberFormat().format(value);
const formatDate = (value: number): string => moment.unix(value).format('YYYY-MM-DD HH:mm:ss Z');

useHead(() => ({ title: `${ucFirst(apiUser.value)} @ User Statistics` }));

const statusLabels: Record<UserStatisticsShow['status'], string> = {
  fully_watched: 'Fully watched',
  in_progress: 'In progress',
  not_started: 'Not started',
};
const statusIcons: Record<UserStatisticsShow['status'], string> = {
  fully_watched: 'i-lucide-circle-check',
  in_progress: 'i-lucide-circle-play',
  not_started: 'i-lucide-circle',
};
const statusItems = [
  { label: 'All shows', value: 'all' },
  { label: 'Fully watched', value: 'fully_watched' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Not started', value: 'not_started' },
];
const progressTypeItems = [
  { label: 'Movies', value: 'movies' },
  { label: 'Episodes', value: 'episodes' },
  { label: 'Shows', value: 'shows' },
];
const progressItems = computed<
  Array<
    | (UserStatisticsShow & { type: 'show' })
    | (UserStatisticsProgressItem & { status: 'in_progress' })
  >
>(() => {
  if ('shows' === progressType.value) {
    return (report.value?.shows ?? []).map((show) => ({ ...show, type: 'show' }));
  }

  return (report.value?.in_progress[progressType.value] ?? []).map((item) => ({
    ...item,
    status: 'in_progress',
  }));
});
const progressPage = computed(() => {
  const search = query.value.trim().toLocaleLowerCase();
  const filtered = progressItems.value.filter((item) => {
    return (
      ('show' !== item.type || 'all' === status.value || status.value === item.status) &&
      ('' === search || item.title.toLocaleLowerCase().includes(search))
    );
  });
  const lastPage = Math.max(1, Math.ceil(filtered.length / 24));
  const currentPage = Math.max(1, Math.min(page.value, lastPage));
  const offset = (currentPage - 1) * 24;

  return {
    items: filtered.slice(offset, offset + 24),
    total: filtered.length,
    page: currentPage,
    lastPage,
  };
});
const genres = computed(() =>
  showAllGenres.value ? (report.value?.genres ?? []) : (report.value?.genres ?? []).slice(0, 10),
);
const genreMax = computed(() =>
  Math.max(1, ...(report.value?.genres ?? []).map((genre) => genre.total)),
);
const exportItems = computed(() => [
  {
    label: 'Download JSON',
    icon: 'i-lucide-braces',
    onSelect: () => void exportReport('json'),
  },
  {
    label: 'Download Markdown',
    icon: 'i-lucide-file-text',
    onSelect: () => void exportReport('markdown'),
  },
]);

const clearProgressFilters = (): void => {
  query.value = '';
  status.value = 'all';
};

const showInProgress = (kind: typeof progressType.value): void => {
  progressType.value = kind;
  query.value = '';
  status.value = 'in_progress';
  page.value = 1;
};

watch([query, status, progressType], () => {
  page.value = 1;
});
watch(
  apiUser,
  () => {
    progressType.value = 'shows';
    clearProgressFilters();
    page.value = 1;
    showAllGenres.value = false;
  },
  { flush: 'sync' },
);
</script>
