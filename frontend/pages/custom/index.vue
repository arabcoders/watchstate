<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-map"></i></span>
          Custom GUIDs Mapper
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <NuxtLink class="button is-primary" v-tooltip.bottom="'Add New GUID'" to="/custom/add">
                <span class="icon">
                  <i class="fas fa-add"></i>
                </span>
              </NuxtLink>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent" :disabled="isLoading"
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
            This page allow you to add custom GUIDs to the system, this is useful when you want to map a GUID from a
            backend to a different GUID in WatchState. Or add new GUID identifiers to the system.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="!data">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else message_class="has-background-success-90 has-text-dark" title="Information" icon="fas fa-check">
          There are no custom GUIDs configured. You can add new GUIDs by clicking on the <i class="fa fa-add"></i>
          button.
        </Message>
      </div>

      <div class="column is-12" v-if="!isLoading &&data && data.guids">
        <h1 class="is-unselectable title is-4">Custom GUIDs</h1>
        <h2 class="is-unselectable subtitle">
          This section contains the custom GUIDs that are currently configured in the system.
        </h2>
        <div class="columns is-multiline">
          <div class="column is-3-tablet" v-for="(guid, index) in data.guids" :key="guid.name">
            <div class="card">
              <header class="card-header">
                <p class="card-header-title is-text-overflow pr-1">
                  {{ guid.name }}
                </p>
                <span class="card-header-icon">
                  <NuxtLink @click="deleteGUID(index, guid)" class="has-text-danger" v-tooltip="'Delete GUID.'">
                    <span class="icon"><i class="fas fa-trash"></i></span>
                  </NuxtLink>
                </span>
              </header>
              <div class="card-content">
                {{ guid.description }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12 is-clearfix is-unselectable" v-if="hasClientsData">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-exchange-alt"></i></span>
          Clients GUID Mapping
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Add new Link'">
                <span class="icon"><i class="fas fa-add"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This section contains the client to GUID mapping.</span>
        </div>
      </div>

      <div class="column is-12" v-if="hasClientsData">
        <div class="columns is-multiline">
          <template v-if="data" v-for="client in supported" :key="client">
            <div class="column is-6-tablet" v-if="client in data && data[client].length > 0"
                 v-for="(link, index) in data[client]" :key="`${client}-${index}`">
              <div class="card">
                <header class="card-header">
                  <template v-if="link.replace?.from">
                    <p class="card-header-title is-text-overflow pr-1 is-unselectable is-clickable"
                       @click="link.show = !link.show">
                      <span class="icon"><i
                          class="fas"
                          :class="{ 'fa-arrow-down': false === (link.show ?? false), 'fa-arrow-up': true === (link.show ?? false) }"
                      ></i>&nbsp;</span>
                      {{ ucFirst(client) }} client link
                    </p>
                  </template>
                  <template v-else>
                    <p class="card-header-title is-text-overflow pr-1 is-unselectable">
                      {{ ucFirst(client) }} client link
                    </p>
                  </template>
                  <span class="card-header-icon">
                    <NuxtLink @click="deleteLink(index, link)" class="has-text-danger" v-tooltip="'Delete Link.'">
                      <span class="icon"><i class="fas fa-trash"></i></span>
                    </NuxtLink>
                  </span>
                </header>

                <div class="card-content">
                  <div class="columns is-mobile is-multiline">
                    <div class="column is-3 has-text-left">
                      <span class="icon"><i class="fas fa-arrow-right"></i>&nbsp;</span>
                      From
                    </div>
                    <div class="column is-9 has-text-right">
                      {{ link.map.from }}
                    </div>
                    <div class="column is-3 has-text-left">
                      <span class="icon"><i class="fas fa-arrow-left"></i>&nbsp;</span>
                      To
                    </div>
                    <div class="column is-9 has-text-right">
                      {{ link.map.to }}
                    </div>
                    <template v-if="link.replace?.from && ('show' in link && link.show)">
                      <div class="column is-3 has-text-left">
                        <span class="icon"><i class="fas fa-xmark"></i>&nbsp;</span>
                        Replace
                      </div>
                      <div class="column is-9 has-text-right">
                        {{ link.replace.from }}
                      </div>
                      <div class="column is-3 has-text-left">
                        <span class="icon"><i class="fas fa-check"></i>&nbsp;</span>
                        To
                      </div>
                      <div class="column is-9 has-text-right">
                        {{ link.replace.to }}
                      </div>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              Clients means the internal implementations of the backends in WatchState, for example, Plex, Emby,
              Jellyfin, etc.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import {notification, parse_api_response} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message'

useHead({title: 'Custom Guid Mapper'})

const data = ref({})
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(false)
const supported = ref([]);

const loadContent = async () => {
  try {
    isLoading.value = true
    data.value = await parse_api_response(await request(`/system/guids/custom`))
  } catch (e) {
    isLoading.value = false
    return notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  const supportedClients = await request('/system/supported')
  supported.value = await supportedClients.json()
  await loadContent()
})

const hasClientsData = computed(() => !isLoading.value && supported.value.length > 0 && Object.keys(data.value).length > 0 && supported.value.some(client => client in data.value))
</script>
