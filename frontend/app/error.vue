<template>
  <NuxtLayout name="error-layout">
    <div class="flex min-h-full flex-1 items-start justify-center py-4 sm:py-8">
      <div class="ytp-card p-0 w-full">
        <div class="space-y-6 px-4 py-5 sm:px-6 sm:py-6 lg:px-7">
          <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 space-y-2">
              <div class="flex flex-wrap items-center gap-2 text-sm text-toned">
                <UIcon name="i-lucide-triangle-alert" class="size-4 text-warning" />
                <span>Application error</span>
              </div>

              <h1 class="text-2xl font-semibold text-highlighted sm:text-3xl">
                {{ error.status || 'Unknown Error' }}
                <span v-if="error.statusText"> - {{ error.statusText }}</span>
              </h1>

              <p class="max-w-3xl text-sm leading-6 text-toned">
                An unexpected error interrupted this view. You can go back home or retry the current
                route.
              </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
              <UButton color="neutral" variant="outline" size="sm" icon="i-lucide-house" to="/">
                Back to Home
              </UButton>

              <UButton color="primary" size="sm" icon="i-lucide-rotate-cw" @click="handleRetry">
                Retry
              </UButton>
            </div>
          </div>

          <UAlert
            v-if="error.message"
            color="warning"
            variant="soft"
            icon="i-lucide-circle-alert"
            title="Details"
            :description="error.message"
          />

          <section v-if="error.stack" class="space-y-3 border-t border-default pt-5">
            <button
              type="button"
              class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted transition hover:text-primary"
              @click="showStacks = !showStacks"
            >
              <UIcon
                :name="showStacks ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
                class="size-4"
              />
              <span>Stack trace</span>
            </button>

            <div
              v-if="showStacks"
              class="overflow-x-auto rounded-lg border border-default bg-elevated/60"
            >
              <pre
                class="min-w-0 p-4 text-xs leading-6 whitespace-pre-wrap text-default"
              ><code>{{ error.stack }}</code></pre>
            </div>
          </section>
        </div>
      </div>
    </div>
  </NuxtLayout>
</template>

<script setup lang="ts">
import type { NuxtError } from '#app';

const props = defineProps<{
  error: NuxtError;
}>();

const showStacks = ref(false);

const handleRetry = async (): Promise<void> => {
  await clearError({ redirect: useRoute().fullPath });
};

onMounted(() => console.error(props.error));
</script>
