<template>
  <div class="columns is-multiline">
    <div class="column is-12 is-clearfix">
      <span class="title is-4">
        <NuxtLink href="/history">History</NuxtLink>
        : {{ id }}
      </span>
      <div class="is-pulled-right">
        <div class="field is-grouped">
          <p class="control">
            <button class="button is-primary" @click="loadContent(id)">
              <span class="icon">
                <i class="fas fa-sync"></i>
              </span>
            </button>
          </p>
        </div>
      </div>
      <div class="subtitle">
        This page still not done, and will be updated at later stages.
      </div>
    </div>

    <div class="column is-12">
      <pre><code>{{ data }}</code></pre>
    </div>

  </div>
</template>

<script setup>
import request from '~/utils/request.js'

const id = useRoute().params.id

useHead({title: `History : ${id}`})

const data = ref();

const loadContent = async (id) => {
  const response = await request(`/history/${id}`)
  data.value = await response.json();
}

onMounted(async () => loadContent(id))
</script>
