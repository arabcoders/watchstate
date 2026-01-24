<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server" /></span>
          <span v-if="api_user">&nbsp;{{ ucFirst(api_user) }} @</span>
          Backends
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" @click="toggleForm = !toggleForm" :disabled="isLoading">
                <span class="icon"><i class="fas fa-add" /></span>
                <span>Add Backend</span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
                :class="{ 'is-loading': isLoading }">
                <span class="icon"><i class="fas fa-sync" /></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">Backends that are configured for the specific user.</span>
        </div>
      </div>

      <div class="column is-12" v-if="toggleForm">
        <BackendAdd @backupData="e => handleEvents('backupData', e)" :backends="backends"
          @forceExport="e => handleEvents('forceExport', e)" @addBackend="e => handleEvents('addBackend', e)"
          @forceImport="e => handleEvents('forceImport', e)" />
      </div>
      <template v-else>
        <div class="column is-12" v-if="backends.length < 1">
          <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
            icon="fas fa-spinner fa-spin" message="Requesting active play sessions. Please wait..." />
          <Message v-else message_class="is-background-warning-80 has-text-dark" title="Warning"
            icon="fas fa-exclamation-circle">
            No backends found. Please add new backends to start using the tool. You can add new backend by
            <NuxtLink @click="toggleForm = true">clicking here</NuxtLink>
            or by clicking the <span class="icon is-clickable" @click="toggleForm = true"><i
                class="fas fa-add" /></span>
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
              <div class="card-header-icon">
                <div class="field is-grouped">
                  <div class="control">
                    <NuxtLink :to="`/backend/${backend.name}/edit?redirect=/backends`"
                      v-tooltip="'Edit backend settings'">
                      <span class="icon has-text-warning"><i class="fas fa-cog" /></span>
                      <span class="is-hidden-mobile">Edit</span>
                    </NuxtLink>
                  </div>
                  <div class="control">
                    <NuxtLink :to="`/backend/${backend.name}/delete?redirect=/backends`" v-tooltip="'Delete backend'">
                      <span class="icon has-text-danger"><i class="fas fa-trash" /></span>
                      <span class="is-hidden-mobile">Delete</span>
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
                  <input :id="backend.name + '_export'" type="checkbox" class="switch is-success"
                    :checked="backend.export.enabled"
                    @change="updateValue(backend, 'export.enabled', !backend.export.enabled)">
                  <label class="has-tooltip" :for="backend.name + '_export'"
                    v-tooltip="'Send data from watchstate to this backend.'">Export</label>
                </div>
              </div>
              <div class="card-footer-item">
                <div class="field">
                  <input :id="backend.name + '_import'" type="checkbox" class="switch is-success"
                    :checked="backend.import.enabled"
                    @change="updateValue(backend, 'import.enabled', !backend.import.enabled)">
                  <label class="has-tooltip" :for="backend.name + '_import'"
                    v-tooltip="'Get data from this backend into watchstate.'">
                    Import</label>
                </div>
              </div>
              <div class="card-footer-item">
                <a :href="backend.urls?.webhook || '#'" class="is-info is-light" @click.prevent="copyUrl(backend)"
                  v-if="backend.urls?.webhook">
                  <span class="icon"><i class="fas fa-copy" /></span>
                  <span class="is-hidden-mobile">Copy Webhook URL</span>
                  <span class="is-hidden-tablet">Webhook</span>
                </a>
                <span v-else class="has-text-grey">No webhook URL</span>
              </div>
            </div>
            <footer class="card-footer">
              <div class="card-footer-item is-block">
                <div class="control is-fullwidth has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="selectedCommand" @change="forwardCommand(backend)">
                      <option value="" disabled>Quick operations</option>
                      <option v-for="(command, index) in usefulCommands" :key="`qc-${index}`" :value="index"
                        :disabled="!check_state(backend, command)">
                        {{ command.id }}. {{ command.title }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-terminal" />
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
                Think of the WatchState as a <strong>Central Hub</strong> your backends aren't aware of each other.
                <strong>WatchState</strong> is the facilitator of the data flow. If you think like that, then
                <strong>import</strong>
                and <strong>export</strong> would make sense.
              </li>
              <li>
                <strong>Import</strong>: Means getting data from the backend into watchstate.
              </li>
              <li>
                <strong>Export</strong>: Means sending data from watchstate to the backend.
              </li>
            </ul>
          </Message>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted} from 'vue'
import {useHead, useRoute, navigateTo} from '#app'
import {useStorage} from '@vueuse/core'
import moment from 'moment'
import BackendAdd from '~/components/BackendAdd.vue'
import Message from '~/components/Message.vue'
import {request, ag, copyText, makeConsoleCommand, notification, queue_event, r, TOOLTIP_DATE_FORMAT, ucFirst} from '~/utils'
import type {Backend, JsonObject, JsonValue, UtilityCommand} from '~/types'
import '~/assets/css/bulma-switch.css'

type UsefulCommand = UtilityCommand

type UsefulCommands = Record<string, UsefulCommand>

type CommandUtility = {
  /** Current date in YYYYMMDD format */
  date: string
  /** API user name */
  user: string
  /** Backend name (merged from backend) */
  name?: string
  [key: string]: JsonValue | undefined
}

useHead({ title: 'Backends' })

const backends = ref<Array<Backend>>([])
const toggleForm = ref<boolean>(false)
const api_user = useStorage('api_user', 'main')
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref<boolean>(false)
const selectedCommand = ref<string>('')

const usefulCommands: UsefulCommands = {
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
  force_metadata: {
    id: 9,
    title: "Force metadata import from this backend.",
    command: "state:import -f -v --metadata-only -u {user} -s {name}",
  },
}

const forwardCommand = async (backend: Backend): Promise<void> => {
  if ('' === selectedCommand.value) {
    return
  }

  const index = selectedCommand.value as keyof UsefulCommands
  selectedCommand.value = ''

  const command = usefulCommands[index]
  if (!command) {
    return
  }

  const util: CommandUtility = {
    date: moment().format('YYYYMMDD'),
    user: api_user.value,
  }

  await navigateTo(makeConsoleCommand(r(command.command, {...backend, ...util} as unknown as JsonObject)))
}

const loadContent = async (): Promise<void> => {
  backends.value = []
  isLoading.value = true
  try {
    const response = await request('/backends')
    const json = await response.json()
    if ('backends' !== useRoute().name) {
      return
    }
    backends.value = json
    useHead({ title: `${ucFirst(api_user.value)} @ Backends` })
  } catch (e) {
    const error = e as Error
    notification('error', 'Error', `Failed to load backends. ${error.message}`)
  } finally {
    isLoading.value = false
  }
}

onMounted((): void => {
  loadContent()
})

const copyUrl = (b: Backend): void => {
  if (b.urls?.webhook) {
    copyText(window.origin + b.urls.webhook)
  }
}

const updateValue = async (backend: Backend, key: string, newValue: boolean): Promise<void> => {
  const response = await request(`/backend/${backend.name}`, {
    method: 'PATCH',
    body: JSON.stringify([{
      "key": key,
      "value": newValue
    }])
  })

  const updatedBackend = await response.json() as Backend
  const index = backends.value.findIndex(b => b.name === backend.name)
  if (-1 !== index) {
    backends.value[index] = updatedBackend
  }
}

const handleEvents = async (event: string, backend: Backend): Promise<void> => {
  switch (event) {
    case 'backupData':
      try {
        await queue_event('run_console', {
          command: 'state:backup',
          args: [
            '-v',
            '--user',
            api_user.value,
            '--select-backend',
            backend.name,
            '--file',
            '{user}.{backend}.{date}.initial_backup.json',
          ]
        })
        notification('info', 'Info', `We are going to initiate a backup for '${backend.name}' in little bit.`, 5000)
      } catch (e) {
        const error = e as Error
        notification('error', 'Error', `Failed to queue backup request. ${error.message}`)
      }
      break
    case 'forceExport':
      try {
        await queue_event('run_console', {
          command: 'state:export',
          args: [
            '-fi',
            '-v',
            '--user',
            api_user.value,
            '--select-backend',
            backend.name,
          ]
        }, 300)

        notification('info', 'Info', `Soon we are going to force export the local data to '${backend.name}'.`, 5000)
      } catch (e) {
        const error = e as Error
        notification('error', 'Error', `Failed to queue force export request. ${error.message}`)
      }
      break
    case 'forceImport':
      try {
        await queue_event('run_console', {
          command: 'state:import',
          args: [
            '-f',
            '-v',
            '--user',
            api_user.value,
            '--select-backend',
            backend.name,
          ]
        }, 300)

        notification('info', 'Info', `Soon we will import data from '${backend.name}'.`, 5000)
      } catch (e) {
        const error = e as Error
        notification('error', 'Error', `Failed to queue force export request. ${error.message}`)
      }
      break
    case 'addBackend':
      toggleForm.value = false
      await loadContent()
      break
  }
}

const check_state = (backend: Backend, command: { state_key?: string }): boolean => {
  if (!command?.state_key) {
    return true
  }

  const state = ag(backend as unknown as JsonObject, command.state_key, false)
  console.log(backend, command.state_key, state)
  return Boolean(state)
}
</script>
