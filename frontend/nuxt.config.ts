// https://nuxt.com/docs/api/configuration/nuxt-config

import path from 'path'

export default defineNuxtConfig({
    ssr: false,
    devtools: {enabled: true},

    devServer: {
        port: 8081,
        host: "0.0.0.0",
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
    ],

    nitro: {
        output: {
            publicDir: path.join(__dirname, 'exported')
        }
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
