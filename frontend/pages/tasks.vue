<template>
  <div>
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
          </span>
        </div>
      </div>

      <div id="queued_tasks" class="column is-12" v-if="queued.length > 0">
        <Message message_class="has-background-success-90 has-text-dark" title="Queued Tasks"
                 icon="fas fa-circle-notch fa-spin">
          <p>
            The following tasks
            <template v-for="(task, index) in queued" :key="`queued-${index}`">
              <NuxtLink :to="`#${task}`">
                <span class="tag has-text-dark is-capitalized">{{ task }}</span>
              </NuxtLink>
              <template v-if="queued.length > index+1">,&nbsp;</template>
            </template>
            are queued to be run in background soon.
          </p>
        </Message>
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
            <span class="card-header-icon" v-tooltip="'Enable/Disable Task.'" v-if="task.allow_disable">
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
                <NuxtLink class="has-tooltip" target="_blank"
                          :to="`https://crontab.guru/#${task.timer.replace(/ /g, '_')}`">
                  {{ cronstrue.toString(task.timer) }}
                </NuxtLink>
              </div>
              <div class="column is-6 has-text-left">
                <strong class="is-hidden-mobile">Timer:&nbsp;</strong>
                <span v-if="!task.allow_disabled" class="is-unselectable">
                  {{ task.timer }}
                </span>
                <NuxtLink v-else class="has-tooltip"
                          :to='makeEnvLink(`WS_CRON_${task.name.toUpperCase()}_AT`, task.timer)'>
                  {{ task.timer }}
                </NuxtLink>
              </div>
              <div class="column is-6 has-text-right" v-if="task.args">
                <strong class="is-hidden-mobile">Args:&nbsp;</strong>
                <span v-if="!task.allow_disabled" class="is-unselectable">
                  {{ task.args }}
                </span>
                <NuxtLink v-else class="has-tooltip"
                          :to='makeEnvLink(`WS_CRON_${task.name.toUpperCase()}_ARGS`, task.args)'>
                  {{ task.args }}
                </NuxtLink>
              </div>
              <div class="column is-6 has-text-left">
                <strong class="is-hidden-mobile">Prev Run:&nbsp;</strong>
                <template v-if="task.enabled">
                  <span class="has-tooltip"
                        v-tooltip="`Last run was at: ${moment(task.prev_run).format(TOOLTIP_DATE_FORMAT)}`">
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
                  <span class="has-tooltip"
                        v-tooltip="`Next run will be at: ${moment(task.next_run).format(TOOLTIP_DATE_FORMAT)}`">
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
                  <span class="icon"><i class="fas fa-clock" :class="{ 'fa-spin': task.queued }"></i></span>
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
            <li>For long running tasks like <code>Import</code> and <code>Export</code>, you should queue the task to
              run
              in background. As running them via web console will take longer if you have many backends and/or has large
              libraries.
            </li>
            <li>Use the switch next to the task to enable or disable the task from being run automatically.</li>
            <li>To change when task is scheduled to run, please visit
              <span class="icon"><i class="fas fa-cogs"></i>&nbsp;</span>
              <NuxtLink to="/env" v-text="'Environment variables'"/>
              page. The <code>WS_CRON_(TASK)_*</code> variables are used to control scheduled tasks.
            </li>
            <li>Clicking on the <code>Runs</code> link will take you to external page that will show for you more
              information about the cron timer syntax. While clicking on <code>Timer</code> or <code>Args</code>
              link will take you to edit the related environment variable.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request'
import {awaitElement, makeConsoleCommand, notification, parse_api_response, TOOLTIP_DATE_FORMAT} from '~/utils/index'
import cronstrue from 'cronstrue'
import Message from '~/components/Message'
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
    if (useRoute().name !== 'tasks') {
      return
    }
    tasks.value = json.tasks
    queued.value = json.queued

    dEvent('taskrunner_update', json.status);
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  } finally {
    isLoading.value = false
  }
}

onMounted(async () => await loadContent())

const toggleTask = async task => {
  try {
    const keyName = `WS_CRON_${task.name.toUpperCase()}`

    const oldState = task.enabled

    const update = await request(`/system/env/${keyName}`, {
      method: 'POST',
      body: JSON.stringify({"value": !task.enabled})
    })

    if (200 !== update.status) {
      const json = await parse_api_response(update)
      notification('error', 'Error', `Failed to toggle task '${task.name}' status. ${json.error.message}`)
      tasks.value[tasks.value.findIndex(b => b.name === task.name)].enabled = oldState
      return
    }

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

      if (true === task.queued) {
        awaitElement('#queued_tasks', (_, e) => e.scrollIntoView({
          behavior: 'smooth',
          block: 'start',
          inline: 'nearest'
        }))
      }
    }
  } catch (e) {
    notification('error', 'Error', `Request error. ${e.message}`)
  }
}

const makeEnvLink = (key, val = null) => {
  let search = new URLSearchParams()
  search.set('callback', '/tasks')
  search.set('edit', key)
  if (val) {
    search.set('value', val)
  }
  return `/env?${search.toString()}`
}

const confirmRun = async task => {
  if (!confirm(`Run '${task.name}' via web console now?`)) {
    return
  }
  await navigateTo(makeConsoleCommand(`${task.command} ${task.args || ''}`, true))
}
</script>
