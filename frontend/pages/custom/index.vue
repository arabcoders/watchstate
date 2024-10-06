<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fas fa-map"></i></span>
          Custom GUIDs
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
            This page allow you to add custom GUIDs to the system and link them to the client GUIDs.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
      </div>

      <div class="column is-12" v-if="!isLoading && data">
        <div class="columns is-multiline" v-if="data?.guids?.length >0">
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
        <div v-else>
          <Message message_class="has-background-warning-90 has-text-dark" title="Information" icon="fas fa-check">
            There are no custom GUIDs configured. You can add new GUIDs by clicking on the <i class="fa fa-add"></i>
            button.
          </Message>
        </div>
      </div>

      <div class="column is-12 is-clearfix is-unselectable" v-if="!isLoading">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-exchange-alt"></i></span>
          Client GUID links
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <NuxtLink class="button is-primary" v-tooltip.bottom="'Add new Link'" to="/custom/addlink">
                <span class="icon"><i class="fas fa-add"></i></span>
              </NuxtLink>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">This section contains the client <> WatchState GUID links.</span>
        </div>
      </div>

      <div class="column is-12">
        <div class="column is-12" v-if="!isLoading && data && data?.links?.length > 0">
          <div class="columns is-multiline">
            <div class="column is-6-tablet" v-for="(link,index) in data.links" :key="link.id">
              <div class="card">
                <header class="card-header">
                  <template v-if="link.replace?.from">
                    <p class="card-header-title is-text-overflow pr-1 is-unselectable is-clickable"
                       @click="link.show = !link.show">
                      <span class="icon"><i
                          class="fas"
                          :class="{ 'fa-arrow-down': false === (link.show ?? false), 'fa-arrow-up': true === (link.show ?? false) }"
                      ></i>&nbsp;</span>
                      {{ ucFirst(link.type) }} client link
                    </p>
                  </template>
                  <template v-else>
                    <p class="card-header-title is-text-overflow pr-1 is-unselectable">
                      {{ ucFirst(link.type) }} client link
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
                    <div class="column is-5 has-text-left">
                      <span class="icon"><i class="fas fa-arrow-right"></i>&nbsp;</span>
                      From Client GUID
                    </div>
                    <div class="column is-7 has-text-right">
                      {{ link.map.from }}
                    </div>
                    <div class="column is-5 has-text-left">
                      <span class="icon"><i class="fas fa-arrow-left"></i>&nbsp;</span>
                      To WatchState GUID
                    </div>
                    <div class="column is-7 has-text-right">
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
                        With
                      </div>
                      <div class="column is-9 has-text-right">
                        {{ link.replace.to }}
                      </div>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else>
          <Message message_class="has-background-warning-90 has-text-dark" title="Information" icon="fas fa-xmark">
            There are no client links configured. You can add new links by clicking on the <i class="fa fa-add"></i>
            button.
          </Message>
        </div>
      </div>
      <!--      <div class="column is-12">-->
      <!--        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"-->
      <!--                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">-->
      <!--          <ul>-->
      <!--            <li>-->
      <!--            </li>-->
      <!--          </ul>-->
      <!--        </Message>-->
      <!--      </div>-->
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import request from '~/utils/request'
import {notification, parse_api_response} from '~/utils/index'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message'

useHead({title: 'Custom Guids'})

const data = ref({})
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref(false)

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

onMounted(async () => await loadContent())

const deleteGUID = async (index, guid) => {
  if (!confirm(`Are you sure you want to delete the GUID: '${guid.name}'?`)) {
    return
  }

  try {
    const response = await request(`/system/guids/custom/${guid.id}`, {method: 'DELETE'})
    const result = await parse_api_response(response)
    if (response.ok) {
      data.value.guids.splice(index, 1)
      notification('success', 'Success', result.message)
    } else {
      notification('error', 'Error', result.error.message)
    }
  } catch (e) {
    return notification('error', 'Error', e.message)
  }
}
</script>
