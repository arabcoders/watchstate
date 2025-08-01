<template>
  <div>
    <div class="columns is-multiline">

      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-external-link"/></span>
          URL Checker
        </span>
        <div class="is-pulled-right" v-if="response.response?.status">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click="() => copyText(JSON.stringify(response,null,2))"
                      v-tooltip.bottom="'Copy request & response.'">
                <span class="icon"><i class="fas fa-copy"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">Check if <strong>WatchState</strong> is able to communicate with the given URL.</span>
        </div>
      </div>

      <div class="column is-12">
        <h1 class="title is-4 is-clickable" @click="toggleForm">
          <span class="icon" v-if="response.response.status && !invalid_form">
            <i class="fas" :class="{'fa-arrow-up': toggle_form, 'fa-arrow-down': !toggle_form}"/>
          </span>
          Request Form
        </h1>
        <Message message_class="has-background-warning-80 has-text-dark" v-if="has_template_values()">
          <p>
            The form contains <strong>template values</strong> <code>[...]</code>. Please make sure to replace them
            with the actual values.
          </p>
        </Message>
      </div>

      <div class="column is-12" v-if="toggle_form || !item.url">
        <form @submit.prevent="check_url">
          <div class="box content">
            <div class="field">
              <label class="label is-unselectable" for="url">Pre-defined template</label>
              <div class="control">
                <div class="select is-fullwidth">
                  <select v-model="use_template" :disabled="is_loading">
                    <option value="" v-text="'Select a template'" disabled/>
                    <option v-for="template in templates" :key="template.key" :value="template.key"
                            v-text="template.key"/>
                  </select>
                </div>
              </div>
              <p class="help is-bold">
                <span class="icon"><i class="fas fa-info-circle"/></span>
                Gives a pre-defined template for the URL to check.
              </p>
            </div>

            <div class="field">
              <label class="label is-unselectable" for="url">URL</label>
              <div class="field is-grouped">
                <div class="control">
                  <div class="select is-fullwidth">
                    <select v-model="item.method" :disabled="is_loading">
                      <option v-for="method in methods" :key="method" :value="method" v-text="method"/>
                    </select>
                  </div>
                </div>
                <div class="control is-expanded has-icons-left">
                  <input class="input" type="text" id="url" v-model="item.url" autocomplete="off"
                         placeholder="https://example.com/api/v1/" :disabled="is_loading">
                  <div class="icon is-left"><i class="fas fa-link"/></div>
                </div>
              </div>
              <p class="help is-bold">
                <span class="icon"><i class="fas fa-info-circle"/></span>
                The URL to check. It must be a valid URL.
              </p>
            </div>

            <div class="field">
              <label class="label is-unselectable">
                Headers -
                <NuxtLink @click="add_header()" v-text="'Add'"/>
              </label>

              <div class="control mb-2" v-for="(header, index) in item.headers" :key="header.index">
                <div class="field is-grouped">
                  <div class="control is-expanded">
                    <input class="input" type="text" v-model="header.key" placeholder="Header Key" required
                           :disabled="is_loading">
                  </div>
                  <div class="control is-expanded">
                    <input class="input" type="text" v-model="header.value" placeholder="Header Value" required
                           :disabled="is_loading">
                  </div>
                  <div class="control">
                    <button class="button is-danger" type="button" @click="item.headers.splice(index, 1)"
                            :disabled="is_loading">
                      <span class="icon"><i class="fas fa-times"/></span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="field is-grouped">
              <div class="control is-expanded">
                <button class="button is-fullwidth is-primary" type="submit" :disabled="invalid_form || is_loading"
                        :class="{'is-loading': is_loading}">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-paper-plane"/></span>
                    <span>Send Request</span>
                  </span>
                </button>
              </div>
              <div class="control is-expanded">
                <button class="button is-fullwidth is-danger" type="button" @click="reset_form" :disabled="is_loading">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-times"/></span>
                    <span>Reset Form</span>
                  </span>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div class="column is-6-tablet is-12-mobile" v-if="response.response.status">
        <div class="card">
          <div class="card-header is-clickable" @click="toggle_request = !toggle_request">
            <div class="card-header-title is-block is-ellipsis">
              <span class="is-underlined has-text-danger">{{ response.request.method }}</span>
              {{ response.request.url }}
            </div>
            <button class="card-header-icon">
              <span class="icon">
                <i class="fas" :class="{'fa-arrow-up': toggle_request, 'fa-arrow-down': !toggle_request}"/>
              </span>
            </button>
          </div>
          <div class="card-content content p-0 m-0" v-if="toggle_request">
            <div style="height: 300px" class="is-overflow-auto">
              <div class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped" style="table-layout: fixed;">
                  <thead>
                  <tr>
                    <th class="has-text-centered" style="min-width:150px">Header</th>
                    <th>Value</th>
                  </tr>
                  </thead>
                  <tbody v-if="Object.keys(response.request?.headers ?? {}).length > 0">
                  <tr v-for="(v,k) in response.request.headers" :key="k">
                    <td class="is-vcentered is-ellipsis">
                      <abbr :title="uc_words(k)" v-text="uc_words(k)" class="is-pointer-help"/>
                    </td>
                    <td class="is-vcentered">{{ v }}</td>
                  </tr>
                  </tbody>
                  <tbody v-else>
                  <tr>
                    <td colspan="2" class="has-text-centered">No request headers found.</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-6-tablet is-12-mobile" v-if="response.response.status">
        <div class="card">
          <div class="card-header is-clickable" @click="toggle_response = !toggle_response">
            <div class="card-header-title is-block is-ellipsis">
              <span class="is-underlined" :class="colorStatus(response.response.status)">{{
                  response.response.status
                }}</span>
              Status code response.
            </div>
            <button class="card-header-icon">
              <span class="icon">
                <i class="fas" :class="{'fa-arrow-up': toggle_response, 'fa-arrow-down': !toggle_response}"/>
              </span>
            </button>
          </div>
          <div class="card-content content p-0 m-0" v-if="toggle_response">
            <div style="height: 300px" class="is-overflow-auto">
              <div class="table-container">
                <table class="table is-fullwidth is-bordered is-hoverable is-striped" style="table-layout: fixed;">
                  <thead>
                  <tr>
                    <th class="has-text-centered" style="width:150px">Header</th>
                    <th>Value</th>
                  </tr>
                  </thead>
                  <tbody v-if="Object.keys(response.response?.headers ?? {}).length > 0">
                  <tr v-for="(v,k) in response.response.headers" :key="k">
                    <td class="is-vcentered is-ellipsis">
                      <abbr :title="uc_words(k)" v-text="uc_words(k)" class="is-pointer-help"/>
                    </td>
                    <td class="is-vcentered" :class="colorize(k)">{{ v }}</td>
                  </tr>
                  </tbody>
                  <tbody v-else>
                  <tr>
                    <td colspan="2" class="has-text-centered">No response headers found.</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="response.response.status">
        <div class="card">
          <div class="card-header is-clickable" @click="toggle_body = !toggle_body">
            <div class="card-header-title is-block is-ellipsis" :class="colorStatus(response.response.status)">
              ( <span class="is-underlined">{{ response.response.status }}</span> ) Response Body
            </div>
            <button class="card-header-icon">
              <span class="icon">
                <i class="fas" :class="{'fa-arrow-up': toggle_body, 'fa-arrow-down': !toggle_body}"/>
              </span>
            </button>
          </div>
          <div class="card-content content p-0 m-0" v-if="toggle_body">
            <div style="max-height: 300px" class="is-overflow-auto">
              <pre><code>{{
                  response.response.body ? tryParse(response.response.body) : 'Empty body'
                }}</code></pre>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              Values in the form with <code>[...]</code> are <strong>template values</strong>. If they are part of a
              string, please only replace the bracket and the value inside it. For example, <code>[ip:port]</code>
              should be replaced with <code>192.168.8.1:8096</code>.
            </li>
            <li>
              If you see a <span class="has-text-success">green status code (200-299)</span>, it means the request was
              successful.
            </li>
            <li>
              If you see a <span class="has-text-danger">red status code (400-499)</span>, it means the request was
              rejected. by the target or the WatchState.
            </li>
            <li>
              If you see a <span class="has-text-warning">yellow status code (300-399)</span>, it means the request was
              redirected. This is not necessarily an error or successful request, but you should check the response and
              follow the redirect.
            </li>
            <li>
              If you see a <span class="has-text-purple">purple status code (500+)</span>, it means the server
              encountered an error.
            </li>
            <li>You can add this special header <code>ws-timeout</code> to control the connection timeout for the http
              library.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from "~/utils/request.js"
import {notification} from "~/utils/index.js"
import Message from "~/components/Message.vue"
import {useStorage} from "@vueuse/core"

useHead({title: 'URL Checker'})

const show_page_tips = useStorage('show_page_tips', true)
const toggle_form = ref(true)
const toggle_request = ref(true)
const toggle_response = ref(true)
const toggle_body = ref(true)
const use_template = ref("")
const templates = ref([
  {
    "key": "Plex Media Server",
    "override": {
      "method": "GET",
      "url": "http://[ip:port]/library/sections",
      "headers": [
        {"key": "Accept", "value": "application/json"},
        {"key": "X-Plex-Token", "value": "[PLEX_TOKEN]"},
      ]
    },
  },
  {
    "key": "Jellyfin/Emby Server",
    "override": {
      "method": "GET",
      "url": "http://[ip:port]/items",
      "headers": [
        {"key": "Accept", "value": "application/json"},
        {"key": "X-MediaBrowser-Token", "value": "[API_KEY]"},
      ]
    },
  },
])
const methods = ref(['GET', 'POST', 'PUT', 'PATCH', 'HEAD', 'DELETE'])
const item = ref({"url": "", "method": "GET", "headers": []})
const is_loading = ref(false)

const default_response = {
  request: {url: "", method: "GET", headers: []},
  response: {status: null, headers: [], body: ""}
}
const response = ref(default_response)

watch(use_template, async newValue => {
  if ("" === newValue) {
    return
  }

  const template = templates.value.find(t => t.key === newValue)
  if (!template) {
    notification('error', 'Error', 'Template not found')
    return
  }

  item.value = template.override
  await nextTick()

  use_template.value = ""
})

const reset_form = async () => item.value = default_response.request

const invalid_form = computed(() => {
  if (!item.value.url) {
    return true
  }

  if (!item.value.method) {
    return true
  }

  try {
    new URL(item.value.url)
  } catch (e) {
    return true
  }

  return false;
});

const has_template_values = () => {
  if (/\[.+?]/.test(item.value.url)) {
    return true
  }

  for (const header of item.value.headers) {
    if (/\[.+?]/.test(header.key) || /\[.+?]/.test(header.value)) {
      return true
    }
  }

  return false;
}

const add_header = (k, v) => item.value.headers.push({key: k ?? "", value: v ?? ""})

const check_url = async () => {
  if (true === invalid_form.value) {
    notification('error', 'Error', 'Please fill in all required fields.')
    return
  }

  if (has_template_values() && !confirm('The form contains template values. Do you want to continue?')) {
    return
  }
  try {
    response.value = default_response
    await nextTick()

    is_loading.value = true
    const resp = await request('/system/url/check', {
      method: 'POST',
      body: JSON.stringify(item.value),
    });

    const json = await parse_api_response(resp)

    if (200 !== resp.status) {
      notification('error', 'Error', `${json.error.code ?? resp.status}: ${json.error.message ?? 'Unknown error'}`)
      return;
    }

    response.value = json;
    toggle_form.value = false
    toggle_request.value = false
  } catch (e) {
    notification('error', `failed to send request. ${e}`);
  } finally {
    is_loading.value = false
  }
}

const uc_words = str => str.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());

const tryParse = body => {
  try {
    return JSON.stringify(JSON.parse(body), null, 2)
  } catch (e) {
    return body
  }
}

const colorStatus = status => {
  if (status >= 200 && status < 300) {
    return 'has-text-success'
  } else if (status >= 300 && status < 400) {
    return 'has-text-warning'
  } else if (status >= 400 && status < 500) {
    return 'has-text-danger'
  } else if (status >= 500) {
    return 'has-text-purple'
  }
}

const toggleForm = () => {
  if (!item.value.url) {
    toggle_form.value = true
    return
  }

  toggle_form.value = !toggle_form.value
}
const colorize = k => k.toLowerCase().startsWith('ws-') ? 'has-text-danger' : ''

onMounted(() => disableOpacity())
onBeforeUnmount(() => enableOpacity())
</script>
