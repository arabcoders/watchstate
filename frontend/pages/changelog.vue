<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-cogs"></i></span>
          Change logs
        </span>
        <div class="is-hidden-mobile">
          <span class="subtitle">This page displays the application change logs.</span>
        </div>
      </div>
    </div>
    <div class="column is-12" v-for="(log, index) in logs" :key="log.tag">
      <div class="content">
        <h1 class="is-4">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-code-branch"></i></span>
            <span>
              {{ log.tag }} <span class="subtitle" v-if="log.tag === api_version">Installed</span>
            </span>
          </span>
        </h1>
        <hr>
        <ul>
          <li v-for="commit in log.commits" :key="commit.sha">
            <strong>{{ ucFirst(commit.message).replace(/\.$/, "") }}.</strong> -
            <small>
              <span class="has-tooltip" v-tooltip="`Date: ${commit.date}`">
                {{ moment(commit.date).fromNow() }}
              </span></small>
          </li>
        </ul>
        <hr v-if="index < logs.length - 1">
      </div>
    </div>
  </div>
</template>
<script setup>
import {notification} from '~/utils/index'
import moment from 'moment'

useHead({title: 'Change log'})

const REPO_URL = "https://arabcoders.github.io/watchstate/CHANGELOG-{branch}.json";
const logs = ref([]);
const api_version = ref('master');

const branch = computed(() => {
  const branch = String(api_version.value).split('-')[0] ?? 'master';
  return ['master', 'dev'].includes(branch) ? branch : 'dev';
});

const loadContent = async () => {
  try {
    const response = await request('/system/version');
    const json = await response.json();
    api_version.value = json.version;
  } catch (e) {
    console.error(e);
    notification('error', 'Error', `Failed to fetch version. ${e.message}`);
  }

  try {
    const changes = await fetch(r(REPO_URL, {branch: branch.value}));
    logs.value = await changes.json();
  } catch (e) {
    console.error(e);
    notification('error', 'Error', `Failed to fetch changelog. ${e.message}`);
  }
}

onMounted(() => loadContent());
</script>

