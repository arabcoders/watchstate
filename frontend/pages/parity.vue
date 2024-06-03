<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4 is-unselectable">Data Parity</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-danger" @click="deleteData" v-tooltip="'Delete The reported records'">
              <span class="icon">
                <i class="fas fa-trash"></i>
              </span>
            </button>
          </p>
          <p class="control">
            <button class="button is-info" @click.prevent="loadContent(page, true)" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile is-unselectable">
        <span class="subtitle">This page shows records that aren't reported by all your backends.</span>
      </div>
    </div>

    <div class="column is-12" v-if="total && last_page > 1">
      <div class="field is-grouped">
        <div class="control" v-if="page !== 1">
          <button rel="first" class="button" @click="loadContent(1)">
            <span><<</span>
          </button>
        </div>
        <div class="control" v-if="page > 1 && (page-1) !== 1">
          <button rel="prev" class="button" @click="loadContent(page-1)">
            <span><</span>
          </button>
        </div>
        <div class="control">
          <div class="select">
            <select v-model="page" @change="loadContent(page)">
              <option v-for="(item, index) in makePagination()" :key="index" :value="item.page">
                {{ item.text }}
              </option>
            </select>
          </div>
        </div>
        <div class="control" v-if="page !== last_page && (page+1) !== last_page">
          <button rel="next" class="button" @click="loadContent(page+1)">
            <span>></span>
          </button>
        </div>
        <div class="control" v-if="page !== last_page">
          <button rel="last" class="button" @click="loadContent(last_page)">
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
                <NuxtLink :to="makeSearchLink(item)">
                  <span class="icon">
                    <i class="fas"
                       :class="{ 'fa-tv': 'episode' === item.type.toLowerCase(), 'fa-film': 'movie' === item.type.toLowerCase()}"></i>
                  </span>
                </NuxtLink>
              </span>
            </header>
            <div class="card-content">
              <div class="content">
                <p>
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-check"></i></span>
                    <span>Reported by:&nbsp;</span>
                  </span>
                  <span v-for="backend in item.reported_by">
                    <NuxtLink :to="'/backend/'+backend" v-text="backend"
                              class="tag"/>
                    &nbsp;
                  </span>
                </p>
                <p>
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-times"></i></span>
                    <span>Not reported by:&nbsp;</span>
                  </span>
                  <span v-for="backend in item.not_reported_by">
                    <NuxtLink :to="'/backend/'+backend" v-text="backend"
                              class="tag"/>
                    &nbsp;
                  </span>
                </p>
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
        <Message message_class="is-info" v-if="true === isLoading">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-spinner fa-pulse"></i></span>
            <span>Loading data please wait...</span>
          </span>
        </Message>

        <Message message_class="has-background-success-90 has-text-dark" title="Success!"
                 v-if="!isLoading && items.length<1">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-check"></i></span>
            <span>WatchState did not find any records matching the criteria.</span>
          </span>
        </Message>
      </div>

      <div class="column is-12" v-if="show_page_tips">
        <Message title="Tips" message_class="has-background-info-90 has-text-dark">
          <button class="delete" @click="show_page_tips=false"></button>
          <div class="content">
            <ul>
              <li>Clicking the icon <span class="fa fa-tv"></span> / <span class="fa fa-film"></span>
                next to the title will trigger search using that record title, while clicking the title will take you to
                the record page.
              </li>
              <li>
                You can specify the minimum number of backends that need to report the record to be considered valid.
                Not available via <code>WebUI</code> yet. You can do it via the
                <NuxtLink :to="makeConsoleCommand('db:parity --min')">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-terminal"></i></span>
                    <span>Console</span>
                  </span>
                </NuxtLink>
                page, or using the the following command <code>db:parity --min NUM</code> in shell.
              </li>
              <li>
                By clicking the <span class="fa fa-trash"></span> icon you will delete the record from the database.
                Not available via <code>WebUI</code> yet. You can do it via the
                <NuxtLink :to="makeConsoleCommand('db:parity --prune --min')">
                  <span class="icon-text">
                    <span class="icon"><i class="fas fa-terminal"></i></span>
                    <span>Console</span>
                  </span>
                </NuxtLink>
                page, or by using the the following command <code>db:parity --min NUM --prune</code> in shell.
              </li>
            </ul>
          </div>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request.js'
import Message from '~/components/Message.vue'
import {makeConsoleCommand, notification} from '~/utils/index.js'
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
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async (pageNumber, fromPopState = false) => {
  pageNumber = parseInt(pageNumber)

  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1
  }

  let search = new URLSearchParams()
  search.set('perpage', perpage.value)
  search.set('page', pageNumber)

  useHead({title: `Parity: Page #${pageNumber}`})

  let newUrl = window.location.pathname + '?' + search.toString()
  isLoading.value = true
  items.value = []

  try {
    const response = await request(`/system/parity/?${search.toString()}`)
    const json = await response.json()

    if (200 !== response.status) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      isLoading.value = false
      return
    }

    if (!fromPopState && window.location.href !== newUrl) {
      window.history.pushState({page: pageNumber}, '', newUrl)
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
    notification('error', 'Error', e.message)
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
const deleteData = () => {
  notification('warning', 'Warning', 'This feature is not implemented yet.')
};

const makeSearchLink = (item) => {
  const params = new URLSearchParams();
  params.append('perpage', '50')
  params.append('page', '1')
  params.append('q', item.title)
  params.append('key', 'title')

  return `/history?${params.toString()}`
}

onMounted(async () => loadContent(page.value ?? 1))
</script>
