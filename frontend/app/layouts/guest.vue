<template>
  <UApp>
    <div class="ws-shell min-h-screen">
      <NuxtPage />
    </div>
  </UApp>
</template>

<script setup lang="ts">
import { registerToastController } from '~/utils';

registerToastController(useToast());
</script>
