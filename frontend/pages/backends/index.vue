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
            <NuxtLink :href="`/backend/${backend.name}`">
              {{ backend.name }}
            </NuxtLink>
          </p>
          <span class="card-header-icon" v-tooltip="'Edit Backend settings'">
            <NuxtLink :href="`/backend/${backend.name}/edit`">
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
            <a :href="api_url + backend.urls.webhook" class="is-info is-light" @click.prevent="copyUrl(backend)">
              <span class="icon"><i class="fas fa-copy"></i></span>
              <span class="is-hidden-mobile">Copy Webhook URL</span>
              <span class="is-hidden-tablet">Webhook</span>
            </a>
          </div>
        </footer>
      </div>
    </div>

    <div class="column is-12" v-if="backends.length>0">
      <div class="content">
        <ul>
          <li>
            Import In context of <code>WatchState</code> means pulling data from the backends into the local database.
          </li>
          <li>
            Export in context of <code>WatchState</code> means pushing data from the local database to the backends.
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request.js'
import BackendAdd from '~/components/BackendAdd.vue'
import {copyText} from '~/utils/index.js'
import {useStorage} from "@vueuse/core";

useHead({title: 'Backends'})

const backends = ref([])
const toggleForm = ref(false)
const api_url = useStorage('api_url', '')

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
