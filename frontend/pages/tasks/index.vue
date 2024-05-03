<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <div class="p-2">
        <span class="title is-4">Tasks</span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" @click.prevent="loadContent">
                <span class="icon is-small">
                  <i class="fas fa-sync"></i>
                </span>
              </button>
            </p>
          </div>
        </div>
      </div>
    </div>

    <div v-for="task in tasks" :key="task.name" class="column is-6-tablet is-12-mobile">
      <div class="card">
        <header class="card-header">
          <div class="card-header-title is-centered is-word-break">
            <NuxtLink :href="'/tasks/' + task.name">
              {{ task.name }}
            </NuxtLink>
          </div>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-6-mobile" v-if="task.next_run">
              <strong>Next Run:</strong> {{ moment(task.next_run).fromNow() }}
            </div>
            <div class="column is-hidden-mobile" v-if="task.prev_run">
              <strong>Prev Run:</strong> {{ moment(task.prev_run).fromNow() }}
            </div>
          </div>
        </div>
        <footer class="card-footer">
          <div class="card-footer-item">
            <div class="field">
              <input :id="task.name" type="checkbox" class="switch is-success" :checked="task.enabled"
                     @change="toggleTask(task)">
              <label :for="task.name">
                Task is {{ task.enabled ? 'Enabled' : 'Disabled' }}
              </label>
            </div>
          </div>
          <div class="card-footer-item" v-if="task.enabled">
            <button class="button is-info" @click="queueTask(task)" :disabled="task.queued">
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

const loadContent = async () => {
  tasks.value = []
  const response = await request('/tasks')
  const json = await response.json();
  tasks.value = json.tasks
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
