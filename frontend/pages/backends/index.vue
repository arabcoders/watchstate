<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server"/></span>
          Backends
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Add New Backend'"
                      @click="toggleForm = !toggleForm" :disabled="isLoading">
                <span class="icon"><i class="fas fa-add"/></span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                      :class="{'is-loading':isLoading}">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This page contains all the backends that are currently configured.</span>
        </div>
      </div>

      <div class="column is-12" v-if="toggleForm">
        <BackendAdd @backupData="e => handleEvents('backupData', e)" :backends="backends"
                    @forceExport="e => handleEvents('forceExport', e)"
                    @addBackend="e => handleEvents('addBackend', e)"/>
      </div>
      <template v-else>
        <div class="column is-12" v-if="backends.length<1">
          <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                   icon="fas fa-spinner fa-spin" message="Requesting active play sessions. Please wait..."/>
          <Message v-else message_class="is-background-warning-80 has-text-dark" title="Warning"
                   icon="fas fa-exclamation-circle">
            No backends found. Please add new backends to start using the tool. You can add new backend by
            <NuxtLink @click="toggleForm=true" v-text="'clicking here'"/>
            or by clicking the <span class="icon is-clickable" @click="toggleForm=true"><i
              class="fas fa-add"/></span>
            button above.
          </Message>
        </div>

        <div class="column is-12">
          <div class="content">
            <h1 class="title is-4">
              <span class="icon"><i class="fas fa-tools"/></span> Tools
            </h1>
            <ul>
              <li>
                <NuxtLink :to="`/tools/plex_token`" v-text="'Validate plex token'"/>
              </li>
              <li v-if="backends && backends.length>0 && 'main' === api_user">
                <NuxtLink :to="`/tools/sub_users`" v-text="'Create sub-users'"/>
              </li>
            </ul>
          </div>
        </div>

        <div v-for="backend in backends" :key="backend.name" class="column is-6-tablet is-12-mobile">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title">
                <NuxtLink :to="`/backend/${backend.name}`">
                  {{ backend.name }}
                </NuxtLink>
              </p>
              <div class="card-header-icon">
                <div class="field is-grouped">
                  <div class="control">
                    <NuxtLink :to="`/backend/${backend.name}/edit?redirect=/backends`"
                              v-tooltip="'Edit backend settings'">
                      <span class="icon has-text-warning"><i class="fas fa-cog"/></span>
                    </NuxtLink>
                  </div>
                  <div class="control">
                    <NuxtLink :to="`/backend/${backend.name}/delete?redirect=/backends`" v-tooltip="'Delete backend'">
                      <span class="icon has-text-danger"><i class="fas fa-trash"/></span>
                    </NuxtLink>
                  </div>
                </div>
              </div>
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
                    <span class="tag is-danger is-light is-pointer-help"
                          v-tooltip="'Local database is not being sync to this backend.'">Disabled</span>
                  </template>
                </div>
                <div class="column is-6 has-text-left-mobile">
                  <strong>Last Import:&nbsp;</strong>
                  <template v-if="backend.import.enabled || backend.options?.IMPORT_METADATA_ONLY">
                    <template v-if="backend.import.lastSync">
                      <span class="has-tooltip" v-tooltip="moment(backend.import.lastSync).format(TOOLTIP_DATE_FORMAT)">
                        {{ moment(backend.import.lastSync).fromNow() }}
                      </span>
                      <template v-if="!backend.import.enabled && backend.options?.IMPORT_METADATA_ONLY">
                        &nbsp;
                        <span class="tag is-warning is-light is-pointer-help"
                              v-tooltip="'Only metadata being imported from this backend'">
                          Metadata</span>
                      </template>
                    </template>
                    <template v-else>Never</template>
                  </template>
                  <template v-else>
                    <span class="tag is-danger is-light is-pointer-help"
                          v-tooltip="'All data import from this backend is disabled.'">Disabled</span>
                  </template>
                </div>
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
                <NuxtLink :to="api_url + backend.urls.webhook" class="is-info is-light"
                          @click.prevent="copyUrl(backend)">
                  <span class="icon"><i class="fas fa-copy"/></span>
                  <span class="is-hidden-mobile">Copy Webhook URL</span>
                  <span class="is-hidden-tablet">Webhook</span>
                </NuxtLink>
              </div>
            </div>
            <footer class="card-footer">
              <div class="card-footer-item is-block">
                <div class="control is-fullwidth has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="selectedCommand" @change="forwardCommand(backend)">
                      <option value="" disabled>Frequently used commands</option>
                      <option v-for="(command, index) in usefulCommands" :key="`qc-${index}`" :value="index"
                              :disabled="!check_state(backend, command)">
                        {{ command.id }}. {{ command.title }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-terminal"/>
                  </div>
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
            </ul>
          </Message>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request'
import BackendAdd from '~/components/BackendAdd'
import {ag, copyText, makeConsoleCommand, notification, queue_event, r, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message'

useHead({title: 'Backends'})

const backends = ref([])
const toggleForm = ref(false)
const api_url = useStorage('api_url', '')
const api_user = useStorage('api_user', 'main')
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(false)
const selectedCommand = ref('')

const usefulCommands = {
  export_now: {
    id: 1,
    title: "Run normal export.",
    command: 'state:export -v -u {user} -s {name}',
  },
  import_now: {
    id: 2,
    title: "Run normal import.",
    command: 'state:import -v -u {user} -s {name}',
  },
  force_export: {
    id: 3,
    title: "Force export local play state to this backend.",
    command: 'state:export -fi -v -u {user} -s {name}',
  },
  backup_now: {
    id: 4,
    title: "Backup this backend play state.",
    command: "state:backup -v -u {user} -s {name} --file '{date}.manual_{name}.json'",
  },
  metadata_only: {
    id: 5,
    title: "Run metadata import from this backend.",
    command: "state:import -v --metadata-only -u {user} -s {name}",
  },
  import_debug: {
    id: 6,
    title: "Run import and save debug log.",
    command: "state:import -v --debug -u {user} -s {name} --logfile '/config/{user}@{name}.import.txt'",
  },
  export_debug: {
    id: 7,
    title: "Run export and save debug log.",
    command: "state:export -v --debug -u {user} -s {name} --logfile '/config/{user}@{name}.export.txt'",
  },
  force_import: {
    id: 8,
    title: "Force import local play state from this backend.",
    command: "state:import -f -v -u {user} -s {name}",
  },
}

const forwardCommand = async backend => {
  if ('' === selectedCommand.value) {
    return
  }

  const index = selectedCommand.value
  selectedCommand.value = ''

  const util = {
    date: moment().format('YYYYMMDD'),
    user: api_user.value,
  }

  await navigateTo(makeConsoleCommand(r(usefulCommands[index].command, {...backend, ...util})));
}

const loadContent = async () => {
  backends.value = []
  isLoading.value = true
  try {
    const response = await request('/backends')
    const json = await response.json()
    if (useRoute().name !== 'backends') {
      return
    }
    backends.value = json
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

const handleEvents = async (event, backend) => {
  switch (event) {
    case 'backupData':
      try {
        const backup_status = await queue_event('run_console', {
          command: 'state:backup',
          args: [
            '-v',
            '--user',
            api_user.value,
            '--select-backend',
            backend.value.name,
            '--file',
            '{user}.{backend}.{date}.initial_backup.json',
          ]
        })
        console.log(backup_status);

        notification('info', 'Info', `We are going to initiate a backup for '${backend.value.name}' in little bit.`, 5000)
      } catch (e) {
        notification('error', 'Error', `Failed to queue backup request. ${e.message}`)
      }
      break
    case 'forceExport':
      try {
        const export_status = await queue_event('run_console', {
          command: 'state:export',
          args: [
            '-fi',
            '-v',
            '--user',
            api_user.value,
            '--dry-run',
            '--select-backend',
            backend.value.name,
          ]
        }, 180)

        console.log(export_status);

        notification('info', 'Info', `Soon we are going to force export the local data to '${backend.value.name}'.`, 5000)
      } catch (e) {
        notification('error', 'Error', `Failed to queue force export request. ${e.message}`)
      }
      break
    case 'addBackend':
      toggleForm.value = false
      await loadContent()
      break
  }
}

const check_state = (backend, command) => {
  if (!command?.state_key) {
    return true
  }

  const state = ag(backend, command.state_key, false)
  console.log(backend, command.state_key, state)
  return Boolean(state)
}
</script>
