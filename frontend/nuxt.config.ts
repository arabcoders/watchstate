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
} catch (e) {
}

export default defineNuxtConfig({
    ssr: false,
    devtools: {enabled: true},

    devServer: {
        port: 8081,
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
                {"charset": "utf-8"},
                {"name": "viewport", "content": "width=device-width, initial-scale=1.0, maximum-scale=1.0"},
                {"name": "theme-color", "content": "#000000"}
            ],
        },
        buildAssetsDir: "assets",
        pageTransition: {name: 'page', mode: 'out-in'}
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
    ],

    nitro: {
        output: {
            publicDir: path.join(__dirname, 'exported')
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
    compatibilityDate: "2024-12-28",
})
