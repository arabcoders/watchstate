<template>
  <UApp>
    <div class="ws-shell min-h-screen">
      <slot />
    </div>
  </UApp>
</template>

<script setup lang="ts">
import { useToast } from '#imports';
import { UApp } from '#components';
import { registerToastController } from '~/utils';

registerToastController(useToast());
</script>
