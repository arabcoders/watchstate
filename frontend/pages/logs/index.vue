<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">Logs</span>
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
          This page contains all the stored log files. The naming convention is <code>type.YYYYMMDD.log</code>.
        </span>
      </div>
    </div>

    <div class="column is-4-tablet" v-for="(item, index) in logs" :key="'log-'+index">
      <div class="card">
        <header class="card-header">
          <p class="card-header-title is-text-overflow pr-1">
            <NuxtLink :to="'/logs/'+item.filename">{{ item.filename ?? item.date }}</NuxtLink>
          </p>
          <span class="card-header-icon">
            <span class="icon" v-if="'access' === item.type"><i class="fas fa-key"></i></span>
            <span class="icon" v-if="'task' === item.type"><i class="fas fa-tasks"></i></span>
            <span class="icon" v-if="'app' === item.type"><i class="fas fa-bugs"></i></span>
            <span class="icon" v-if="'webhook' === item.type"><i class="fas fa-book"></i></span>
          </span>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-6-mobile is-pre">
              <span v-tooltip="'Last Update'" class="has-tooltip">
                {{ moment(item.modified).fromNow() }}
              </span>
            </div>
            <div class="column is-6-mobile">
              {{ humanFileSize(item.size) }}
            </div>
            <div class="column is-6-mobile">
              <span v-tooltip="'Log Kind'" class="has-tooltip">
                {{ item.type }}
              </span>
            </div>
          </div>
        </div>
        <div class="card-footer">
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from "~/utils/request.js";
import moment from "moment";
import {humanFileSize} from "~/utils/index.js";

useHead({title: 'Logs'})

const logs = ref([])

const loadContent = async () => {
  logs.value = []
  const response = await request('/logs')
  let data = await response.json();

  data.sort((a, b) => new Date(b.modified) - new Date(a.modified));

  logs.value = data;
}

onMounted(() => loadContent())
</script>
