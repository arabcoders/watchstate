<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-exchange-alt"></i></span>
          Add new client GUID link
        </span>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page allows you to add a new client GUID link. The client GUID link is used to link the client/backend
            GUID to the <code>WatchState</code> GUID or your custom GUID.
          </span>
        </div>
      </div>

      <div class="column is-12">
        <form id="page_form" @submit.prevent="addNewLink">

          <div class="field">
            <label class="label is-unselectable" for="form_select_type">Client</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select id="form_select_type" v-model="form.type">
                  <option value="" disabled>Select client type</option>
                  <option v-for="client in supported" :value="client" :key="`client-${client}`">
                    {{ ucFirst(client) }}
                  </option>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-server"></i>
              </div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>Select which client this link association for.</span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable" for="form_map_from">Link client GUID</label>
            <div class="control has-icons-left">
              <input class="input" id="form_map_from" type="text" v-model="form.map.from">
              <div class="icon is-small is-left"><i class="fas fa-a"></i></div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>Write the <code>{{ form.type.length > 0 ? ucFirst(form.type) : 'client' }}</code> GUID
                identifier.</span>
            </p>
          </div>

          <div class="field">
            <label class="label is-unselectable" for="form_map_to">To This GUID</label>
            <div class="control has-icons-left">
              <div class="select is-fullwidth">
                <select id="form_map_to" v-model="form.map.to">
                  <option value="" disabled>Select the associated GUID</option>
                  <option v-for="(g) in guids" :value="g.guid" :key="`guid-${g.guid}`">
                    {{ g.guid }}
                  </option>
                </select>
              </div>
              <div class="icon is-left">
                <i class="fas fa-b"></i>
              </div>
            </div>
            <p class="help">
              <span class="icon"><i class="fas fa-info"></i></span>
              <span>
                Select which <code>WatchState</code> GUID should link with this
                <code>{{ form.type.length > 0 ? ucFirst(form.type) : 'client' }}</code> GUID identifier.
              </span>
            </p>
          </div>

          <div class="field" v-if="'plex' === form.type">
            <label class="label" for="backend_import">Is this a Plex legacy agent GUID?</label>
            <div class="control">
              <input id="backend_import" type="checkbox" class="switch is-success" v-model="form.options.legacy">
              <label for="backend_import">Enable</label>
              <p class="help">Plex legacy agents starts with <code>com.plexapp.agents.</code></p>
            </div>
          </div>

          <template v-if="'plex' === form.type && true === form.options.legacy">
            <div class="field">
              <label class="label is-clickable is-unselectable" @click="toggleReplace = !toggleReplace">
                <span class="icon">
                  <i class="fas" :class="{ 'fa-arrow-up': toggleReplace, 'fa-arrow-down': !toggleReplace }"></i>
                </span>
                Toggle Text replacement.
              </label>
              <p class="help">
                <span class="icon"><i class="fas fa-info"></i></span>
                <span>Text replacement only works for plex legacy agents.</span>
              </p>
            </div>

            <template v-if="toggleReplace">
              <div class="field">
                <label class="label is-unselectable" for="form_replace_from">Search for</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_replace_from" type="text" v-model="form.replace.from">
                  <div class="icon is-small is-left"><i class="fas fa-passport"></i></div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>The text string to replace. Sometimes it's necessary to replace legacy agent GUID into
                    something else. Leave it empty to ignore it.</span>
                </p>
              </div>
              <div class="field">
                <label class="label is-unselectable" for="form_replace_to">Replace with</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_replace_to" type="text" v-model="form.replace.to">
                  <div class="icon is-small is-left"><i class="fas fa-passport"></i></div>
                </div>
                <p class="help">
                  <span class="icon"><i class="fas fa-info"></i></span>
                  <span>The string replacement. If <code>replace.from</code> is empty this field will be
                    ignored.</span>
                </p>
              </div>
            </template>
          </template>

          <div class="field is-grouped">
            <div class="control is-expanded">
              <button class="button is-fullwidth is-primary" type="submit"
                      :disabled="false === validForm || isSaving"
                      :class="{'is-loading':isSaving}">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-save"></i></span>
                  <span>Save</span>
                </span>
              </button>
            </div>
            <div class="control is-expanded">
              <button class="button is-fullwidth is-danger" type="button" @click="navigateTo('/custom')">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-cancel"></i></span>
                  <span>Cancel</span>
                </span>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import '~/assets/css/bulma-switch.css'
import request from '~/utils/request.js'
import {notification, parse_api_response} from '~/utils/index'
import {useStorage} from '@vueuse/core'

useHead({title: 'Add new client GUID link'})

const empty_form = {
  type: '',
  options: {
    legacy: true,
  },
  map: {
    from: '',
    to: ''
  },
  replace: {
    from: '',
    to: ''
  },
}
const show_page_tips = useStorage('show_page_tips', true)
const form = ref(JSON.parse(JSON.stringify(empty_form)))
const guids = ref([])
const supported = ref([])
const isSaving = ref(false)
const links = ref([])
const toggleReplace = ref(false)

onMounted(async () => {
  try {

    /** @type {Array<Promise<Response>>} */
    const responses = await Promise.all([
      request('/system/guids'),
      request('/system/supported'),
      request('/system/guids/custom'),
    ])

    guids.value = await parse_api_response(responses[0]) ?? []
    supported.value = await parse_api_response(responses[1]) ?? []
    links.value = (await parse_api_response(responses[2])).links ?? []

  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  }
})

const addNewLink = async () => {
  if (!validForm.value) {
    notification('error', 'Error', 'Invalid form data.', 5000)
    return
  }

  let data = form.value

  if (!supported.value.includes(data.type)) {
    notification('error', 'Error', `Invalid client type.`, 5000)
    return
  }

  if (!data.map.from) {
    notification('error', 'Error', `map.from must not be empty.`, 5000)
    return
  }

  if (!guids.value.find(g => g.guid === data.map.to)) {
    notification('error', 'Error', `Invalid map.to value '${data.map.to}'.`, 5000)
    return
  }

  for (let i = 0; i < links.value.length; i++) {
    if (links.value[i].type === data.type && links.value[i].map.from === data.map.from) {
      notification('error', 'Error', `Link with map.from '${data.map.from}' already exists.`, 5000)
      return
    }
  }

  let formData = {
    type: data.type,
    map: {
      from: data.map.from,
      to: data.map.to
    }
  }

  if ('plex' === data.type) {
    formData.options = {
      legacy: Boolean(data.options.legacy),
    }

    if (data.replace.from && data.replace.to) {
      formData.replace = {
        from: data.replace.from,
        to: data.replace.to
      }
    }
  }

  isSaving.value = true

  try {
    const response = await request(`/system/guids/custom/${formData.type}`, {
      method: 'PUT',
      body: JSON.stringify(formData)
    })

    const json = await parse_api_response(response)

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }

    notification('success', 'Success', 'Successfully added new client link.', 5000)
    await navigateTo('/custom')
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  } finally {
    isSaving.value = false
  }
}

const validForm = computed(() => !(!form.value.map.to || !form.value.map.from || !form.value.type))
</script>
