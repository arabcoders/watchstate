<template>
  <main class="w-full min-w-0 max-w-full space-y-6">
    <PageHeader v-bind="pageShell" />

    <section aria-label="Release history" :aria-busy="isLoading" class="space-y-4">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="w-full space-y-1.5 sm:max-w-md">
          <label for="changelog-filter" class="block text-xs font-medium text-toned">
            Search changelog
          </label>
          <UInput
            id="changelog-filter"
            v-model.lazy="query"
            type="search"
            placeholder="Release, change, author or commit"
            icon="i-lucide-search"
            size="sm"
            class="w-full"
          />
        </div>

        <USwitch
          v-model="latestOnly"
          color="primary"
          size="sm"
          label="Latest only"
          :description="`${DEFAULT_LIMIT} most recent releases`"
          :ui="{ root: 'items-center gap-2', wrapper: 'ms-0 text-xs text-toned' }"
        />
      </div>

      <UAlert
        v-if="isLoading"
        color="info"
        variant="soft"
        icon="i-lucide-loader-circle"
        title="Loading changelog"
        :ui="{ icon: 'animate-spin' }"
      />

      <template v-else>
        <UAlert
          v-if="loadError"
          color="error"
          variant="soft"
          icon="i-lucide-triangle-alert"
          title="Unable to load changelog"
          :description="loadError"
        />

        <div v-if="0 < filteredLogs.length" class="divide-y divide-default border-y border-default">
          <section
            v-for="(log, index) in filteredLogs"
            :key="log.tag"
            :aria-labelledby="`changelog-release-${index}`"
            class="py-5"
          >
            <h2>
              <button
                :id="`changelog-release-${index}`"
                type="button"
                :aria-expanded="isReleaseOpen(log.tag)"
                :aria-controls="`changelog-commits-${index}`"
                class="group flex w-full items-start gap-3 rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                @click="toggleRelease(log.tag)"
              >
                <UIcon
                  name="i-lucide-chevron-right"
                  :class="[
                    'mt-0.5 size-5 shrink-0 text-muted transition-transform',
                    isReleaseOpen(log.tag) ? 'rotate-90' : '',
                  ]"
                  aria-hidden="true"
                />
                <span
                  class="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-6 gap-y-2"
                >
                  <span class="flex min-w-0 flex-wrap items-center gap-2">
                    <span
                      class="wrap-anywhere font-mono text-base font-semibold text-highlighted transition-colors group-hover:text-primary"
                    >
                      {{ log.tag }}
                    </span>
                    <UBadge
                      v-if="isInstalledRelease(log)"
                      color="success"
                      variant="soft"
                      size="sm"
                      icon="i-lucide-check"
                    >
                      Installed
                    </UBadge>
                  </span>
                  <span class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-toned">
                    <time v-if="log.date" :datetime="log.date" :title="log.date">
                      {{ moment(log.date).format('MMM D, YYYY') }}
                    </time>
                    <span class="tabular-nums">
                      {{ log.commits?.length || 0 }}
                      {{ 1 === log.commits?.length ? 'commit' : 'commits' }}
                    </span>
                  </span>
                </span>
              </button>
            </h2>

            <div
              :id="`changelog-commits-${index}`"
              :hidden="!isReleaseOpen(log.tag)"
              class="mt-4 sm:ml-8"
            >
              <ul v-if="isReleaseOpen(log.tag)" class="divide-y divide-default">
                <li v-for="commit in log.commits" :key="commit.sha">
                  <Lazy :unrender="true" :min-height="80">
                    <article class="space-y-2 py-3">
                      <NuxtLink
                        :to="`${REPO}/commit/${commit.full_sha}`"
                        target="_blank"
                        class="block wrap-anywhere text-sm font-medium leading-6 text-highlighted transition-colors hover:text-primary focus-visible:outline-none focus-visible:underline"
                      >
                        {{ formatCommitMessage(commit.message) }}
                      </NuxtLink>

                      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-toned">
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                          <UIcon
                            name="i-lucide-user"
                            class="size-3.5 shrink-0 text-muted"
                            aria-hidden="true"
                          />
                          <span class="wrap-anywhere">{{ commit.author }}</span>
                        </span>

                        <UTooltip :text="commit.date">
                          <time :datetime="commit.date" class="cursor-help">
                            {{ moment(commit.date).fromNow() }}
                          </time>
                        </UTooltip>

                        <UTooltip :text="`Commit ${commit.full_sha}`">
                          <NuxtLink
                            :to="`${REPO}/commit/${commit.full_sha}`"
                            target="_blank"
                            :aria-label="`Commit ${commit.full_sha}`"
                            class="inline-flex items-center gap-1.5 font-mono transition-colors hover:text-primary focus-visible:outline-none focus-visible:underline"
                          >
                            <UIcon
                              name="i-lucide-git-commit-horizontal"
                              class="size-3.5 text-muted"
                              aria-hidden="true"
                            />
                            {{ commit.sha }}
                          </NuxtLink>
                        </UTooltip>

                        <span
                          v-if="isInstalledCommit(commit)"
                          class="inline-flex items-center gap-1.5 font-medium text-success"
                        >
                          <UIcon name="i-lucide-check" class="size-3.5" aria-hidden="true" />
                          Installed
                        </span>
                      </div>
                    </article>
                  </Lazy>
                </li>
              </ul>
              <UEmpty
                v-if="isReleaseOpen(log.tag) && 0 === (log.commits?.length ?? 0)"
                size="sm"
                variant="naked"
                icon="i-lucide-git-commit-horizontal"
                title="No commits"
              />
            </div>
          </section>
        </div>

        <UEmpty
          v-else-if="!loadError"
          :icon="query ? 'i-lucide-search' : 'i-lucide-scroll-text'"
          :title="query ? 'No matching changelog entries' : 'No changelog entries'"
        />
      </template>
    </section>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { useHead } from '#app';
import { NuxtLink, UAlert, UBadge, UEmpty, UIcon, UInput, USwitch, UTooltip } from '#components';
import { useStorage } from '@vueuse/core';
import moment from 'moment';
import Lazy from '~/components/Lazy.vue';
import PageHeader from '~/components/PageHeader.vue';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { notification, parse_api_response, request, ucFirst } from '~/utils';
import type { ChangeSet, VersionResponse } from '~/types';

type ChangeCommit = ChangeSet['commits'][number];

useHead({ title: 'CHANGELOG' });

const pageShell = requireTopLevelPageShell('changelog');

const PROJECT = 'watchstate';
const REPO = `https://github.com/arabcoders/${PROJECT}`;
const REPO_URL = `https://arabcoders.github.io/${PROJECT}/CHANGELOG.json?version={version}`;
const DEFAULT_LIMIT = 10;

const logs = ref<Array<ChangeSet>>([]);
const api_version_sha = ref<string>('unknown');
const isLoading = ref<boolean>(true);
const loadError = ref<string>('');
const query = ref<string>('');
const latestOnly = useStorage<boolean>('changelog_latest_only', true);
const openReleases = ref<Array<string>>([]);

const visibleLogs = computed<Array<ChangeSet>>(() =>
  latestOnly.value ? logs.value.slice(0, DEFAULT_LIMIT) : logs.value,
);

const filteredLogs = computed<Array<ChangeSet>>(() => {
  const search = query.value.trim().toLowerCase();

  if (!search) {
    return visibleLogs.value;
  }

  return visibleLogs.value
    .map((log) => {
      const tagMatches = log.tag.toLowerCase().includes(search);
      const filteredCommits =
        log.commits?.filter(
          (commit) =>
            commit.message.toLowerCase().includes(search) ||
            commit.author.toLowerCase().includes(search) ||
            commit.full_sha.toLowerCase().includes(search),
        ) ?? [];

      if (tagMatches || 0 < filteredCommits.length) {
        return { ...log, commits: tagMatches ? log.commits : filteredCommits };
      }

      return null;
    })
    .filter((log): log is ChangeSet => null !== log);
});

const isReleaseOpen = (tag: string): boolean => openReleases.value.includes(tag);

const toggleRelease = (tag: string): void => {
  if (isReleaseOpen(tag)) {
    openReleases.value = openReleases.value.filter((entry) => entry !== tag);
    return;
  }

  openReleases.value = [...openReleases.value, tag];
};

const formatCommitMessage = (message: string): string => {
  return `${ucFirst(message).replace(/\.$/, '')}.`;
};

const normalizeSha = (value: string | null | undefined): string => {
  return String(value ?? '')
    .trim()
    .toLowerCase();
};

const matchesInstalledSha = (value: string | null | undefined): boolean => {
  const installed = normalizeSha(api_version_sha.value);
  const candidate = normalizeSha(value);

  if (!installed || !candidate || 'unknown' === installed) {
    return false;
  }

  return (
    candidate === installed || candidate.startsWith(installed) || installed.startsWith(candidate)
  );
};

const isInstalledCommit = (commit: ChangeCommit): boolean => {
  return matchesInstalledSha(commit.full_sha) || matchesInstalledSha(commit.sha);
};

const isInstalledRelease = (log: ChangeSet): boolean => {
  return 'Unreleased' !== log.tag && matchesInstalledSha(log.full_sha);
};

const openCurrentReleases = (): void => {
  const installedIndex = logs.value.findIndex(
    (log) => matchesInstalledSha(log.full_sha) || log.commits?.some(isInstalledCommit),
  );
  const lastOpenIndex = 0 > installedIndex ? 0 : installedIndex;

  openReleases.value = logs.value.slice(0, lastOpenIndex + 1).map((log) => log.tag);
};

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  loadError.value = '';

  try {
    const response = await request('/system/version');
    const json = await parse_api_response<VersionResponse>(response);

    if ('error' in json) {
      throw new Error(json.error.message || 'Failed to fetch version information');
    }

    api_version_sha.value = json.sha;

    await nextTick();

    try {
      const changes = await fetch(
        REPO_URL.replace('{branch}', json.branch).replace('{version}', json.version),
      );
      const changelog = await parse_api_response<Array<ChangeSet>>(changes);
      if ('error' in changelog) {
        throw new Error(changelog.error.message || 'Failed to fetch changelog information');
      }
      logs.value = changelog;
    } catch (e: unknown) {
      console.error(e);
      logs.value = (
        await (await request('/system/static/CHANGELOG.json', { method: 'GET' })).json()
      ).slice(0, DEFAULT_LIMIT * 2) as Array<ChangeSet>;
    }

    openCurrentReleases();
  } catch (e: unknown) {
    const message = e instanceof Error ? e.message : String(e);
    loadError.value = message;
    notification('error', 'Unable to load changelog', message);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async () => {
  await loadContent();
});
</script>
