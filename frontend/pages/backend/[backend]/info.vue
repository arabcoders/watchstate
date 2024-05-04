<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink href="/backends">Backends</NuxtLink>
        -
        <NuxtLink :href="'/backend/' + backend">{{ backend }}</NuxtLink>
        : Info
      </span>

      <div class="is-pulled-right">
        <div class="field is-grouped"></div>
      </div>
    </div>

    <div class="column is-12" v-if="info">
      <code class="box logs-container">
        {{ JSON.stringify(info, null, 2) }}
      </code>
    </div>
  </div>
</template>

<style scoped>
.logs-container {
  min-height: 40vh;
  overflow-y: auto;
  white-space: pre;
}
</style>

<script setup>
const backend = useRoute().params.backend
const info = ref({})

const loadContent = async () => {
  const response = await request(`/backend/${backend}/info`)
  const json = await response.json()
  info.value = json.data
}

onMounted(() => loadContent())

</script>
