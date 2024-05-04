<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <div class="p-2">
        <span class="title is-4">Backends</span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary is-light" v-tooltip="'Add New Backend'">
                <span class="icon">
                  <i class="fas fa-add"></i>
                </span>
              </button>
            </p>
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
    </div>
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
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-6-mobile" v-if="backend.export.enabled">
              <strong>Last Export:</strong> {{ moment(backend.export.lastSync).fromNow() }}
            </div>
            <div class="column is-hidden-mobile" v-if="backend.import.enabled">
              <strong>Last Import:</strong> {{ moment(backend.import.lastSync).fromNow() }}
            </div>
          </div>
        </div>
        <footer class="card-footer">
          <div class="card-footer-item">
            <div class="field">
              <input :id="backend.name+'_export'" type="checkbox" class="switch is-success"
                     :checked="backend.export.enabled"
                     @change="updateValue(backend, 'export.enabled', !backend.export.enabled)">
              <label :for="backend.name+'_export'">
                Export {{ backend.export.enabled ? 'Enabled' : 'Disabled' }}
              </label>
            </div>
          </div>
          <div class="card-footer-item">
            <div class="field">
              <input :id="backend.name+'_import'" type="checkbox" class="switch is-success"
                     :checked="backend.import.enabled"
                     @change="updateValue(backend, 'import.enabled',!backend.import.enabled)">
              <label :for="backend.name+'_import'">
                Import {{ backend.import.enabled ? 'Enabled' : 'Disabled' }}
              </label>
            </div>
          </div>
        </footer>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from "moment";
import request from "~/utils/request.js";

useHead({title: 'Backends'})

const backends = ref([])

const loadContent = async () => {
  backends.value = []
  const response = await request('/backends')
  const json = await response.json();
  backends.value = json.backends
}

onMounted(() => loadContent())

const updateValue = async (backend, key, newValue) => {
  const response = await request(`/backend/${backend.name}`, {
    method: 'PATCH',
    body: JSON.stringify([{
      "key": key,
      "value": newValue
    }])
  });

  const json = await response.json();
  backends.value[backends.value.findIndex(b => b.name === backend.name)] = json.backend
}

</script>
