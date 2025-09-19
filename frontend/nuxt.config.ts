// https://nuxt.com/docs/api/configuration/nuxt-config

import path from 'path'

let extraNitro = {}
try {
    const API_URL = import.meta.env.NUXT_API_URL;
    if (API_URL) {
        extraNitro = {
            devProxy: {
                '/v1/api/': {
                    target: API_URL + '/v1/api/',
                    changeOrigin: true
                },
                '/guides/': {
                    target: API_URL + '/guides/',
                    changeOrigin: true
                },
            }
        }
    }
} catch { }

export default defineNuxtConfig({
    ssr: false,
    devtools: { enabled: true },

    devServer: {
        port: 8082,
        host: "0.0.0.0",
    },
    runtimeConfig: {
        public: {
            domain: '/',
            version: '1.0.0',
        }
    },
    app: {
        head: {
            "meta": [
                { "charset": "utf-8" },
                { "name": "viewport", "content": "width=device-width, initial-scale=1.0, maximum-scale=1.0" },
                { "name": "theme-color", "content": "#000000" }
            ],
        },
        buildAssetsDir: "assets",
        pageTransition: { name: 'page', mode: 'out-in' }
    },

    router: {
        options: {
            linkActiveClass: "is-selected",
        }
    },

    modules: [
        '@vueuse/nuxt',
        'floating-vue/nuxt',
        '@pinia/nuxt',
        process.env.NODE_ENV === 'development' ? '@nuxt/eslint' : '',
    ],

    nitro: {
        output: {
            publicDir: path.join(__dirname, 'production' === process.env.NODE_ENV ? 'exported' : 'dist')
        },
        ...extraNitro,
    },

    build: {
        transpile: ['vue-toastification'],
    },

    css: [
        'vue-toastification/dist/index.css'
    ],

    telemetry: false,
    compatibilityDate: "2025-08-02",
    experimental: {
        checkOutdatedBuildInterval: 1000 * 60 * 10,
    }
})
