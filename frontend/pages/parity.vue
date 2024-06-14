<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4 ">Data Parity</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <div class="control" v-if="min && max" v-tooltip.bottom="'Minimum number of backends'">
            <div class="select">
              <select v-model="min" :disabled="isDeleting || isLoading">
                <option v-for="i in numberRange(1,max+1)" :key="`min-${i}`" :value="i">
                  {{ i }}
                </option>
              </select>
            </div>
          </div>
          <p class="control">
            <button class="button is-danger" @click="deleteData" v-tooltip.bottom="'Delete The reported records'"
                    :disabled="isDeleting || isLoading || items.length<1" :class="{'is-loading':isDeleting}">
              <span class="icon"><i class="fas fa-trash"></i></span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click.prevent="loadContent(page, true)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">This page shows records that aren't reported by all your backends.</span>
      </div>
    </div>

    <div class="column is-12" v-if="total && last_page > 1">
      <div class="field is-grouped">
        <div class="control" v-if="page !== 1">
          <button rel="first" class="button" @click="loadContent(1)" :disabled="isLoading"
                  :class="{'is-loading':isLoading}">
            <span><<</span>
          </button>
        </div>
        <div class="control" v-if="page > 1 && (page-1) !== 1">
          <button rel="prev" class="button" @click="loadContent(page-1)" :disabled="isLoading"
                  :class="{'is-loading':isLoading}">
            <span><</span>
          </button>
        </div>
        <div class="control">
          <div class="select">
            <select v-model="page" @change="loadContent(page)" :disabled="isLoading">
              <option v-for="(item, index) in makePagination()" :key="index" :value="item.page">
                {{ item.text }}
              </option>
            </select>
          </div>
        </div>
        <div class="control" v-if="page !== last_page && (page+1) !== last_page">
          <button rel="next" class="button" @click="loadContent(page+1)" :disabled="isLoading"
                  :class="{'is-loading':isLoading}">
            <span>></span>
          </button>
        </div>
        <div class="control" v-if="page !== last_page">
          <button rel="last" class="button" @click="loadContent(last_page)" :disabled="isLoading"
                  :class="{'is-loading':isLoading}">
            <span>>></span>
          </button>
        </div>
      </div>
    </div>

    <div class="column is-12">
      <div class="columns is-multiline" v-if="items?.length>0">
        <div class="column is-6-tablet" v-for="item in items" :key="item.id">
          <div class="card" :class="{ 'is-success': item.watched }">
            <header class="card-header">
              <p class="card-header-title is-text-overflow pr-1">
                <NuxtLink :to="'/history/'+item.id" v-text="item.full_title ?? item.title"/>
              </p>
              <span class="card-header-icon">
                <span class="icon">
                  <i class="fas"
                     :class="{ 'fa-tv': 'episode' === item.type.toLowerCase(), 'fa-film': 'movie' === item.type.toLowerCase()}"></i>
                </span>
              </span>
            </header>
            <div class="card-content">
              <div class="columns is-multiline is-mobile">
                <div class="column is-12 " v-if="item?.title">
                  <div class="is-text-overflow is-clickable"
                       @click="(e) => e.target.classList.toggle('is-text-overflow')">
                    <span class="icon"><i class="fas fa-heading"></i></span>
                    <span class="is-hidden-mobile">Title:&nbsp;</span>
                    {{ item.title }}
                  </div>
                </div>
                <div class="column is-12 is-clickable " v-if="item?.path"
                     @click="(e) => e.target.firstChild?.classList?.toggle('is-text-overflow')">
                  <div class="is-text-overflow">
                    <span class="icon"><i class="fas fa-file"></i></span>
                    <span class="is-hidden-mobile">File:&nbsp;</span>
                    <NuxtLink :to="makeSearchLink('path',item.path)" v-text="item.path"/>
                  </div>
                </div>
                <div class="column is-12">
                  <span class="icon"><i class="fas fa-check"></i></span>
                  <span v-for="backend in item.reported_by">
                    <NuxtLink :to="'/backend/'+backend" v-text="backend"
                              class="tag"/>
                    &nbsp;
                  </span>
                </div>
                <div class="column is-12">
                  <span class="icon"><i class="fas fa-times"></i></span>
                  <span v-for="backend in item.not_reported_by">
                    <NuxtLink :to="'/backend/'+backend" v-text="backend"
                              class="tag"/>
                    &nbsp;
                  </span>
                </div>
              </div>
            </div>
            <div class="card-footer">
              <div class="card-footer-item">
                <span class="icon-text">
                  <span class="icon">
                    <i class="fas" :class="{'fa-eye':item.watched,'fa-eye-slash':!item.watched}"></i>
                  </span>
                  <span class="has-text-success" v-if="item.watched">Played</span>
                  <span class="has-text-danger" v-else>Unplayed</span>
                </span>
              </div>
              <div class="card-footer-item">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-calendar"></i></span>
                  <span>{{ moment(item.updated).fromNow() }}</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12" v-else>
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>

        <Message message_class="has-background-success-90 has-text-dark" v-if="!isLoading && items.length<1"
                 title="Success" icon="fas fa-check">
          WatchState did not find any records matching the criteria. All records has at least <code>{{ min }}</code>
          backends reporting it.
        </Message>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              You can specify the minimum number of backends that need to report the record to be considered valid.
            </li>
            <li>
              By clicking the <span class="fa fa-trash"></span> icon you will delete the the reported items from the
              local database. If the items are not fixed by the time <code>import</code> is run, they will re-appear.
            </li>
            <li>
              Deleting records works by deleting everything at or below the specified number of backends. For example,
              if you set the minimum to <code>3</code>, all records that are reported by <code>3</code> or fewer
              backends will be deleted.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import Message from '~/components/Message.vue'
import {makeSearchLink, notification} from '~/utils/index.js'
import moment from 'moment'
import {useStorage} from '@vueuse/core'

const route = useRoute()

useHead({title: 'Parity'})

const items = ref([])
const page = ref(route.query.page ?? 1)
const perpage = ref(route.query.perpage ?? 100)
const total = ref(0)
const last_page = computed(() => Math.ceil(total.value / perpage.value))
const isLoading = ref(false)
const isDeleting = ref(false)
const show_page_tips = useStorage('show_page_tips', true)
/** @type {Ref<number>} */
const min = ref(route.query.min ?? null);
/** @type {Ref<number>} */
const max = ref();

const loadContent = async (pageNumber, fromPopState = false) => {
  pageNumber = parseInt(pageNumber)

  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1
  }

  let search = new URLSearchParams()
  search.set('perpage', perpage.value)
  search.set('page', pageNumber)
  if (min.value) {
    search.set('min', min.value)
  }

  let pageTitle = `Parity: Page #${pageNumber}`

  if (min.value) {
    pageTitle += ` - Min: ${min.value}`
  }

  useHead({title: pageTitle})

  let newUrl = window.location.pathname + '?' + search.toString()
  isLoading.value = true
  items.value = []

  try {
    const response = await request(`/system/parity/?${search.toString()}`)
    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `API Error. ${json.error.code}: ${json.error.message}`)
      isLoading.value = false
      return
    }

    if (!fromPopState && window.location.href !== newUrl) {
      window.history.pushState({page: pageNumber, min: min.value}, '', newUrl)
    }

    if ('paging' in json) {
      page.value = json.paging.current_page
      perpage.value = json.paging.perpage
      total.value = json.paging.total
    } else {
      page.value = 1
      total.value = 0
    }

    if (json.items) {
      items.value = json.items
    }

    isLoading.value = false
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const makePagination = () => {
  let pagination = []
  let pages = Math.ceil(total.value / perpage.value)

  if (pages < 2) {
    return pagination
  }

  for (let i = 1; i <= pages; i++) {
    pagination.push({
      page: i,
      text: `Page #${i}`,
      selected: parseInt(page.value) === i,
    })
  }

  return pagination
}
const deleteData = async () => {
  if (isDeleting.value) {
    return
  }
  if (!min.value) {
    notification('error', 'Error', 'Minimum number of backends is not set.')
    return
  }

  if (items.value.length < 1) {
    notification('error', 'Error', 'There are no reported records to delete.')
    return
  }

  if (!confirm(`Are you sure you want to delete the reported records?`)) {
    return
  }

  isDeleting.value = true

  try {
    const response = await request(`/system/parity`, {
      method: 'DELETE',
      body: JSON.stringify({min: min.value})
    })

    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success!', `Deleted '${json.deleted_records ?? 0}' records.`)
    items.value = []
    total.value = 0
    page.value = 1
  } catch (e) {
    notification('error', 'Error', e.message)
  } finally {
    isDeleting.value = false
  }
};

onMounted(async () => {
  const response = await request(`/backends/`)
  const json = await response.json()
  max.value = json.length
  if (min.value === null) {
    min.value = json.length
  } else {
    await loadContent(page.value ?? 1)
  }
})

const numberRange = (start, end) => new Array(end - start).fill().map((d, i) => i + start)

watch(min, async () => await loadContent(page.value ?? 1))
</script>
