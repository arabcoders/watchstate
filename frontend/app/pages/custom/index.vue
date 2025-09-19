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
                :class="{ 'is-loading': isLoading }">
                <span class="icon">
                  <i class="fas fa-sync"></i>
                </span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">User defined custom GUIDs.</span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
          message="Loading data. Please wait..." />
      </div>

      <div class="column is-12" v-if="!isLoading && guids">
        <div class="columns is-multiline" v-if="guids?.length > 0">
          <div class="column is-3-tablet" v-for="(guid, index) in guids" :key="guid.name">
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
          <Message message_class="has-background-warning-90 has-text-dark" title="Information"
            icon="fas fa-exclamation">
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
          <span class="subtitle">Client <--> WatchState GUID links.</span>
        </div>
      </div>

      <div class="column is-12">
        <div class="column is-12" v-if="!isLoading && links?.length > 0">
          <div class="columns is-multiline">
            <div class="column is-6-tablet" v-for="(link, index) in links" :key="link.id">
              <div class="card">
                <header class="card-header">
                  <template v-if="link.replace?.from">
                    <p class="card-header-title is-text-overflow pr-1 is-unselectable is-clickable"
                      @click="link.show = !link.show">
                      <span class="icon"><i class="fas"
                          :class="{ 'fa-arrow-down': false === (link.show ?? false), 'fa-arrow-up': true === (link.show ?? false) }"></i>&nbsp;</span>
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
          <Message message_class="has-background-warning-90 has-text-dark" title="Information"
            icon="fas fa-exclamation">
            There are no client GUID links configured. You can add new links by clicking on the <i
              class="fa fa-add"></i>
            button.
          </Message>
        </div>
      </div>
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
          @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>Using this feature allows you to extend <code>WatchState</code> to support more less known or regional
              specific metadata databases. We cannot add support directly to all databases, so this feature instead
              allow you to manually do it yourself.
            </li>
            <li>
              Adding Custom guid without a client/s links is useless as the parsing engine will not know what to do with
              it. So, make sure to add a client GUID link referencing the custom GUID.
            </li>
            <li>The guid names are unique. Therefore, you cannot reuse existing ones.</li>
            <li>You cannot add link from the same client GUID twice. For example you cannot add <code>jellyfin:foobar ->
          WatchState:guid_foobar</code> and another for <code>jellyfin:foobar -> guid_imdb</code>.
            </li>
            <li>Editing the <code>guid.yaml</code> file directly is unsupported and might lead to unexpected behavior.
              Please use the WebUI to manage the GUIDs. as we expose the entire functionality via the WebUI. with
              safeguards to prevent you from doing something that might break the system.
            </li>
            <li>If you added or removed Custom GUID, you should run
              <NuxtLink :to="makeConsoleCommand('system:index --force-reindex', false)">
                <span class="icon"><i class="fas fa-terminal"></i>&nbsp;</span>
                <span>system:index --force-reindex</span>
              </NuxtLink>
              command to rebuild the database indexes. While not required, it is recommended to ensure the database is
              up to date. and the indexing is correct and for speedy database operations.
            </li>
            <li>The links are global for each client, not the backend itself. So, For example, if you have NN jellyfin
              backends and you add new GUID link for jellyfin, it will be applied to all jellyfin backends. The backends
              themselves don't need to report it, however the support will be available for all backends.
            </li>
            <li>For more information please read the content in the <code>FAQ.md</code> page, or directly via
              <NuxtLink target="_blank"
                to="https://github.com/arabcoders/watchstate/blob/master/FAQ.md#advanced-how-to-extend-the-guid-parser-to-support-more-guids-or-custom-ones">
                <span class="icon"><i class="fas fa-external-link-alt"></i>&nbsp;</span>
                <span>this link</span>
              </NuxtLink>
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useHead, navigateTo } from '#app'
import { useStorage } from '@vueuse/core'
import { request, makeConsoleCommand, notification, parse_api_response, ucFirst } from '~/utils'
import Message from '~/components/Message.vue'
import type { CustomGUID, CustomLink } from '~/types'
import '~/assets/css/bulma-switch.css'

type CustomLinkWithUI = CustomLink & { show?: boolean }
useHead({ title: 'Custom Guids' })

const guids = ref<Array<CustomGUID>>([])
const links = ref<Array<CustomLinkWithUI>>([])
const show_page_tips = useStorage('show_page_tips', true)
const isLoading = ref<boolean>(false)

const loadContent = async (): Promise<void> => {
  isLoading.value = true
  try {
    const response = await request('/system/guids/custom')
    const data = await parse_api_response<{
      guids: Record<string, CustomGUID>,
      links: Record<string, CustomLink>
    }>(response)

    if ('error' in data) {
      notification('error', 'Error', data.error.message)
      return
    }

    // Clear existing data
    guids.value = []
    links.value = []

    // Convert object values to arrays
    guids.value = Object.values(data.guids)
    links.value = Object.values(data.links)
  } catch (e) {
    const error = e as Error
    notification('error', 'Error', error.message)
  } finally {
    isLoading.value = false
  }
}

onMounted(async (): Promise<void> => {
  await loadContent()
})

const deleteGUID = async (index: number, guid: CustomGUID): Promise<void> => {
  if (!confirm(`Delete '${guid.name}'? links using this GUID will be deleted as well.`)) {
    return
  }

  try {
    const response = await request(`/system/guids/custom/${guid.id}`, { method: 'DELETE' })
    if (!response.ok) {
      const result = await parse_api_response(response)
      if ('error' in result) {
        notification('error', 'Error', result.error.message)
      }
      return
    }

    guids.value.splice(index, 1)
    links.value = links.value.filter(link => link.map.to !== guid.name)

    notification('success', 'Success', `The GUID '${guid.name}' has been deleted.`)
  } catch (e) {
    const error = e as Error
    notification('error', 'Error', error.message)
  }
}

const deleteLink = async (index: number, link: CustomLinkWithUI): Promise<void> => {
  if (!confirm(`Are you sure you want to delete the '${link.type}' - '${link.id}'?`)) {
    return
  }

  try {
    const response = await request(`/system/guids/custom/${link.type}/${link.id}`, { method: 'DELETE' })
    if (!response.ok) {
      const result = await parse_api_response(response)
      if ('error' in result) {
        notification('error', 'Error', result.error.message)
      }
      return
    }

    links.value.splice(index, 1)
    notification('success', 'Success', `The link '${link.type}' - '${link.id}' has been deleted.`)
  } catch (e) {
    const error = e as Error
    notification('error', 'Error', error.message)
  }
}
</script>
