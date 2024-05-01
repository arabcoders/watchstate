<template>
  <div class="p-2">
    <span class="title is-4">Backends</span>

    <div class="is-pulled-right">
      <div class="field is-grouped">
        <p class="control">
          <button class="button is-primary" @click.prevent="loadContent">
            <span class="icon is-small">
              <i class="fas fa-sync"></i>
            </span>
          </button>
        </p>
      </div>
    </div>
  </div>

  <div class="columns is-multiline">
    <div v-for="backend in backends" :key="backend.name" class="column is-6-tablet is-12-mobile">
      <div class="card">
        <header class="card-header">
          <div class="card-header-title is-centered is-word-break">
            <NuxtLink :href="'/backends/' + backend.name">
              {{ backend.name }}
            </NuxtLink>
          </div>
        </header>
        <div class="card-content">
          <div class="content">
            <p>
              <strong>Last Import:</strong> {{ moment(backend.import.lastSync).fromNow() }}
            </p>
            <p>
              <strong>Last Export:</strong> {{ moment(backend.export.lastSync).fromNow() }}
            </p>
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
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core';
import 'assets/css/bulma-switch.css'
import moment from "moment";

useHead({title: 'Backends'})

const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')

const backends = ref([])

const loadContent = async () => {
  const response = await fetch(`${api_url.value}/v1/api/backends`, {
    headers: {
      'Authorization': `Bearer ${api_token.value}`
    }
  })
  const json = await response.json();
  backends.value = json.backends
}

onMounted(() => loadContent())

const updateValue = async (backend, key, newValue) => {
  const response = await fetch(`${api_url.value}/v1/api/backends/${backend.name}`, {
    method: 'PATCH',
    headers: {
      'Authorization': `Bearer ${api_token.value}`
    },
    body: JSON.stringify([{
      "key": key,
      "value": newValue
    }])
  });

  const json = await response.json();
  backends.value[backends.value.findIndex(b => b.name === backend.name)] = json.backend
}

</script>
