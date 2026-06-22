<template>
  <UApp>
    <div class="min-h-screen bg-default text-default">
      <div
        class="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-4 py-4 sm:px-6 sm:py-6 lg:px-8"
      >
        <header class="mb-6 flex items-center justify-between border-b border-default pb-4">
          <NuxtLink
            to="/"
            class="inline-flex items-center gap-2 text-sm font-semibold text-highlighted"
          >
            <UIcon name="i-lucide-house" class="size-4" />
            <span>Home</span>
          </NuxtLink>

          <span class="text-xs tracking-[0.22em] text-toned uppercase">Error</span>
        </header>

        <main class="flex min-w-0 flex-1 flex-col">
          <slot />
        </main>
      </div>
    </div>
  </UApp>
</template>
