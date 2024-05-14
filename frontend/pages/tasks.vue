<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">Tasks</span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-info" @click.prevent="loadContent(true)">
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
              <strong class="is-hidden-mobile">Runs:</strong> {{ cronstrue.toString(task.timer) }}
            </div>
            <div class="column is-6 has-text-left">
              <strong class="is-hidden-mobile">Timer:&nbsp;</strong>
              <a target="_blank" :href="`https://crontab.guru/#${task.timer.replace(/ /g, '_')}`"
                 rel="noreferrer,nofollow,noopener">
                {{ task.timer }}
              </a>
            </div>
            <div class="column is-6 has-text-right" v-if="task.args">
              <strong class="is-hidden-mobile">Args:</strong> <code>{{ task.args }}</code>
            </div>
            <div class="column is-6 has-text-left">
              <strong class="is-hidden-mobile">Prev Run:&nbsp;</strong>
              <template v-if="task.enabled">
                {{ task.prev_run ? moment(task.prev_run).fromNow() : '???' }}
              </template>
              <template v-else>
                <span class="tag is-danger">Disabled</span>
              </template>
            </div>
            <div class="column is-6 has-text-right">
              <strong class="is-hidden-mobile">Next Run:&nbsp;</strong>
              <template v-if="task.enabled">
                {{ task.next_run ? moment(task.next_run).fromNow() : 'Never' }}
              </template>
              <template v-else>
                <span class="tag is-danger">Disabled</span>
              </template>
            </div>
          </div>
        </div>
        <footer class="card-footer">
          <div class="card-footer-item">
            <button class="button is-info" @click="queueTask(task)" :disabled="task.queued">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-clock"></i></span>
                <span>
                  <template v-if="!task.queued">Queue Task</template>
                  <template v-else>Queued</template>
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
  </div>
</template>

<script setup>
import 'assets/css/bulma-switch.css'
import moment from 'moment'
import request from '~/utils/request.js'
import {notification} from "~/utils/index.js";
import cronstrue from 'cronstrue'

useHead({title: 'Tasks'})

const tasks = ref([])
const queued = ref([])

const loadContent = async (clear = false) => {
  if (clear) {
    tasks.value = []
  }
  const response = await request('/tasks')
  const json = await response.json()
  tasks.value = json.tasks
  queued.value = json.queued
}

onMounted(() => loadContent())

const toggleTask = async (task) => {
  const keyName = `WS_CRON_${task.name.toUpperCase()}`
  await request(`/system/env/${keyName}`, {
    method: 'POST',
    body: JSON.stringify({"value": !task.enabled})
  });

  const response = await request(`/tasks/${task.name}`)
  tasks.value[tasks.value.findIndex(b => b.name === task.name)] = await response.json()
}

const queueTask = async (task) => {
  if (!confirm(`Queue '${task.name}' to run in background?`)) {
    return
  }

  const response = await request(`/tasks/${task.name}/queue`, {method: 'POST'})
  if (response.ok) {
    notification('success', 'Success', `Task ${task.name} has been queued.`)
    await loadContent()
  }
}

const confirmRun = async (task) => {
  if (!confirm(`Are you sure you want to run '${task.name}' via web console now?`)) {
    return
  }
  await navigateTo({path: '/console', query: {task: task.name, keep: 1}})
}
</script>
