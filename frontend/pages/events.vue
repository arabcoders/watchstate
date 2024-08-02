<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-list-check"></i></span>
          Events
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-info" @click.prevent="loadContent">
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page will show events that are queued to be handled or sent to the backends.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="queue.length < 1 && progress.length < 1 && requests.length < 1">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
        <Message v-else message_class="is-background-success-90 has-text-dark" title="Information"
                 icon="fas fa-info-circle"
                 message="There are currently no queued events."/>
      </div>
    </div>

    <div class="columns is-multiline" v-if="queue.length > 0">
      <div class="column is-12">
        <span class="title is-5">
          <span class="icon"><i class="fas fa-eye"></i></span>
          State events
        </span>
        <div class="subtitle is-hidden-mobile">
          Events that are changing the play state. Consumed by <code>state:push</code> task.
        </div>
      </div>
      <div class="column is-4 is-6-tablet" v-for="i in queue" :key="`queue-${i.key}`">
        <div class="card" :class="{ 'is-success': i.item.watched }">
          <header class="card-header">
            <p class="card-header-title is-text-overflow pr-1">
              <span class="icon">
                <i class="fas" :class="{'fa-eye-slash': !i.item.watched,'fa-eye': i.item.watched}"></i>&nbsp;
              </span>
              <NuxtLink :to="'/history/'+i.item.id" v-text="makeName(i.item)"/>
            </p>
            <span class="card-header-icon">
              <button class="button is-danger is-small" @click="deleteItem(i.item, 'queue', i.key)">
                <span class="icon"><i class="fas fa-trash"></i></span>
              </button>
            </span>
          </header>
          <div class="card-content">
            <div class="columns is-multiline is-mobile has-text-centered">
              <div class="column is-12 has-text-left" v-if="i.item?.content_title">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('subtitle',i.item.content_title)" v-text="i.item.content_title"/>
                </div>
              </div>
              <div class="column is-12 has-text-left" v-if="i.item?.content_path">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('path',i.item.content_path)" v-text="i.item.content_path"/>
                </div>
              </div>
              <div class="column is-12 has-text-left" v-if="i.item?.progress">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-bars-progress"></i></span>
                  {{ formatDuration(i.item.progress) }}
                </div>
              </div>
            </div>
          </div>
          <div class="card-footer has-text-centered">
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                <span class="has-tooltip"
                      v-tooltip="`${getMoment(ag(i.item.extra, `${i.item.via}.received_at`, i.item.updated_at)).format(TOOLTIP_DATE_FORMAT)}`">
                  {{ getMoment(ag(i.item.extra, `${i.item.via}.received_at`, i.item.updated_at)).fromNow() }}
                </span>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
                <NuxtLink :to="'/backend/'+i.item.via" v-text="i.item.via"/>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
                <span>{{ i.item.event ?? '-' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div class="columns is-multiline" v-if="progress.length > 0">
      <div class="column is-12">
        <span class="title is-5">
          <span class="icon"><i class="fas fa-bars-progress"></i></span>
          Watch progress events
        </span>
        <div class="subtitle is-hidden-mobile">
          Events that are changing the play progress. Consumed by <code>state:progress</code> task.
        </div>
      </div>

      <div class="column is-4 is-6-tablet" v-for="i in progress" :key="`progress-${i.key}`">
        <div class="card" :class="{ 'is-success': i.item.watched }">
          <header class="card-header">
            <p class="card-header-title is-text-overflow pr-1">
              <span class="icon">
                <i class="fas" :class="{'fa-eye-slash': !i.item.watched,'fa-eye': i.item.watched}"></i>&nbsp;
              </span>
              <NuxtLink :to="'/history/'+i.item.id" v-text="makeName(i.item)"/>
            </p>
            <span class="card-header-icon">
              <button class="button is-danger is-small" @click="deleteItem(i.item, 'progress', i.key)">
                <span class="icon"><i class="fas fa-trash"></i></span>
              </button>
            </span>
          </header>
          <div class="card-content">
            <div class="columns is-multiline is-mobile has-text-centered">
              <div class="column is-12 has-text-left" v-if="i.item?.content_title">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('subtitle',i.item.content_title)" v-text="i.item.content_title"/>
                </div>
              </div>
              <div class="column is-12 has-text-left" v-if="i.item?.content_path">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('path',i.item.content_path)" v-text="i.item.content_path"/>
                </div>
              </div>
              <div class="column is-6 has-text-left">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-info-circle"></i></span>
                  is Tainted: {{ i.item?.isTainted ? 'Yes' : 'No' }}
                </div>
              </div>
              <div class="column is-6 has-text-right" v-if="i.item?.progress">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-bars-progress"></i></span>
                  {{ formatDuration(i.item.progress) }}
                </div>
              </div>
            </div>
          </div>
          <div class="card-footer has-text-centered">
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                <span class="has-tooltip"
                      v-tooltip="`${getMoment(ag(i.item.extra, `${i.item.via}.received_at`, i.item.updated_at)).format(TOOLTIP_DATE_FORMAT)}`">
                  {{ getMoment(ag(i.item.extra, `${i.item.via}.received_at`, i.item.updated_at)).fromNow() }}
                </span>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
                <NuxtLink :to="'/backend/'+i.item.via" v-text="i.item.via"/>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
                <span>{{ i.item.event ?? '-' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="columns is-multiline" v-if="requests.length > 0">
      <div class="column is-12">
        <span class="title is-5 is-unselectable">
          <span class="icon"><i class="fas fa-envelope"></i></span>
          Request events
        </span>
        <div class="subtitle is-hidden-mobile">
          Events from backends. Consumed by <code>state:requests</code> task.
        </div>
      </div>

      <div class="column is-4 is-6-tablet" v-for="i in requests" :key="`requests-${i.key}`">
        <div class="card" :class="{ 'is-success': i.item.watched }">
          <header class="card-header">
            <p class="card-header-title is-text-overflow pr-1">
              <span class="icon">
                <i class="fas" :class="{'fa-eye-slash': !i.item.watched, 'fa-eye': i.item.watched}"></i>&nbsp;
              </span>
              <NuxtLink :to="'/history/'+i.item.id" v-text="makeName(i.item)" v-if="i.item.id"/>
              <template v-else>{{ makeName(i.item) }}</template>
            </p>
            <span class="card-header-icon">
              <button class="button is-danger is-small" @click="deleteItem(i.item, 'requests', i.key)">
                <span class="icon"><i class="fas fa-trash"></i></span>
              </button>
            </span>
          </header>
          <div class="card-content">
            <div class="columns is-multiline is-mobile has-text-centered">
              <div class="column is-12 has-text-left" v-if="i.item?.content_title">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('subtitle',i.item.content_title)" v-text="i.item.content_title"/>
                </div>
              </div>
              <div class="column is-12 has-text-left" v-if="i.item?.content_path">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                  <NuxtLink :to="makeSearchLink('path',i.item.content_path)" v-text="i.item.content_path"/>
                </div>
              </div>
              <div class="column is-6 has-text-left">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-info-circle"></i></span>
                  is Tainted: {{ i.item?.isTainted ? 'Yes' : 'No' }}
                </div>
              </div>
              <div class="column is-6 has-text-right" v-if="i.item?.progress">
                <div class="is-text-overflow">
                  <span class="icon"><i class="fas fa-bars-progress"></i></span>
                  {{ formatDuration(i.item.progress) }}
                </div>
              </div>
            </div>
          </div>
          <div class="card-footer has-text-centered">
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                <span class="has-tooltip"
                      v-tooltip="`${getMoment(ag(i.item.extra, `${i.item.via}.received_at`, i.item.updated)).format(TOOLTIP_DATE_FORMAT)}`">
                  {{ getMoment(ag(i.item.extra, `${i.item.via}.received_at`, i.item.updated)).fromNow() }}
                </span>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-server"></i>&nbsp;</span>
                <NuxtLink :to="'/backend/'+i.item.via" v-text="i.item.via"/>
              </div>
            </div>
            <div class="card-footer-item">
              <div class="is-text-overflow">
                <span class="icon"><i class="fas fa-envelope"></i>&nbsp;</span>
                <span>{{ i.item.event ?? '-' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="columns is-multiline">
      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
          <ul>
            <li>
              Events marked with <code>is Tainted: Yes</code>, are interesting but are too chaotic to be useful be used
              to
              determine play state. However, we do use them to update local metadata & play progress.
            </li>
            <li>
              Events marked with <code>is Tainted: No</code>, are events that are used to determine play state.
            </li>
            <li>
              If you are fast enough, you might be able to see the event before it is consumed by the backend. which
              allow
              you to delete it from the queue if you desire.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from '~/utils/request'
import moment from 'moment'
import Message from '~/components/Message'
import {ag, formatDuration, makeName, makeSearchLink, notification, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import {useStorage} from '@vueuse/core'

useHead({title: 'Queue'})

const queue = ref([])
const progress = ref([])
const requests = ref([])
const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  try {
    isLoading.value = true
    queue.value = []
    progress.value = []
    requests.value = []

    const response = await request(`/system/events`)
    let json

    try {
      json = await response.json()
      if (useRoute().name !== 'events') {
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

    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    queue.value = json?.queue
    progress.value = json?.progress
    requests.value = json?.requests
  } catch (e) {
    return notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

const deleteItem = async (item, type, key) => {
  if (!confirm(`Remove '${makeName(item)}' from the '${type}' list?`)) {
    return
  }

  try {
    const response = await request(`/system/events/0`, {
      method: 'DELETE',
      body: JSON.stringify({type: type, id: key})
    })

    if (200 !== response.status) {
      let json

      try {
        json = await response.json()
      } catch (e) {
        json = {
          error: {
            code: response.status,
            message: response.statusText
          }
        }
      }

      notification('error', 'Error', `${json.error.code}: ${json.error.message}`)
      return
    }

    notification('success', 'Success', 'Item successfully deleted from queue.')

    switch (type) {
      case 'queue':
        queue.value = queue.value.filter(i => i.key !== key)
        break
      case 'progress':
        progress.value = progress.value.filter(i => i.key !== key)
        break
      case 'requests':
        requests.value = requests.value.filter(i => i.key !== key)
        break
    }

  } catch (e) {
    return notification('error', 'Error', e.message)
  }
}
const getMoment = (time) => time.toString().length < 13 ? moment.unix(time) : moment(time)

onMounted(async () => loadContent())
</script>
