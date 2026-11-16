<template>
  <UAlert color="info" variant="soft" icon="i-lucide-info" title="WebUI updated">
    <template #description>
      <span>
        <UButton
          type="button"
          color="neutral"
          variant="outline"
          size="xs"
          icon="i-lucide-refresh-cw"
          @click="reloadPage"
        >
          Reload now
        </UButton>
      </span>
    </template>
  </UAlert>
</template>

<script setup lang="ts">
import { UAlert, UButton } from '#components';

const reloadPage = () => window.location.reload();
</script>
