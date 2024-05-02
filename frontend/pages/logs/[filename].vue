<template>
  <div class="columns is-multiline">
    <div class="column is-12">
      <span class="title is-4">View Log file</span>

      <div class="is-pulled-right" v-if="!error">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-info" v-tooltip="'Watch log'">
              <span class="icon is-small">
                <i class="fas fa-stream"></i>
              </span>
            </button>
          </p>
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

    <div class="column is-12">
      <code class="box logs-container" v-if="!error">
        <div v-for="(item, index) in data" :key="'log_line-'+index">
          {{ item }}
        </div>
      </code>
      <template v-else>
        <Message title="Request Error" message_class="is-danger" :message="error"/>
      </template>
    </div>

  </div>
</template>

<style scoped>
.logs-container {
  min-height: 60vh;
  max-height: 70vh;
  overflow-y: auto;
  white-space: pre;
}
</style>

<script setup>

import Message from "~/components/Message.vue";

const filename = useRoute().params.filename
const data = ref([])
const error = ref(null)

const loadContent = async () => {
  try {
    const response = await request(`/logs/${filename}`)
    if (response.ok) {
      const text = await response.text()
      data.value = text.split('\n')
    } else {
      error.value = `${response.status}: ${response.statusText}`
    }
  } catch (e) {
    error.value = e
  }
}

onMounted(() => {
  loadContent()
})

</script>
