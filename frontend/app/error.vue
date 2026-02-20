<template>
  <NuxtLayout>
    <div class="columns is-multiline">
      <div class="column is-12">
        <h1 class="title is-4">
          {{ props.error.statusCode
          }}<span v-if="props.error.statusMessage"> - {{ props.error.statusMessage }}</span>
        </h1>
      </div>
    </div>
    <div class="column is-12" v-if="props.error.message">
      <div class="notification has-background-warning-90 has-text-dark">
        <p>{{ props.error.message }}</p>
      </div>
    </div>

    <div class="column is-12" v-if="props.error.stack">
      <h2 class="title is-5 is-clickable" @click="showStacks = !showStacks">
        <span class="icon-text">
          <span class="icon">
            <i v-if="showStacks" class="fas fa-arrow-up"></i>
            <i v-else class="fas fa-arrow-down"></i>
          </span>
          <span>Stack trace</span>
        </span>
      </h2>

      <pre v-if="showStacks"><code>{{ props.error.stack }}</code></pre>
    </div>

    <div class="column is-12">
      <h2 class="title is-5">
        <NuxtLink to="/">
          <span class="icon-text">
            <span class="icon"><i class="fas fa-home"></i></span>
            <span>Back to Home</span>
          </span>
        </NuxtLink>
      </h2>
    </div>
  </NuxtLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{
  error: {
    statusCode?: number;
    statusMessage?: string;
    message?: string;
    stack?: string;
  };
}>();
const showStacks = ref(false);
</script>
