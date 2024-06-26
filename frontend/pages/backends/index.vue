<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-server"></i></span>
        Backends
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" v-tooltip.bottom="'Add New Backend'"
                    @click="toggleForm = !toggleForm" :disabled="isLoading">
              <span class="icon"><i class="fas fa-add"></i></span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click="loadContent" :disabled="isLoading" :class="{'is-loading':isLoading}">
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
      <div class="column is-12" v-if="backends.length<1">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Requesting active play sessions. Please wait..."/>
        <Message v-else message_class="is-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-circle">
          No backends found. Please add new backends to start using the tool. You can add new backend by
          <NuxtLink @click="toggleForm=true" v-text="'clicking here'"/>
          or by clicking the <span class="icon is-clickable" @click="toggleForm=true"><i class="fas fa-add"></i></span>
          button above.
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
              <div class="column is-6 has-text-left-mobile">
                <strong>Last Export:&nbsp;</strong>
                <template v-if="backend.export.enabled">
                  <span v-if="backend.export.lastSync" class="has-tooltip"
                        v-tooltip="moment(backend.export.lastSync).format(TOOLTIP_DATE_FORMAT)">
                    {{ moment(backend.export.lastSync).fromNow() }}
                  </span>
                  <template v-else>Never</template>
                </template>
                <template v-else>
                  <span class="tag is-danger is-light">Disabled</span>
                </template>
              </div>
              <div class="column is-6 has-text-left-mobile">
                <strong>Last Import:&nbsp;</strong>
                <template v-if="backend.import.enabled">
                  <span v-if="backend.import.lastSync" class="has-tooltip"
                        v-tooltip="moment(backend.import.lastSync).format(TOOLTIP_DATE_FORMAT)">
                    {{ moment(backend.import.lastSync).fromNow() }}
                  </span>
                  <template v-else>Never</template>
                </template>
                <template v-else>
                  <span class="tag is-danger is-light">Disabled</span>
                </template>
              </div>
            </div>
          </div>
          <div class="card-footer">
            <div class="card-footer-item" v-if="backend.export.enabled">
              <NuxtLink class="button is-danger is-fullwidth"
                        :to="makeConsoleCommand(`state:export -v -s ${backend.name}`)">
                <span class="icon"><i class="fas fa-upload"></i></span>
                <span>Run export now</span>
              </NuxtLink>
            </div>
            <div class="card-footer-item" v-if="backend.import.enabled">
              <NuxtLink class="button is-primary is-fullwidth"
                        :to="makeConsoleCommand(`state:import -v -s ${backend.name}`)">
                <span class="icon"><i class="fas fa-download"></i></span>
                <span>Run import now</span>
              </NuxtLink>
            </div>
          </div>
          <div class="card-footer">
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
          </div>
          <footer class="card-footer">
            <div class="card-footer-item">
              <div class="select is-fullwidth">
                <select v-model="selectedCommand" @change="forwardCommand(backend)">
                  <option value="" disabled>Quick commands</option>
                  <option v-for="(command, index) in usefulCommands" :key="`command_${index}`" :value="index">
                    {{ command.title }}
                  </option>
                </select>
              </div>
            </div>
          </footer>
        </div>
      </div>
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              <strong>Import</strong> means pulling data from the backends into the local database.
            </li>
            <li>
              <strong>Export</strong> means pushing data from the local database to the backends.
            </li>
            <li>You can delete a backend by visiting the backend page and clicking the delete button.</li>
          </ul>
        </Message>
      </div>
    </template>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request.js'
import BackendAdd from '~/components/BackendAdd.vue'
import {copyText, makeConsoleCommand, notification, r, TOOLTIP_DATE_FORMAT} from '~/utils/index.js'
import {useStorage} from "@vueuse/core";
import Message from "~/components/Message.vue";

useHead({title: 'Backends'})

const backends = ref([])
const toggleForm = ref(false)
const api_url = useStorage('api_url', '')
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(false)
const selectedCommand = ref('')

const usefulCommands = [
  {
    title: "Force export local play state to this backend.",
    command: 'state:export -fi -v -s {name}'
  },
  {
    title: "Backup this backend play state.",
    command: "state:backup -v -s {name} --file '{date}.manual_{name}.json'",
  },
  {
    title: "Import backend metadata only.",
    command: "state:import -v --metadata-only -s {name}",
  },
]

const forwardCommand = async backend => {
  if ('' === selectedCommand.value) {
    return
  }
  const Index = selectedCommand.value
  selectedCommand.value = ''

  const util = {
    date: moment().format('YYYYMMDD'),
  }

  await navigateTo(makeConsoleCommand(r(usefulCommands[Index].command, {...backend, ...util})));
}

const loadContent = async () => {
  backends.value = []
  isLoading.value = true
  try {
    const response = await request('/backends')
    backends.value = await response.json()
  } catch (e) {
    notification('error', 'Error', `Failed to load backends. ${e.message}`)
  } finally {
    isLoading.value = false
  }
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
