<template>
  <main class="w-full min-w-0 max-w-full space-y-4">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
      <div class="space-y-1">
        <div
          class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.2em] text-toned"
        >
          <UIcon :name="pageShell.icon" class="size-4" />
          <span>{{ pageShell.sectionLabel }}</span>
          <span>/</span>
          <span>{{ pageShell.pageLabel }}</span>
        </div>
      </div>

      <div v-if="logs.length > 0" class="flex flex-wrap items-center justify-end gap-2">
        <UInput
          v-if="showFilter || query"
          id="filter"
          v-model.lazy="query"
          type="search"
          placeholder="Filter changelog entries"
          icon="i-lucide-filter"
          size="sm"
          class="w-full sm:w-72"
        />

        <UButton
          color="neutral"
          :variant="showFilter ? 'soft' : 'outline'"
          size="sm"
          icon="i-lucide-filter"
          @click="toggleFilter"
          label="Filter"
        />
      </div>
    </div>

    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      icon="i-lucide-loader-circle"
      title="Loading"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="filteredLogs.length < 1"
      color="warning"
      variant="soft"
      icon="i-lucide-triangle-alert"
      :title="query ? 'Search results' : 'No changelog entries'"
    >
      <template #description>
        <div class="space-y-2 text-sm text-default">
          <p v-if="query">
            No changelog entries match <strong>{{ query }}</strong
            >.
          </p>
          <p v-else>No changelog entries are available right now.</p>
        </div>
      </template>
    </UAlert>

    <div v-else class="space-y-4">
      <UCard
        v-for="log in filteredLogs"
        :key="log.tag"
        class="border border-default/70 bg-default/90 shadow-sm"
        :ui="cardUi"
      >
        <template #header>
          <div class="flex items-center justify-between gap-3">
            <div
              class="inline-flex min-w-0 items-center gap-2 text-base font-semibold text-highlighted"
            >
              <UIcon name="i-lucide-git-branch" class="size-4 shrink-0 text-toned" />
              <UTooltip :text="String(log.tag)">
                <span class="truncate">{{ log.tag }}</span>
              </UTooltip>
            </div>

            <div class="flex items-center gap-2">
              <UBadge
                v-if="isInstalled(log)"
                color="success"
                variant="soft"
                icon="i-lucide-badge-check"
                label="Installed"
              />

              <UButton
                color="neutral"
                variant="outline"
                size="sm"
                :icon="isCollapsed(log) ? 'i-lucide-chevron-down' : 'i-lucide-chevron-up'"
                :label="isCollapsed(log) ? 'Expand' : 'Collapse'"
                @click="toggleCollapsed(log)"
              />
            </div>
          </div>
        </template>

        <div v-if="!isCollapsed(log)" class="space-y-3">
          <div
            v-for="commit in log.commits"
            :key="commit.sha"
            class="rounded-md border border-default bg-elevated/40 px-3 py-3 text-sm text-default"
          >
            <div class="flex items-start gap-2">
              <UIcon
                name="i-lucide-git-commit-horizontal"
                class="mt-0.5 size-4 shrink-0 text-toned"
              />

              <div class="min-w-0 flex-1">
                <div class="leading-6">
                  <NuxtLink
                    :to="`${REPO}/commit/${commit.full_sha}`"
                    target="_blank"
                    class="font-medium text-highlighted hover:text-primary hover:underline"
                  >
                    {{ ucFirst(commit.message).replace(/\.$/, '') }}.
                  </NuxtLink>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-toned">
                  <UBadge color="neutral" variant="outline" size="sm" icon="i-lucide-user">
                    {{ commit.author }}
                  </UBadge>

                  <UTooltip :text="`${commit.full_sha}`" class="cursor-pointer">
                    <UBadge
                      color="neutral"
                      variant="outline"
                      size="sm"
                      icon="i-lucide-git-commit-horizontal"
                    >
                      {{ commit.sha }}
                    </UBadge>
                  </UTooltip>

                  <UTooltip :text="`${commit.date}`" class="cursor-pointer">
                    <UBadge color="neutral" variant="outline" size="sm" icon="i-lucide-clock-3">
                      {{ moment(commit.date).fromNow() }}
                    </UBadge>
                  </UTooltip>

                  <UBadge
                    v-if="isInstalledCommit(commit)"
                    color="success"
                    variant="soft"
                    size="sm"
                    icon="i-lucide-badge-check"
                  >
                    Installed
                  </UBadge>
                </div>
              </div>
            </div>
          </div>
        </div>
      </UCard>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { useHead } from '#app';
import { requireTopLevelPageShell } from '~/utils/topLevelNavigation';
import { awaitElement, parse_api_response, request, ucFirst } from '~/utils';
import moment from 'moment';
import type { ChangeSet, VersionResponse } from '~/types';

type FilteredChangeSet = ChangeSet;
type ChangeCommit = ChangeSet['commits'][number];

useHead({ title: 'CHANGELOG' });

const pageShell = requireTopLevelPageShell('changelog');

const PROJECT = 'watchstate';
const REPO = `https://github.com/arabcoders/${PROJECT}`;
const REPO_URL = `https://arabcoders.github.io/${PROJECT}/CHANGELOG.json?version={version}`;

const logs = ref<Array<ChangeSet>>([]);
const api_version = ref<string>('dev-master');
const api_version_sha = ref<string>('unknown');
const api_version_build = ref<string>('unknown');
const api_version_branch = ref<string>('unknown');
const isLoading = ref<boolean>(false);
const query = ref<string>('');
const showFilter = ref<boolean>(false);
const collapsedLogs = ref<Record<string, boolean>>({});

const cardUi = {
  header: 'p-4',
  body: 'px-4 pb-4 pt-0',
};

const filteredLogs = computed<Array<FilteredChangeSet>>(() => {
  const search = query.value.trim().toLowerCase();

  if (!search) {
    return logs.value;
  }

  return logs.value.flatMap((log) => {
    if (log.tag.toLowerCase().includes(search)) {
      return [log];
    }

    const commits = log.commits.filter((commit) => {
      return [commit.message, commit.author, commit.sha, commit.full_sha, commit.date].some(
        (value) => value.toLowerCase().includes(search),
      );
    });

    if (commits.length < 1) {
      return [];
    }

    return [{ ...log, commits }];
  });
});

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

const logKey = (log: ChangeSet): string => {
  return log.full_sha || log.tag;
};

const isCollapsed = (log: ChangeSet): boolean => {
  return true === collapsedLogs.value[logKey(log)];
};

const toggleCollapsed = (log: ChangeSet): void => {
  const key = logKey(log);

  collapsedLogs.value = {
    ...collapsedLogs.value,
    [key]: !isCollapsed(log),
  };
};

const isInstalled = (log: ChangeSet): boolean => {
  if (matchesInstalledSha(log.full_sha)) {
    return true;
  }

  for (const commit of log?.commits ?? []) {
    if (isInstalledCommit(commit)) {
      return true;
    }
  }

  return false;
};

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;

  if (!showFilter.value) {
    query.value = '';
    return;
  }

  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const loadContent = async (): Promise<void> => {
  isLoading.value = true;
  try {
    const response = await request('/system/version');
    const json = await parse_api_response<VersionResponse>(response);

    if ('error' in json) {
      throw new Error(json.error.message || 'Failed to fetch version information');
    }

    api_version.value = json.version;
    api_version_sha.value = json.sha;
    api_version_build.value = json.build;
    api_version_branch.value = json.branch;

    await nextTick();

    try {
      const changes = await fetch(
        REPO_URL.replace('{branch}', api_version_branch.value).replace(
          '{version}',
          api_version.value,
        ),
      );
      const changelog = await parse_api_response<Array<ChangeSet>>(changes);
      if ('error' in changelog) {
        throw new Error(changelog.error.message || 'Failed to fetch changelog information');
      }
      logs.value = changelog;
    } catch (e: unknown) {
      logs.value = (await (
        await request('/system/static/CHANGELOG.json', { method: 'GET' })
      ).json()) as Array<ChangeSet>;
      console.error(e);
    }

    await nextTick();

    logs.value = logs.value.slice(0, 10);
  } finally {
    isLoading.value = false;
  }
};

onMounted(async () => {
  await loadContent();
});
</script>
