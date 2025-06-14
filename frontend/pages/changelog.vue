<template>
  <main>
    <div class="mt-1 columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-cogs"/></span>
          CHANGELOG
        </span>

        <div class="is-hidden-mobile">
          <span class="subtitle">This page display the latest changes and updates from the project.</span>
        </div>
      </div>
    </div>

    <div class="columns is-multiline" v-if="isLoading">
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
                 message="Loading data. Please wait..."/>
      </div>
    </div>

    <template v-if="!isLoading">
      <div class="logs-container">
        <div class="columns is-multiline">
          <div class="column p-0 m-0 is-12" v-for="(log, index) in logs" :key="log.tag">
            <div class="content p-0 m-0">
              <h1 class="is-4">
                <span class="icon"><i class="fas fa-code-branch"/></span>
                {{ formatTag(log) }} <span class="tag has-text-success" v-if="isInstalled(log)">Installed</span>
              </h1>
              <hr>
              <ul>
                <li v-for="commit in log.commits" :key="commit.sha">
                  <strong>{{ ucFirst(commit.message).replace(/\.$/, "") }}.</strong> -
                  <small>
                    <NuxtLink :to="`${REPO}/commit/${commit.full_sha}`" target="_blank">
                      <span class="has-tooltip" v-tooltip="`SHA: ${commit.full_sha} - Date: ${commit.date}`">
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
  </main>
</template>

<script setup>
import {disableOpacity, enableOpacity, notification} from '~/utils/index'
import moment from 'moment'
import {NuxtLink} from '#components'
import Message from "~/components/Message.vue"

useHead({title: 'CHANGELOG'})

const PROJECT = 'watchstate'
const REPO = `https://github.com/arabcoders/${PROJECT}`
const REPO_URL = `https://arabcoders.github.io/${PROJECT}/CHANGELOG-{branch}.json?version={version}`

const logs = ref([])
const hashLength = ref(7)
const api_version = ref('master')
const isLoading = ref(false)

const branch = computed(() => {
  const branch = String(api_version.value).split('-')[0] ?? 'master'
  return ['master', 'dev'].includes(branch) ? branch : 'master'
})

const formatTag = log => {
  const parts = log.tag.split('-')
  if (parts.length < 3) {
    return log.tag
  }

  const branch = parts[0]
  const date = parts[1]
  const shortSha = log.full_sha.substring(0, hashLength.value)
  return `${ucFirst(branch)}: ${moment(date, 'YYYYMMDD').format('YYYY-MM-DD')} - ${shortSha}`
}

const isInstalled = log => {
  const installed = String(api_version.value).split('-').pop()

  if (log.full_sha.startsWith(installed)) {
    return true
  }

  for (const commit of log?.commits ?? []) {
    if (commit.full_sha.startsWith(installed)) {
      return true
    }
  }

  return false
}

const loadContent = async () => {
  isLoading.value = true
  try {
    const q_version = useRoute().query?.version ?? null
    if (null === q_version) {
      try {
        const response = await request('/system/version')
        const json = await response.json()
        api_version.value = json.version
        hashLength.value = json.version.split('-').pop().length
      } catch (e) {
        console.error(e)
        notification('error', 'Error', `Failed to fetch version. ${e.message}`)
      }
    } else {
      console.log(`Using version from query: ${q_version}`)
      api_version.value = q_version
      hashLength.value = q_version.split('-').pop().length
    }

    try {
      await nextTick()
      const changes = await fetch(REPO_URL.replace('{branch}', branch.value).replace('{version}', api_version.value))
      logs.value = await changes.json()
    } catch (e) {
      console.error(e)
      notification('error', 'Error', `Failed to fetch changelog. ${e.message}`)
    }
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  disableOpacity()
  await loadContent()
})
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
  background-color: unset;
  border-bottom: 1px solid var(--bulma-grey-light) !important
}
</style>
