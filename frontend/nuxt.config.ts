import { defineNuxtConfig } from 'nuxt/config';

let extraNitro = {};
try {
  const API_URL = import.meta.env.NUXT_API_URL;
  if (API_URL) {
    extraNitro = {
      devProxy: {
        '/v1/api/': {
          target: API_URL + '/v1/api/',
          changeOrigin: true,
        },
        '/guides/': {
          target: API_URL + '/guides/',
          changeOrigin: true,
        },
      },
    };
  }
} catch {}

const isProd = 'production' === process.env.NODE_ENV;
export default defineNuxtConfig({
  ssr: false,
  sourcemap: isProd ? false : true,
  devtools: { enabled: true },
  devServer: {
    port: 8082,
    host: '0.0.0.0',
  },
  runtimeConfig: {
    public: {
      domain: '/',
      version: '1.0.0',
    },
  },
  colorMode: {
    preference: 'dark',
    fallback: 'dark',
    classSuffix: '',
  },
  icon: {
    provider: 'none',
    fallbackToApi: false,
    clientBundle: {
      scan: {
        globInclude: ['app/**/*.{vue,ts,js}', 'node_modules/@nuxt/ui/dist/shared/ui*.mjs'],
        globExclude: ['dist', 'build', 'coverage', 'test', 'tests', '.*'],
      },
    },
  },
  app: {
    head: {
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1.0, maximum-scale=1.0' },
        { name: 'theme-color', content: '#000000' },
      ],
    },
    buildAssetsDir: 'assets',
    pageTransition: { name: 'page', mode: 'out-in' },
  },
  router: {
    options: {
      linkActiveClass: 'is-selected',
    },
  },
  modules: ['@nuxt/ui', '@vueuse/nuxt', '@pinia/nuxt', '@nuxt/eslint'],
  nitro: {
    sourceMap: isProd ? false : true,
    output: {
      publicDir: isProd ? __dirname + '/exported' : __dirname + '/dist',
    },
    ...extraNitro,
  },
  css: ['~/assets/css/tailwind.css'],
  telemetry: false,
  compatibilityDate: '2025-08-02',
  experimental: {
    payloadExtraction: 'client',
    checkOutdatedBuildInterval: 1000 * 60 * 10,
  },
  vite: {
    optimizeDeps: {
      include: [
        'moment',
        '@microsoft/fetch-event-source',
        '@xterm/addon-fit',
        '@xterm/xterm',
        'vuedraggable', // CJS
        '@vue/devtools-core',
        '@vue/devtools-kit',
        'hls.js',
        'assjs',
        'marked',
        'marked-base-url',
        'marked-alert',
        'marked-gfm-heading-id',
        '@noble/hashes/hmac.js',
        '@noble/hashes/sha2.js',
        'cronstrue',
      ],
    },
    server: {
      allowedHosts: true,
    },
    build: {
      chunkSizeWarningLimit: 2000,
      rollupOptions: {
        onwarn(warning, warn) {
          if ('SOURCEMAP_BROKEN' === warning.code) {
            return;
          }

          warn(warning);
        },
      },
    },
  },
});
