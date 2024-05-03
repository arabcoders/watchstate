<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <div class="p-2">
        <span class="title is-4">Logs</span>
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

    <div class="column is-4-tablet" v-for="(item, index) in logs" :key="'log-'+index">
      <div class="card">
        <header class="card-header">
          <div class="card-header-title is-centered is-ellipsis">
            <NuxtLink :href="'/logs/'+item.filename">{{ item.filename ?? item.date }}</NuxtLink>
          </div>
        </header>
        <div class="card-content">
          <div class="columns is-multiline is-mobile has-text-centered">
            <div class="column is-6-mobile">
              <span v-tooltip="'Last Update'" class="has-tooltip">{{ moment(item.modified).fromNow() }}</span>
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
  const json = await response.json();
  let data = json.logs;
  data.sort((a, b) => new Date(b.modified) - new Date(a.modified));

  logs.value = data;
}

onMounted(() => loadContent())
</script>
