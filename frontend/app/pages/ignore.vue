<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-ban"></i></span>
          Ignored GUIDs
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Add New Ignore rule'"
                      @click="toggleForm = !toggleForm">
                <span class="icon">
                  <i class="fas fa-add"></i>
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading || toggleForm"
                      :class="{'is-loading':isLoading}">
                <span class="icon">
                  <i class="fas fa-sync"></i>
                </span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page allow you to ignore specific <code>GUID</code> from being processed by the system.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="!toggleForm && items.length < 1">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else message_class="has-background-success-90 has-text-dark" title="Information" icon="fas fa-check">
          There are no ignore rules configured. You can add new ignore rules by clicking on the
          <i @click="toggleForm=true" class="is-clickable fas fa-add"></i> button.
        </Message>
      </div>

      <div class="column is-12" v-if="toggleForm">
        <form id="page_form" @submit.prevent="addIgnoreRule">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-unselectable is-justify-center">Add Ignore rule</p>
            </header>

            <div class="card-content">
              <div class="field">
                <label class="label is-unselectable" for="form_select_backend">Backend</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select id="form_select_backend" v-model="form.backend">
                      <option value="" disabled>Select Backend</option>
                      <option v-for="backend in backends" :key="backend.name" :value="backend.name">
                        {{ backend.name }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-server"></i>
                  </div>
                </div>
                <p class="help">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>Ignore rules applies to backends, you must select the correct backend you want to ignore the
                      GUID from</span>
                  </span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable" for="form_select_guid">Provider</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select id="form_select_guid" v-model="form.db">
                      <option value="" disabled>Select GUID provider</option>
                      <option v-for="guid in guids" :key="guid.guid" :value="guid.guid">
                        {{ guid.guid }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-database"></i>
                  </div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>You must select the GUID provider that giving you incorrect data.</span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable" for="form_ignore_id">GUID Value</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_ignore_id" type="text" v-model="form.id">
                  <div class="icon is-small is-left"><i class="fas fa-font"></i></div>
                </div>
                <p class="help">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>The GUID value to ignore.</span>
                  </span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable">Type</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select id="form_select_backend" v-model="form.type" class="is-capitalized">
                      <option value="" disabled>Select type</option>
                      <option v-for="type in types" :key="type" :value="type">
                        {{ type }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fas fa-server"></i>
                  </div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>What kind of data the <code>GUID value</code> reference?</span>
                </p>
              </div>

              <div class="field">
                <label class="label is-unselectable" for="form_scoped">Scope</label>
                <div class="control has-icons-left">
                  <input id="form_scoped" type="checkbox" class="switch is-success" v-model="form.scoped">
                  <label for="form_scoped">
                    <template v-if="form.scoped">On (True)</template>
                    <template v-else>Off (False)</template>
                  </label>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-exclamation"></i></span>
                  <span>By default, Rules are globally applied to all items from the selected backend, you can limit the
                    scope, by enabling this option.
                  </span>
                </p>
              </div>

              <div class="field" v-if="form.scoped">
                <label class="label is-unselectable" for="form_scoped_to">Scoped To</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_scoped_to" type="text" v-model="form.scoped_to">
                  <div class="icon is-small is-left"><i class="fas fa-font"></i></div>
                  <p class="help">
                    <span class="icon"><i class="fas fa-info"></i></span>
                    <span>The id to associate this rule with. The value must be the <code>{{ form.type }}</code> id as
                      being reported by the backend.</span>
                  </p>
                </div>
              </div>

            </div>
            <div class="card-footer">
              <div class="card-footer-item">
                <button class="button is-fullwidth is-primary" type="submit" :disabled="false === checkForm">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-save"></i></span>
                    <span>Save</span>
                  </span>
                </button>
              </div>
              <div class="card-footer-item">
                <button class="button is-fullwidth is-danger" type="button" @click="cancelForm">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-cancel"></i></span>
                    <span>Cancel</span>
                  </span>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div v-else class="column is-12" v-if="items">
        <div class="columns is-multiline">
          <div class="column is-6" v-for="item in items" :key="item.rule">
            <div class="card">
              <header class="card-header">
                <p class="card-header-title is-unselectable is-text-overflow">
                  <template v-if="item.title">{{ item.title }}</template>
                  <template v-else>
                    {{ item.scoped ? 'Unknown title' : '**Global**' }}
                  </template>
                </p>
                <span class="card-header-icon">
                  <span class="icon">
                    <i class="fas" :class="{'fa-tv':'Show'===item.type,'fa-film': 'Movie' === item.type}"></i>
                  </span>
                </span>
              </header>
              <div class="card-content">
                <div class="columns is-multiline is-mobile">
                  <div class="column is-6">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-server"></i></span>
                      <span>
                        <NuxtLink :to="`/backend/${item.backend}`" v-text="item.backend"/>
                      </span>
                    </span>
                  </div>
                  <div class="column is-6 has-text-right">
                    <strong>Scope:&nbsp;</strong>
                    <NuxtLink :to="makeItemLink(item)" v-text="item.scoped_to" v-if="item.scoped_to"/>
                    <template v-else>Global</template>
                  </div>

                  <div class="column is-6">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-database"></i></span>
                      <span>
                        <NuxtLink target="_blank" :to="makeGUIDLink(item.type, item.db, item.id)"
                                  v-text="`${item.db}://${item.id}`"/>
                      </span>
                    </span>
                  </div>

                  <div class="column is-6 has-text-right">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-calendar"></i></span>
                      <span class="has-tooltip"
                            v-tooltip="`Created at: ${moment(item.created).format(TOOLTIP_DATE_FORMAT)}`">
                        {{ moment(item.created).fromNow() }}</span>
                    </span>
                  </div>

                </div>
              </div>
              <footer class="card-footer">
                <div class="card-footer-item">
                  <button class="button is-fullwidth is-warning" @click="copyText(item.rule)">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-copy"></i></span>
                      <span>Copy</span>
                    </span>
                  </button>
                </div>
                <div class="card-footer-item">
                  <button class="button is-fullwidth is-danger" @click="deleteIgnore(item)">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-trash"></i></span>
                      <span>Delete</span>
                    </span>
                  </button>
                </div>
              </footer>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>Ignoring specific GUID sometimes helps in preventing incorrect data being added to WatchState, due to
              incorrect metadata being provided by backends.
            </li>
            <li>
              <code>GUID</code> means in terms of WatchState is the unique identifier for a specific item in the
              external data source.
            </li>
            <li>To add a new ignore rule click on the <i @click="toggleForm=true" class="is-clickable fa fa-add"></i>
              button.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import '~/assets/css/bulma-switch.css'
import request from '~/utils/request.js'
import {awaitElement, copyText, notification, stringToRegex, TOOLTIP_DATE_FORMAT} from '~/utils/index.js'
import {useStorage} from '@vueuse/core'
import moment from 'moment'
import Message from '~/components/Message.vue'

useHead({title: 'Ignored GUIDs'})

const types = ['show', 'movie', 'episode']
const empty_form = {id: '', type: '', backend: '', db: '', scoped: false, scoped_to: null}
const items = ref([])
const toggleForm = ref(false)
const form = ref(JSON.parse(JSON.stringify(empty_form)))
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(false)
const guids = ref([])
const backends = ref([])

const loadContent = async () => {
  isLoading.value = true
  items.value = []

  if (!guids.value.length) {
    const guid_request = await request('/system/guids')
    const guid_response = await guid_request.json()
    if (useRoute().name !== 'ignore') {
      return
    }
    guids.value = guid_response
  }

  if (!backends.value.length) {
    const backends_request = await request('/backends')
    const backends_response = await backends_request.json()
    if (useRoute().name !== 'ignore') {
      return
    }
    backends.value = backends_response
  }

  let response, json

  try {
    response = await request(`/ignore`)
  } catch (e) {
    isLoading.value = false
    return notification('error', 'Error', e.message)
  }

  try {
    json = await response.json()
    if (useRoute().name !== 'ignore') {
      return
    }
  } catch (e) {
    json = {
      error: {
        code: response.status,
        message: response.statusText
      }
    }
  }

  isLoading.value = false

  if (!response.ok) {
    notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
    return
  }

  items.value = json
}

onMounted(() => loadContent())

const deleteIgnore = async (item) => {
  if (!confirm(`Are you sure you want to delete the ignore rule?`)) {
    return
  }

  const response = await request(`/ignore`, {
    method: 'DELETE',
    body: JSON.stringify({
      rule: item.rule
    })
  })

  if (response.ok) {
    items.value = items.value.filter(i => i.rule !== item.rule)
    notification('success', 'Success', `Environment variable '${item.rule}' successfully deleted.`, 5000)
  }
}
const makeItemLink = (item) => {
  if (!item?.scoped_to) {
    return ''
  }

  const type = item.type === 'Show' ? 'show' : 'id'

  const params = new URLSearchParams()
  params.append('perpage', '50')
  params.append('page', '1')
  params.append('q', `${item.backend}.${type}://${item.scoped_to}`)
  params.append('key', 'metadata')

  return `/history?${params.toString()}`
}
const addIgnoreRule = async () => {
  const val = guids.value.find(g => g.guid === form.value.db)
  if (val && val?.validator && val.validator.pattern) {
    if (!stringToRegex(val.validator.pattern).test(form.value.id)) {
      notification('error', 'Error', `Invalid GUID value, must match the pattern: '${val.validator.pattern}'. Example ${val.validator.example}`, 5000)
      return
    }
  }

  try {
    const response = await request(`/ignore`, {
      method: 'POST',
      body: JSON.stringify(form.value)
    })

    const json = await response.json()

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }

    items.value.push(json)

    notification('success', 'Success', 'Successfully added new ignore rule.', 5000)
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
    return
  }

  return cancelForm()
}

const cancelForm = () => {
  form.value = JSON.parse(JSON.stringify(empty_form))
  toggleForm.value = false
}

watch(toggleForm, (value) => {
  if (!value) {
    cancelForm()
    return
  }

  awaitElement('#page_form', (_, el) => el.scrollIntoView({
    behavior: 'smooth',
    block: 'start',
    inline: 'nearest'
  }))
})

const checkForm = computed(() => {
  const {id, type, backend, db} = form.value
  return '' !== id && '' !== type && '' !== backend && '' !== db
})
</script>
