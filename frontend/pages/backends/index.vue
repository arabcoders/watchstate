<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">Backends</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" v-tooltip="'Add New Backend'" @click="toggleForm = !toggleForm">
              <span class="icon"><i class="fas fa-add"></i></span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click.prevent="loadContent">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">This page contains all the backends that are currently configured.</span>
      </div>
    </div>

    <div class="column is-12" v-if="toggleForm">
      <BackendAdd @addBackend="toggleForm = false; loadContent()" :backends="backends"/>
    </div>
    <template v-else>
      <div class="column is-12" v-if="backends.length<1 && !toggleForm">
        <Message message_class="is-warning" title="Warning">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-exclamation"></i></span>
            <span>
              No backends found. Please add new backends to start using the tool. You can add new backend by
              <NuxtLink @click="toggleForm=true" v-text="'clicking here'"/>
              or by clicking the <span class="icon is-small"><i class="fas fa-add"></i></span> button above.
            </span>
          </span>
        </Message>
      </div>

      <div v-for="backend in backends" :key="backend.name" class="column is-6-tablet is-12-mobile">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title">
              <NuxtLink :to="`/backend/${backend.name}`">
                {{ backend.name }}
              </NuxtLink>
            </p>
            <span class="card-header-icon" v-tooltip="'Edit Backend settings'">
              <NuxtLink :to="`/backend/${backend.name}/edit`">
                <span class="icon"><i class="fas fa-cog"></i></span>
              </NuxtLink>
            </span>
          </header>
          <div class="card-content">
            <div class="columns is-multiline has-text-centered">
              <div class="column is-6 has-text-left-mobile" v-if="backend.export.enabled">
                <strong>Last Export:</strong>
                {{ backend.export.lastSync ? moment(backend.export.lastSync).fromNow() : 'None' }}
              </div>
              <div class="column is-6 has-text-left-mobile" v-if="backend.import.enabled">
                <strong>Last Import:</strong>
                {{ backend.import.lastSync ? moment(backend.import.lastSync).fromNow() : 'None' }}
              </div>
            </div>
          </div>
          <footer class="card-footer">
            <div class="card-footer-item">
              <div class="field">
                <input :id="backend.name+'_export'" type="checkbox" class="switch is-success"
                       :checked="backend.export.enabled"
                       @change="updateValue(backend, 'export.enabled', !backend.export.enabled)">
                <label :for="backend.name+'_export'">Export</label>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="field">
                <input :id="backend.name+'_import'" type="checkbox" class="switch is-success"
                       :checked="backend.import.enabled"
                       @change="updateValue(backend, 'import.enabled',!backend.import.enabled)">
                <label :for="backend.name+'_import'">Import</label>
              </div>
            </div>
            <div class="card-footer-item">
              <NuxtLink :to="api_url + backend.urls.webhook" class="is-info is-light" @click.prevent="copyUrl(backend)">
                <span class="icon"><i class="fas fa-copy"></i></span>
                <span class="is-hidden-mobile">Copy Webhook URL</span>
                <span class="is-hidden-tablet">Webhook</span>
              </NuxtLink>
            </div>
          </footer>
        </div>
      </div>
    </template>

    <div class="column is-12">
      <Message message_class="has-background-info-90 has-text-dark">
        <div class="is-pulled-right">
          <NuxtLink @click="show_page_tips=false" v-if="show_page_tips">
            <span class="icon"><i class="fas fa-arrow-up"></i></span>
            <span>Close</span>
          </NuxtLink>
          <NuxtLink @click="show_page_tips=true" v-else>
            <span class="icon"><i class="fas fa-arrow-down"></i></span>
            <span>Open</span>
          </NuxtLink>
        </div>
        <h5 class="title is-5 is-unselectable">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-info-circle"></i></span>
            <span>Tips</span>
          </span>
        </h5>
        <div class="content" v-if="show_page_tips">
          <ul>
            <li>
              <strong>Import</strong> means pulling data from the backends into the local database.
            </li>
            <li>
              <strong>Export</strong> means pushing data from the local database to the backends.
            </li>
            <li>
              WatchState is single user tool. It doesn't support syncing multiple users play state.
              <NuxtLink target="_blank" v-text="'Visit this link'"
                        to="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#is-there-support-for-multi-user-setup"/>
              to learn more.
            </li>
            <li>
              If you are adding new backend that is fresh and doesn't have your correct watch state, you should
              turn off import and enable only metadata import at the start to prevent overriding your current play
              state.
              <NuxtLink
                  to="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#my-new-backend-overriding-my-old-backend-state--my-watch-state-is-not-correct"
                  target="_blank" v-text="'Visit this link'"/>
              to learn more.
            </li>
            <li>
              Deleting backend is not available via <code>WebUI</code> yet. You can do it via the
              <NuxtLink :to="makeConsoleCommand('config:delete -n -s backend_name')">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-terminal"></i></span>
                  <span>Console</span>
                </span>
              </NuxtLink>
              page, or using the the following command <code>config:delete -s backend_name</code> in shell.
            </li>
          </ul>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request.js'
import BackendAdd from '~/components/BackendAdd.vue'
import {copyText, makeConsoleCommand} from '~/utils/index.js'
import {useStorage} from "@vueuse/core";

useHead({title: 'Backends'})

const backends = ref([])
const toggleForm = ref(false)
const api_url = useStorage('api_url', '')
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  backends.value = []
  const response = await request('/backends')
  backends.value = await response.json()
}

onMounted(() => loadContent())

const copyUrl = (backend) => copyText(api_url.value + backend.urls.webhook)

const updateValue = async (backend, key, newValue) => {
  const response = await request(`/backend/${backend.name}`, {
    method: 'PATCH',
    body: JSON.stringify([{
      "key": key,
      "value": newValue
    }])
  })

  backends.value[backends.value.findIndex(b => b.name === backend.name)] = await response.json()
}

</script>
