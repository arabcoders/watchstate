<template>
  <main class="w-full min-w-0 max-w-full space-y-8">
    <div class="space-y-4">
      <PageHeader v-bind="pageShell">
        <template #actions>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-refresh-cw"
            :loading="isLoading"
            :disabled="isLoading || isQueueing"
            @click="loadReport"
          >
            Reload
          </UButton>
          <UDropdownMenu v-if="report" :items="exportItems" :content="{ align: 'end' }">
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
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
            variant="solid"
            size="sm"
            icon="i-lucide-play"
            :loading="isQueueing"
            :disabled="isLoading || isQueueing || true === state?.queued"
            @click="queueReport"
          >
            {{ state?.queued ? 'Queued' : report ? 'Queue refresh' : 'Generate statistics' }}
          </UButton>
        </template>
      </PageHeader>

      <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-toned">
        <div class="flex min-w-0 items-center gap-2">
          <dt>Identity:</dt>
          <dd class="wrap-break-word font-medium text-default">{{ apiUser }}</dd>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <dt>Last completed:</dt>
          <dd>
            <UTooltip
              v-if="report?.completed_at"
              :text="moment.unix(report.completed_at).fromNow()"
            >
              <time :datetime="moment.unix(report.completed_at).toISOString()" class="cursor-help">
                {{ formatDate(report.completed_at) }}
              </time>
            </UTooltip>
            <span v-else>{{ report ? 'Unknown' : 'No report loaded' }}</span>
          </dd>
        </div>
      </dl>
    </div>

    <UAlert
      v-if="state?.queued"
      color="info"
      variant="soft"
      icon="i-lucide-clock-3"
      title="Report queued"
      description="Reload to check for a completed report."
    />

    <UEmpty
      v-if="false === isLoading && !error && !state?.queued && null === report"
      icon="i-lucide-chart-no-axes-column"
      title="No statistics yet"
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
      :ui="{ icon: 'animate-spin' }"
    />

    <template v-if="report">
      <section aria-labelledby="summary-heading" class="space-y-4">
        <h2 id="summary-heading" class="text-lg font-semibold text-highlighted">Summary</h2>
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
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-toned">
          <span class="inline-flex items-center gap-1.5"
            ><UIcon name="i-lucide-circle-play" class="size-4" />In progress</span
          >
          <a
            href="#progress-heading"
            class="font-medium text-default hover:text-primary hover:underline"
            @click="showInProgress('movies')"
            >{{ formatNumber(report.summary.movies.in_progress) }} movies</a
          >
          <a
            href="#progress-heading"
            class="font-medium text-default hover:text-primary hover:underline"
            @click="showInProgress('episodes')"
            >{{ formatNumber(report.summary.episodes.in_progress) }} episodes</a
          >
          <a
            href="#progress-heading"
            class="font-medium text-default hover:text-primary hover:underline"
            @click="showInProgress('shows')"
            >{{ formatNumber(report.summary.shows.in_progress) }} shows</a
          >
        </div>
      </section>

      <UEmpty
        v-if="0 === report.summary.movies.total + report.summary.episodes.total"
        size="sm"
        variant="naked"
        icon="i-lucide-library"
        title="Your library is empty"
        description="Sync your library, then refresh statistics."
      />

      <section aria-labelledby="recent-heading" class="space-y-4">
        <h2
          id="recent-heading"
          class="flex items-center gap-2 text-lg font-semibold text-highlighted"
        >
          <UIcon name="i-lucide-history" class="size-5 text-toned" />Recently watched
        </h2>
        <RecentHistoryCards
          v-if="report.recent.length"
          :key="apiUser"
          :items="recentCards"
          :user="apiUser"
          :poster-enable="posterEnable"
          aria-label="Recently watched titles"
        />
        <UEmpty
          v-else
          size="sm"
          variant="naked"
          icon="i-lucide-history"
          title="No watch dates available"
        />
      </section>

      <template v-if="report.insights">
        <WatchActivityChart :key="apiUser" :activity="report.insights.activity" />

        <div class="grid gap-6 lg:grid-cols-2">
          <section aria-labelledby="top-shows-heading" class="min-w-0 space-y-3">
            <h2
              id="top-shows-heading"
              class="flex items-center gap-2 text-lg font-semibold text-highlighted"
            >
              <UIcon name="i-lucide-trophy" class="size-5 shrink-0 text-toned" />
              Top shows by episodes watched
            </h2>
            <ol
              v-if="report.insights.top_shows.length"
              class="ws-card divide-y divide-default px-4"
            >
              <li
                v-for="(show, index) in report.insights.top_shows"
                :key="show.history_id"
                class="flex items-baseline justify-between gap-x-3 py-2.5 text-sm"
              >
                <div class="flex min-w-0 items-baseline gap-2">
                  <span class="w-5 shrink-0 tabular-nums text-toned" aria-hidden="true"
                    >{{ index + 1 }}.</span
                  >
                  <NuxtLink
                    :to="{ path: '/history', query: { title: show.title, type: 'episode' } }"
                    class="min-w-0 wrap-break-word font-medium text-highlighted hover:text-primary hover:underline"
                    >{{ show.title }}</NuxtLink
                  >
                </div>
                <span class="shrink-0 tabular-nums text-toned">
                  {{ formatNumber(show.watched) }} of {{ formatNumber(show.total) }} synced
                </span>
              </li>
            </ol>
            <UEmpty v-else size="sm" variant="naked" icon="i-lucide-tv" title="No watched shows" />
          </section>

          <section aria-labelledby="movie-decades-heading" class="min-w-0 space-y-3">
            <h2
              id="movie-decades-heading"
              class="flex items-center gap-2 text-lg font-semibold text-highlighted"
            >
              <UIcon name="i-lucide-film" class="size-5 shrink-0 text-toned" />
              Watched movies by release decade
            </h2>
            <ul v-if="report.insights.movie_decades.length" class="ws-card space-y-3 p-4">
              <li
                v-for="item in report.insights.movie_decades"
                :key="item.decade ?? 'unknown'"
                class="space-y-1"
              >
                <div class="flex justify-between gap-2 text-sm">
                  <span class="font-medium text-highlighted">
                    {{ null === item.decade ? 'Unknown release year' : `${item.decade}s` }}
                  </span>
                  <span class="tabular-nums text-toned">{{ formatNumber(item.movies) }}</span>
                </div>
                <UProgress
                  :model-value="item.movies"
                  :max="movieDecadeMax"
                  size="sm"
                  aria-hidden="true"
                />
              </li>
            </ul>
            <UEmpty
              v-else
              size="sm"
              variant="naked"
              icon="i-lucide-film"
              title="No watched movies"
            />
          </section>
        </div>
      </template>

      <section aria-labelledby="progress-heading" class="space-y-4">
        <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
          <div class="space-y-1">
            <h2
              id="progress-heading"
              class="flex items-center gap-2 text-lg font-semibold text-highlighted"
            >
              <UIcon name="i-lucide-tv" class="size-5 text-toned" />Watch progress
            </h2>
            <p class="text-sm text-toned" aria-live="polite">
              {{ formatNumber(progressPage.total) }}
              {{ 1 === progressPage.total ? progressType.slice(0, -1) : progressType
              }}{{ query ? ` matching “${query}”` : '' }}
            </p>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row xl:shrink-0">
            <USelect
              v-model="progressType"
              :items="progressTypeItems"
              size="sm"
              aria-label="Watch progress type"
              class="w-full sm:w-32"
            />
            <UInput
              v-model="query"
              type="search"
              icon="i-lucide-search"
              size="sm"
              placeholder="Find a title"
              aria-label="Find a title"
              class="w-full sm:w-60"
            />
            <USelect
              v-if="'shows' === progressType"
              v-model="status"
              :items="statusItems"
              size="sm"
              aria-label="Show progress filter"
              class="w-full sm:w-44"
            />
          </div>
        </div>
        <div
          v-if="progressPage.items.length"
          class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6"
        >
          <article
            v-for="item in progressPage.items"
            :key="`${apiUser}:${item.type}:${item.history_id}`"
            class="ws-card min-w-0 overflow-hidden bg-elevated/20"
          >
            <NuxtLink
              v-if="posterEnable"
              :to="
                'show' === item.type
                  ? { path: '/history', query: { title: item.title, type: 'episode' } }
                  : `/history/${item.history_id}`
              "
              class="relative block rounded-t-lg p-2 pb-0 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
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
        <UEmpty
          v-else
          size="sm"
          variant="naked"
          icon="i-lucide-tv"
          :title="
            progressItems.length ? 'No titles match these filters' : `No ${progressType} to display`
          "
        >
          <template v-if="progressItems.length" #actions>
            <UButton
              color="neutral"
              variant="outline"
              size="sm"
              icon="i-lucide-filter-x"
              @click="clearProgressFilters"
            >
              Clear filters
            </UButton>
          </template>
        </UEmpty>
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

      <section aria-labelledby="genres-heading" class="space-y-4 border-t border-default pt-6">
        <div class="flex items-start justify-between gap-3">
          <h2
            id="genres-heading"
            class="flex items-center gap-2 text-lg font-semibold text-highlighted"
          >
            <UIcon name="i-lucide-tags" class="size-5 text-toned" />Genres
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
        <UEmpty
          v-else
          size="sm"
          variant="naked"
          icon="i-lucide-tags"
          title="No genres available for watched movies or shows"
        />
        <UButton
          v-if="10 < report.genres.length"
          color="neutral"
          :variant="showAllGenres ? 'soft' : 'outline'"
          size="sm"
          :trailing-icon="showAllGenres ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          :aria-expanded="showAllGenres"
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
  UEmpty,
  UIcon,
  UInput,
  UProgress,
  USelect,
  UTooltip,
} from '#components';
import PageHeader from '~/components/PageHeader.vue';
import Pager from '~/components/Pager.vue';
import RecentHistoryCards from '~/components/RecentHistoryCards.vue';
import StatCard from '~/components/StatCard.vue';
import StatisticsPoster from '~/components/StatisticsPoster.vue';
import WatchActivityChart from '~/components/WatchActivityChart.vue';
import { useUserStatistics } from '~/composables/useUserStatistics';
import type {
  RecentHistoryCard,
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

const recentCards = computed<Array<RecentHistoryCard>>(() =>
  (report.value?.recent ?? []).map(({ played_at, ...item }) => ({
    ...item,
    timestamp: played_at,
    timestampTooltip: formatDate(played_at),
  })),
);

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
const movieDecadeMax = computed(() =>
  Math.max(1, ...(report.value?.insights?.movie_decades ?? []).map((decade) => decade.movies)),
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
