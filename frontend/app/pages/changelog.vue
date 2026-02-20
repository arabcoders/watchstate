<style scoped>
.logs-container {
  padding: 1rem;
  min-width: 100%;
  max-height: 73vh;
  overflow-y: auto;
  overflow-x: auto;
}

hr {
  background-color: unset;
  border-bottom: 1px solid var(--bulma-grey-light) !important;
}
</style>

<template>
  <main>
    <div class="mt-1 columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-cogs" /></span>
          CHANGELOG
        </span>

        <div class="is-hidden-mobile">
          <span class="subtitle"
            >This page display the latest changes and updates from the project.</span
          >
        </div>
      </div>
    </div>

    <div class="columns is-multiline" v-if="isLoading">
      <div class="column is-12">
        <Message
          message_class="has-background-info-90 has-text-dark"
          title="Loading"
          icon="fas fa-spinner fa-spin"
          message="Loading data. Please wait..."
        />
      </div>
    </div>

    <template v-if="!isLoading">
      <div class="logs-container">
        <div class="columns is-multiline">
          <div class="column p-0 m-0 is-12" v-for="(log, index) in logs" :key="log.tag">
            <div class="content p-0 m-0">
              <h1 class="is-4">
                <span class="icon"><i class="fas fa-code-branch" /></span>
                {{ log.tag }}
                <span class="tag has-text-success" v-if="isInstalled(log)">Installed</span>
              </h1>
              <hr />
              <ul>
                <li v-for="commit in log.commits" :key="commit.sha">
                  <strong>{{ ucFirst(commit.message).replace(/\.$/, '') }}.</strong> -
                  <small>
                    <NuxtLink :to="`${REPO}/commit/${commit.full_sha}`" target="_blank">
                      <span
                        class="has-tooltip"
                        v-tooltip="`SHA: ${commit.full_sha} - Date: ${commit.date}`"
                      >
                        {{ moment(commit.date).fromNow() }}
                      </span>
                    </NuxtLink>
                  </small>
                </li>
              </ul>
              <hr v-if="index < logs.length - 1" />
            </div>
          </div>
        </div>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { useHead } from '#app';
import { request, disableOpacity, enableOpacity, ucFirst } from '~/utils';
import moment from 'moment';
import Message from '~/components/Message.vue';
import type { ChangeSet, VersionResponse } from '~/types';

useHead({ title: 'CHANGELOG' });

const PROJECT = 'watchstate';
const REPO = `https://github.com/arabcoders/${PROJECT}`;
const REPO_URL = `https://arabcoders.github.io/${PROJECT}/CHANGELOG.json?version={version}`;

const logs = ref<Array<ChangeSet>>([]);
const api_version = ref<string>('dev-master');
const api_version_sha = ref<string>('unknown');
const api_version_build = ref<string>('unknown');
const api_version_branch = ref<string>('unknown');
const isLoading = ref<boolean>(false);

const isInstalled = (log: ChangeSet): boolean => {
  const installed = String(api_version_sha.value);

  if (log.full_sha.startsWith(installed)) {
    return true;
  }

  for (const commit of log?.commits ?? []) {
    if (commit.full_sha.startsWith(installed)) {
      return true;
    }
  }

  return false;
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
      const json = await parse_api_response<Array<ChangeSet>>(changes);
      if ('error' in json) {
        throw new Error(json.error.message || 'Failed to fetch changelog information');
      }
      logs.value = json;
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
  disableOpacity();
  await loadContent();
});

onUnmounted(() => enableOpacity());
</script>
