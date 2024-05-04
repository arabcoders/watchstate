<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <div class="p-2">
        <span class="title is-4">Tasks</span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" @click.prevent="loadContent(true)">
                <span class="icon is-small">
                  <i class="fas fa-sync"></i>
                </span>
              </button>
            </p>
          </div>
        </div>
      </div>
      <div class="is-hidden-mobile" v-if="queued.length > 0">
        <p>
          <span>The following tasks <code>{{ queued.join(', ') }}</code> are queued to be run soon.</span>
        </p>
      </div>
    </div>

    <div v-for="task in tasks" :key="task.name" class="column is-6-tablet is-12-mobile">
      <div class="card">
        <header class="card-header">
          <div class="is-capitalized card-header-title is-centered has-tooltip"
               v-tooltip="'The command: ' + task.command">
            {{ task.name }}
          </div>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-12 has-text-left" v-if="task.description">
              {{ task.description }}
            </div>
            <div class="column is-6 has-text-left">
              <strong class="is-hidden-mobile">Timer:</strong>
              <a target="_blank" :href="`https://crontab.guru/#${task.timer.replace(/ /g, '_')}`"
                 rel="noreferrer,nofollow,noopener">
                {{ task.timer }}
              </a>
            </div>
            <div class="column is-6 has-text-right" v-if="task.args">
              <strong class="is-hidden-mobile">Args:</strong> <code>{{ task.args }}</code>
            </div>
            <div class="column is-6 has-text-left">
              <strong class="is-hidden-mobile">Prev Run:</strong>
              {{ task.prev_run ? moment(task.prev_run).fromNow() : '???' }}
            </div>
            <div class="column is-6 has-text-right">
              <strong class="is-hidden-mobile">Next Run:</strong>
              {{ task.next_run ? moment(task.next_run).fromNow() : 'Never' }}
            </div>
          </div>
        </div>
        <footer class="card-footer">
          <div class="card-footer-item">
            <div class="field">
              <input :id="task.name" type="checkbox" class="switch is-success" :checked="task.enabled"
                     @change="toggleTask(task)">
              <label :for="task.name">
                <span class="is-hidden-mobile">Task is&nbsp;</span> {{ task.enabled ? 'Enabled' : 'Disabled' }}
              </label>
            </div>
          </div>
          <div class="card-footer-item">
            <button class="button is-info" @click="queueTask(task)" :disabled="task.queued || !task.enabled">
              <span class="icon-text">
                <span class="icon"><i class="fas fa-trash"></i></span>
                <span v-if="!task.queued">Run now</span>
                <span v-else>Queued</span>
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
import moment from "moment";
import request from "~/utils/request.js";

useHead({title: 'tasks'})

const tasks = ref([])
const queued = ref([])

const loadContent = async (clear = false) => {
  if (clear) {
    tasks.value = []
  }
  const response = await request('/tasks')
  const json = await response.json();
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
  const json = await response.json();

  tasks.value[tasks.value.findIndex(b => b.name === task.name)] = json.task
}

const queueTask = async (task) => {
  if (!confirm(`Are you sure you want to queue the task ${task.name}?`)) {
    return
  }

  const response = await request(`/tasks/${task.name}/queue`, {method: 'POST'})
  if (response.ok) {
    await loadContent();
  }
}

</script>
