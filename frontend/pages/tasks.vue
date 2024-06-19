<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix is-unselectable">
      <span class="title is-4">
        <span class="icon"><i class="fas fa-tasks"></i></span>
        Tasks
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-info" @click="loadContent()" :disabled="isLoading"
                    :class="{'is-loading':isLoading}">
              <span class="icon"><i class="fas fa-sync"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="is-hidden-mobile">
        <span class="subtitle">
          This page contains all the tasks that are currently configured.
          <template v-if="queued.length > 0">
            <p>The following tasks <code>{{ queued.join(', ') }}</code> are queued to be run in background soon.</p>
          </template>
        </span>
      </div>
    </div>
    <div class="column is-12" v-if="isLoading">
      <Message message_class="has-background-info-90 has-text-dark" title="Loading"
               icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>
    </div>

    <div v-for="task in tasks" :key="task.name" class="column is-6-tablet is-12-mobile">
      <div class="card" :class="{ 'is-gray' : !task.enabled, 'is-success': task.enabled }">
        <header class="card-header">
          <div class="is-capitalized card-header-title">
            {{ task.name }}
          </div>
          <span class="card-header-icon" v-tooltip="'Enable/Disable Task.'">
            <input :id="task.name" type="checkbox" class="switch is-success" :checked="task.enabled"
                   @change="toggleTask(task)">
            <label :for="task.name"></label>
          </span>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-12 has-text-left" v-if="task.description">
              {{ task.description }}
            </div>
            <div class="column is-12 has-text-left">
              <strong class="is-hidden-mobile">Runs: </strong>
              <NuxtLink class="has-tooltip" :to="`/env?edit=WS_CRON_${task.name.toUpperCase()}_AT`">
                {{ cronstrue.toString(task.timer) }}
              </NuxtLink>
            </div>
            <div class="column is-6 has-text-left">
              <strong class="is-hidden-mobile">Timer:&nbsp;</strong>
              <NuxtLink class="has-tooltip" target="_blank"
                        :to="`https://crontab.guru/#${task.timer.replace(/ /g, '_')}`">
                {{ task.timer }}
              </NuxtLink>
            </div>
            <div class="column is-6 has-text-right" v-if="task.args">
              <strong class="is-hidden-mobile">Args:</strong> <code>{{ task.args }}</code>
            </div>
            <div class="column is-6 has-text-left">
              <strong class="is-hidden-mobile">Prev Run:&nbsp;</strong>
              <template v-if="task.enabled">
                <span class="has-tooltip" v-tooltip="`Prev Run: ${moment(task.prev_run).format(TOOLTIP_DATE_FORMAT)}`">
                  {{ task.prev_run ? moment(task.prev_run).fromNow() : '???' }}
                </span>
              </template>
              <template v-else>
                <span class="tag is-danger">Disabled</span>
              </template>
            </div>
            <div class="column is-6 has-text-right">
              <strong class="is-hidden-mobile">Next Run:&nbsp;</strong>
              <template v-if="task.enabled">
                <span class="has-tooltip" v-tooltip="`Next Run: ${moment(task.next_run).format(TOOLTIP_DATE_FORMAT)}`">
                  {{ task.next_run ? moment(task.next_run).fromNow() : 'Never' }}
                </span>
              </template>
              <template v-else>
                <span class="tag is-danger">Disabled</span>
              </template>
            </div>
          </div>
        </div>
        <footer class="card-footer">
          <div class="card-footer-item">
            <button class="button is-info" @click="queueTask(task)"
                    :class="{'is-danger':task.queued,'is-info':!task.queued}">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-clock"></i></span>
                <span>
                  <template v-if="!task.queued">Queue Task</template>
                  <template v-else>Remove from queue</template>
                </span>
              </span>
            </button>
          </div>
          <div class="card-footer-item">
            <button class="button is-warning" @click="confirmRun(task)">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-terminal"></i></span>
                <span class="is-hidden-mobile">Run via console</span>
                <span class="is-hidden-tablet">Run now</span>
              </span>
            </button>
          </div>
        </footer>
      </div>
    </div>

    <div class="column is-12">
      <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
               @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fas fa-info-circle">
        <ul>
          <li>For long running tasks like <code>Import</code> and <code>Export</code>, you should queue the task to run
            in background. As running them via web console will take longer if you have many backends and/or has large
            libraries.
          </li>
          <li>Use the switch next to the task to enable or disable the task from being run automatically.</li>
          <li>To change when task is scheduled to run, please visit
            <NuxtLink to="/env" v-text="'Environment variables'"/>
            page. The <code>WS_CRON_(TASK)_*</code> variables are used to control scheduled tasks.
          </li>
          <li>Clicking on the Runs link will take you directly to the environment variable for the task. While on the
            timer link will take you to external page that will show for you more information about the cron timer
            syntax.
          </li>
        </ul>
      </Message>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request'
import {notification, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import cronstrue from 'cronstrue'
import Message from '~/components/Message.vue'
import {useStorage} from '@vueuse/core'

useHead({title: 'Tasks'})

const tasks = ref([])
const queued = ref([])
const isLoading = ref(false)
const show_page_tips = useStorage('show_page_tips', true)

const loadContent = async () => {
  isLoading.value = true
  tasks.value = []
  try {
    const response = await request('/tasks')
    const json = await response.json()
    tasks.value = json.tasks
    queued.value = json.queued
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => loadContent())

const toggleTask = async task => {
  try {
    const keyName = `WS_CRON_${task.name.toUpperCase()}`
    await request(`/system/env/${keyName}`, {
      method: 'POST',
      body: JSON.stringify({"value": !task.enabled})
    })

    const response = await request(`/tasks/${task.name}`)
    tasks.value[tasks.value.findIndex(b => b.name === task.name)] = await response.json()
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const queueTask = async task => {
  const is_queued = Boolean(task.queued)
  const message = is_queued ? `Remove '${task.name}' from the queue?` : `Queue '${task.name}' to run in background?`
  if (!confirm(message)) {
    return
  }

  try {
    const response = await request(`/tasks/${task.name}/queue`, {method: is_queued ? 'DELETE' : 'POST'})
    if (response.ok) {
      notification('success', 'Success', `Task '${task.name}' has been ${is_queued ? 'removed from the queue' : 'queued'}.`)
      task.queued = !is_queued
      if (task.queued) {
        queued.value.push(task.name)
      } else {
        queued.value = queued.value.filter(t => t !== task.name)
      }
    }
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const confirmRun = async task => {
  if (!confirm(`Run '${task.name}' via web console now?`)) {
    return
  }
  await navigateTo({path: '/console', query: {task: task.name}})
}
</script>
