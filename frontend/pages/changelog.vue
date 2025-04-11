<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-cogs"></i></span>
          CHANGELOG
        </span>
        <div class="is-pulled-right" v-if="!isLoading">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="toggleFilter">
              <input type="search" v-model.lazy="query" class="input" id="filter" placeholder="Filter">
              <span class="icon is-left"><i class="fas fa-filter"/></span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" v-tooltip.bottom="'Filter log lines.'"
                      @click="toggleFilter = !toggleFilter">
                <span class="icon"><i class="fas fa-filter"/></span>
              </button>
            </div>
          </div>
        </div>

        <div class="is-hidden-mobile">
          <span class="subtitle">This page displays all the application change logs.</span>
        </div>
      </div>
    </div>

    <div class="columns is-multiline" v-if="isLoading">
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
      </div>
    </div>

    <template v-if="!isLoading">
      <div class="logs-container">
        <div class="columns is-multiline">
          <div class="column is-12" v-for="(log, index) in logs" :key="log.tag">
            <div class="content">
              <h1 class="is-4">
                <span class="icon"><i class="fas fa-code-branch"/></span>
                {{ formatTag(log.tag) }} <span class="tag has-text-success" v-if="isInstalled(log.tag)">Installed</span>
              </h1>
              <hr>
              <ul>
                <li v-for="commit in log.commits" :key="commit.sha">
                  <strong>{{ ucFirst(commit.message).replace(/\.$/, "") }}.</strong> -
                  <small>
                    <NuxtLink :to="`https://github.com/arabcoders/watchstate/commit/${commit.sha}`" target="_blank">
                      <span class="has-tooltip" v-tooltip="`SHA: ${commit.sha} - Date: ${commit.date}`">
                        {{ moment(commit.date).fromNow() }}
                      </span>
                    </NuxtLink>
                  </small>
                </li>
              </ul>
              <hr v-if="index < logs.length - 1">
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>


<script setup>
import {disableOpacity, enableOpacity, notification} from '~/utils/index'
import moment from 'moment'
import {NuxtLink} from '#components';
import Message from "~/components/Message.vue";

useHead({title: 'CHANGELOG'})

const REPO_URL = "https://arabcoders.github.io/watchstate/CHANGELOG-{branch}.json?version={version}";
const logs = ref([]);
const api_version = ref('master');
const isLoading = ref(false);

const branch = computed(() => {
  const branch = String(api_version.value).split('-')[0] ?? 'master';
  return ['master', 'dev'].includes(branch) ? branch : 'master';
});

const toggleFilter = ref(false)
const query = ref()

const loadContent = async () => {
  isLoading.value = true;
  try {
    try {
      const response = await request('/system/version');
      const json = await response.json();
      api_version.value = json.version;
    } catch (e) {
      console.error(e);
      notification('error', 'Error', `Failed to fetch version. ${e.message}`);
    }

    try {
      const changes = await fetch(r(REPO_URL, {branch: branch.value, version: api_version.value}));
      logs.value = await changes.json();
    } catch (e) {
      console.error(e);
      notification('error', 'Error', `Failed to fetch changelog. ${e.message}`);
    }
  } finally {
    isLoading.value = false;
  }
}

const formatTag = tag => {
  const parts = tag.split('-');
  if (parts.length < 3) {
    return tag;
  }
  const branch = parts[0];
  const date = parts[1];
  const shortSha = parts[2].substring(0, 7);
  return `${ucFirst(branch)}: ${moment(date, 'YYYYMMDD').format('YYYY-MM-DD')} - ${shortSha}`;
}

const isInstalled = tag => {
  const installed = String(api_version.value).split('-').pop();
  return tag.endsWith(installed);
}

onMounted(async () => {
  disableOpacity()
  await loadContent()
});
onUnmounted(() => enableOpacity())
</script>

<style scoped>
.logs-container {
  padding: 1rem;
  min-width: 100%;
  max-height: 73vh;
  overflow-y: auto;
  overflow-x: auto;
}

hr {
  border-bottom: 1px solid #b2d1ff;
}
</style>
