<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-calendar-alt"></i></span>
          Events
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="toggleFilter || query">
              <form @submit.prevent="loadContent">
                <input type="search" v-model="query" class="input" id="filter" placeholder="Search & Filter">
                <span class="icon is-left"><i class="fas fa-filter"/></span>
              </form>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter = !toggleFilter">
                <span class="icon"><i class="fas fa-filter"/></span>
              </button>
            </div>

            <div class="control">
              <button class="button is-danger" @click="deleteAll" v-tooltip.bottom="'Remove All non pending events.'">
                <span class="icon"><i class="fas fa-trash"/></span>
              </button>
            </div>

            <p class="control">
              <button class="button is-info" @click="loadContent(page, false)" :class="{ 'is-loading': isLoading }"
                      :disabled="isLoading">
                <span class="icon"><i class="fas fa-sync"/></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            Show events that are queued to be dispatched, or have been dispatched.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="total && last_page > 1">
        <Pager @navigate="ePage => loadContent(ePage)" :last_page="last_page" :page="page" :is-loading="isLoading"/>
      </div>
    </div>

    <div class="columns is-multiline" v-if="filteredRows.length < 1">
      <div class="column is-12">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else class="has-background-warning-80 has-text-dark" title="Warning"
                 icon="fas fa-exclamation-triangle">
          <p>No items found.</p>
          <p v-if="query">Search for <strong>{{ query }}</strong> returned no results.</p>
        </Message>
      </div>
    </div>

    <div class="columns is-multiline">
      <div class="column is-6 is-12-mobile" v-for="item in filteredRows" :key="item.id">
        <div class="card">
          <header class="card-header is-align-self-flex-end">
            <div class="card-header-title is-block">
              <NuxtLink @click="quick_view = item.id" v-text="makeName(item.id)"/>
              <span v-if="item?.delay_by" class="tag is-warning is-pulled-right is-hidden-mobile has-tooltip"
                    v-tooltip="'The event dispatching was delayed by this many seconds.'">
                <span class="icon"><i class="fas fa-clock"/></span>
                <span>{{ item.delay_by }}s</span>
              </span>

              <div class="is-pulled-right is-hidden-tablet">
                <span class="tag" :class="getStatusClass(item.status)">{{ statuses[item.status].name }}</span>
              </div>
            </div>
            <div class="card-header-icon">
              <span class="icon" @click="item._display = !item._display" v-if="Object.keys(item.event_data).length > 0">
                <i class="fas" :class="{ 'fa-arrow-up': item?._display, 'fa-arrow-down': !item?._display }"/>
              </span>
            </div>
          </header>
          <div class="card-content p-0 m-0" v-if="item._display">
            <pre class="p-0 is-pre" style="position: relative; max-height:30vh; overflow-y:scroll;"><code>{{
                JSON.stringify(item.event_data, null, 2)
              }}</code><button class="button is-small m-4"
                               @click="() => copyText(JSON.stringify(item.event_data), false)"
                               style="position: absolute; top:0; right:0;">
                <span class="icon"><i class="fas fa-copy"></i></span></button></pre>
          </div>
          <div class="card-footer">
            <div class="card-footer-item is-hidden-mobile">
              <span class="tag" :class="getStatusClass(item.status)">{{ statuses[item.status].name }}</span>
            </div>
            <span class="card-footer-item">
              <span class="icon"><i class="fas fa-calendar"></i></span>
              <time class="has-tooltip" v-tooltip="`Created at: ${moment(item.created_at)}`">
                {{ moment(item.created_at).fromNow() }}
              </time>
            </span>
            <span class="card-footer-item">
              <template v-if="!item.updated_at">
                <span v-if="0 === item.status" class="icon">
                  <i class="fas fa-spinner fa-spin"></i>
                </span>
                <span v-else>None</span>
              </template>
              <template v-else>
                <span class="icon"><i class="fas fa-calendar-alt"></i></span>
                <time class="has-tooltip" v-tooltip="`Updated at: ${moment(item.updated_at)}`">
                  {{ moment(item.updated_at).fromNow() }}
                </time>
              </template>
            </span>
          </div>
          <footer class="card-footer">
            <div class="card-footer-item" v-text="item.event"/>
            <div class="card-footer-item">
              <button class="button is-warning is-fullwidth" @click="resetEvent(item, 0 === item.status ? 4 : 0)">
                <span class="icon"><i class="fas fa-trash-arrow-up"></i></span>
                <span>{{ 0 === item.status ? 'Stop' : 'Reset' }}</span>
              </button>
            </div>
            <div class="card-footer-item">
              <button class="button is-danger is-fullwidth" @click="deleteItem(item)">
                <span class="icon"><i class="fas fa-trash"></i></span>
                <span>Delete</span>
              </button>
            </div>
          </footer>
        </div>
      </div>
    </div>

    <div class="columns is-multiline">
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>Resetting event will return it to the queue to be dispatched again.</li>
            <li>Stopping event will prevent it from being dispatched.</li>
            <li>Events with status of <span class="tag is-warning">Running</span> Cannot be cancelled or stopped.</li>
            <li>The filter <i class="fa fa-filter"/> button on top can be used for both filtering the displayed
              results, and on submit it will search the backend for the given event name.
            </li>
          </ul>
        </Message>
      </div>
    </div>

    <template v-if="quick_view">
      <Overlay @closeOverlay="quick_view = null" :title="`#${makeName(quick_view)}`">
        <EventView :id="quick_view" @delete="item => deleteItem(item)"/>
      </Overlay>
    </template>
  </div>
</template>

<script setup>
import {copyText, notification, parse_api_response} from '~/utils/index'
import request from '~/utils/request'
import moment from 'moment'
import Pager from '~/components/Pager'
import {getStatusClass, makeName} from '~/utils/events/helpers'
import Message from '~/components/Message'
import {useStorage} from '@vueuse/core'

const route = useRoute()

const total = ref(0)
const page = ref(parseInt(route.query.page ?? 1))
const perpage = ref(parseInt(route.query.perpage ?? 26))
const last_page = computed(() => Math.ceil(total.value / perpage.value))

const isLoading = ref(false)
const toggleDispatcher = ref(false)
const items = ref([])
const statuses = ref([])
const query = ref(route.query.filter ?? '')
const toggleFilter = ref(false)
const quick_view = ref()
const show_page_tips = useStorage('show_page_tips', true)

watch(toggleFilter, () => {
  if (!toggleFilter.value) {
    query.value = ''
  }
});

const filteredRows = computed(() => {
  if (!query.value) {
    return items.value
  }

  const toTower = query.value.toLowerCase();

  return items.value.filter(i => {
    return Object.keys(i).some(k => {
      if (typeof i[k] === 'object' && null !== i[k]) {
        return Object.values(i[k]).some(v => typeof v === 'string' ? v.toLowerCase().includes(toTower) : false)
      }
      return typeof i[k] === 'string' ? i[k].toLowerCase().includes(toTower) : false
    })
  })
});

const loadContent = async (pageNumber, updateHistory = true) => {
  try {
    pageNumber = parseInt(pageNumber)
    let p_perpage = parseInt(perpage.value)

    if (isNaN(pageNumber) || pageNumber < 1) {
      pageNumber = 1
    }

    if (isNaN(p_perpage) || p_perpage < 1) {
      p_perpage = 25
    }

    let queryParams = new URLSearchParams()
    queryParams.append('page', pageNumber)
    queryParams.append('perpage', p_perpage)
    if (query.value) {
      queryParams.append('filter', query.value)
    }

    isLoading.value = true
    toggleDispatcher.value = false
    items.value = []

    const response = await request(`/system/events?${queryParams.toString()}`)
    const json = await parse_api_response(response)

    if (200 !== response.status) {
      notification('error', 'Error', `Events request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    let title = `Events - Page #${pageNumber}`

    useHead({title})

    if (true === Boolean(updateHistory)) {
      let history_query = {
        perpage: p_perpage,
        page: pageNumber,
      }

      if (query.value) {
        history_query.filter = query.value
      }

      await useRouter().push({path: '/events', query: history_query})
    }

    if ('paging' in json) {
      page.value = json.paging.page
      perpage.value = json.paging.perpage
      total.value = json.paging.total
    }

    items.value = json?.items ?? []
    statuses.value = json?.statuses ?? []
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Events Request failure. ${e.message}`
    )
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => {
  await loadContent(page.value)
  window.addEventListener('popstate', handlePopState)
})

onUnmounted(() => window.removeEventListener('popstate', handlePopState))

const handlePopState = async () => {
  const route = useRoute()

  if (route.query?.perpage) {
    perpage.value = route.query.perpage
  }

  if (route.query?.page) {
    page.value = route.query.page
  }

  await loadContent(page.value, false)
}

const deleteItem = async item => {
  if (!confirm(`Delete '${item.id}'?`)) {
    return
  }

  try {
    const response = await request(`/system/events/${item.id}`, {method: 'DELETE'})

    if (200 !== response.status) {
      const json = await parse_api_response(response)
      notification('error', 'Error', `Events delete Request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    deletedItem(item.id)

    notification('success', 'Success', `Event '${makeName(item.id)}' successfully deleted.`)
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Events delete Request failure. ${e.message}`
    )
  }
}

const resetEvent = async (item, status = 0) => {
  if (!confirm(`Reset '${item.id}'?`)) {
    return
  }

  try {
    const response = await request(`/system/events/${item.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        status: status,
        reset_logs: true,
      })
    })

    const json = await parse_api_response(response)

    if (200 !== response.status) {
      notification('error', 'Error', `Events view patch Request error. ${json.error.code}: ${json.error.message}`)
      return
    }

    const index = items.value.findIndex(i => i.id === item.id)

    if (index < 0) {
      return
    }

    items.value[index] = json
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Events view patch Request failure. ${e.message}`
    )
  }
}

const deleteAll = async () => {
  if (!confirm('Delete all non pending events?')) {
    return
  }

  try {
    const response = await request(`/system/events/`, {method: 'DELETE'})
    if (200 !== response.status) {
      const json = await parse_api_response(response)
      notification('error', 'Error', `Failed to delete events. ${json.error.code}: ${json.error.message}`)
      return
    }

    window.location.reload(true)
  } catch (e) {
    console.error(e)
    notification('crit', 'Error', `Events view patch Request failure. ${e.message}`
    )
  }
}

const deletedItem = id => {
  items.value = items.value.filter(i => i.id !== id)
  if (quick_view.value) {
    quick_view.value = null
  }
}

watch(query, val => {
  const route = useRoute()
  const router = useRouter()
  if (!val) {
    if (!route?.query['filter']) {
      return;
    }

    router.push({
      'path': '/events',
      'query': {
        ...route.query,
        'filter': undefined
      }
    })
    return;
  }

  if (route?.query['filter'] === val) {
    return;
  }

  router.push({
    'path': '/events',
    'query': {
      ...route.query,
      'filter': val
    }
  })
})

</script>
