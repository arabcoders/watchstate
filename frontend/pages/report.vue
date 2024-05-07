<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        System Report
      </span>
      <div class="is-pulled-right" v-if="copyAPI">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" @click="copyContent" v-tooltip="'Copy Report'">
              <span class="icon"><i class="fas fa-copy"></i></span>
            </button>
          </p>
        </div>
      </div>
      <div class="subtitle">This page shows basic information about the system.</div>
    </div>

    <div class="column is-12">
      <Message message_class="is-warning">
        <p>Beware, while we try to make sure no sensitive information is leaked via the report, it's possible that
          something might be missed. Please review the report before posting it. If you notice
          any sensitive information, please report it to the developers. so we can fix it.</p>
      </Message>
    </div>

    <div class="column is-12" v-if="data.length>0">
      <pre style="min-height: 60vh;max-height:80vh; overflow-y: scroll"
      ><code><span v-for="(item, index) in data" :key="index" class="is-block">{{ item }}</span></code></pre>
    </div>
  </div>
</template>

<script setup>
useHead({title: `System Report`})

const data = ref([])

onMounted(async () => {
  const response = await request(`/system/report`);
  data.value = await response.json()
});

const copyAPI = navigator.clipboard
const copyContent = () => navigator.clipboard.writeText(data.value.join('\n'));
</script>
