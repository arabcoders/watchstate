<template>
  <div class="p-3" style="min-width: 260px; max-width: 320px;">
    <div v-if="isLoading" class="is-flex is-align-items-center is-size-7 has-text-weight-medium">
      <span class="icon has-text-info mr-2"><i class="fas fa-spinner fa-spin" /></span>
      <span>Loading records...</span>
    </div>

    <div v-else-if="errorMessage" class="notification is-danger is-light is-size-7">
      <span class="icon-text">
        <span class="icon has-text-danger"><i class="fas fa-exclamation-triangle" /></span>
        <span>{{ errorMessage }}</span>
      </span>
    </div>

    <div v-else-if="0 === records.length" class="notification is-info is-light is-size-7">
      <span class="icon-text">
        <span class="icon has-text-info"><i class="fas fa-info-circle" /></span>
        <span>No other records reference this file.</span>
      </span>
    </div>

    <div v-else>
      <h2 class="is-size-6 has-text-weight-semibold mb-3">
        <span class="icon"><i class="fas fa-copy" /></span>
        Duplicate File references.
      </h2>
      <div v-for="(record, index) in records" :key="record.id" class="py-2">
        <div class="level is-mobile mb-1">
          <div class="level-left">
            <div class="level-item">
              <NuxtLink :to="`/backend/${record.via}`" class="is-small tag is-info is-small">
                <span class="icon"><i class="fas fa-server" /></span>
                {{ record.via }}
              </NuxtLink>
            </div>
          </div>
          <div class="level-right" v-if="record.updated">
            <div class="level-item has-text-grey-light is-size-7 is-flex is-align-items-center">
              <span class="icon is-small"><i class="fas fa-clock" /></span>
              <span class="ml-1">{{ moment.unix(record.updated).fromNow() }}</span>
            </div>
          </div>
        </div>
        <p class="is-size-7 mb-0">
          <NuxtLink :to="`/history/${record.id}`">
            {{ record.full_title || makeName(record as unknown as JsonObject) }}
          </NuxtLink>
        </p>
        <hr v-if="index < records.length - 1" class="my-2" />
      </div>
    </div>
  </div>
</template>


<script setup lang="ts">
import {ref} from 'vue'
import moment from 'moment'
import {NuxtLink} from '#components'
import {makeName, parse_api_response, request} from '~/utils'
import { useSessionCache } from '~/utils/cache'
import type {HistoryItem, JsonObject} from '~/types'

const props = defineProps<{ ids: Array<number> }>()

const records = ref<Array<HistoryItem>>([])
const isLoading = ref<boolean>(true)
const errorMessage = ref<string>('')
const cache = useSessionCache()

onMounted(async () => {
  const unique = new Set<number>()
  for (const value of props.ids ?? []) {
    if (!Number.isFinite(value)) {
      continue
    }
    unique.add(Number(value))
  }

  const ids = Array.from(unique)

  if (0 === ids.length) {
    isLoading.value = false
    return
  }

  try {
    const items: Array<HistoryItem> = []
    const missingIds: Array<number> = []

    for (const id of ids) {
      const cacheKey = `history_${id}`
      const cachedItem = cache.get<HistoryItem>(cacheKey) ?? null
      if (null !== cachedItem) {
        items.push(cachedItem)
        continue
      }
      missingIds.push(id)
    }

    await Promise.all(missingIds.map(async id => {
      const response = await request(`/history/${id}`)
      const record = await parse_api_response<HistoryItem>(response)
      if ('error' in record) {
        throw new Error(record.error.message || `Unable to load record ${id}`)
      }
      cache?.set(`history_${id}`, record, 300)
      items.push(record)
    }))

    records.value = items
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'Failed to load records.'
  } finally {
    isLoading.value = false
  }
})
</script>
