import { defineNuxtPlugin } from '#app';

export default defineNuxtPlugin({
  name: 'chunk-recovery',
  // Register before Nuxt's router loads the initial route.
  order: -25,
  setup(nuxtApp) {
    const { baseURL, buildId } = nuxtApp.$config.app;
    const key = `watchstate:chunk-reload:${baseURL}`;

    nuxtApp.hook('app:chunkError', () => {
      if (false === nuxtApp.isHydrating) {
        return;
      }

      try {
        if (buildId === window.sessionStorage.getItem(key)) {
          return;
        }

        window.sessionStorage.setItem(key, buildId);
      } catch {
        // Skip the reload when the tab can't remember the attempt.
        return;
      }

      window.location.reload();
    });
  },
});
