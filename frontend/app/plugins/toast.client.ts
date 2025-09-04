import Toast, {type PluginOptions} from 'vue-toastification'
import {defineNuxtPlugin} from '#app'

export default defineNuxtPlugin(nuxtApp => {
    const options: PluginOptions = {
        transition: 'Vue-Toastification__bounce',
        maxToasts: 5,
        closeOnClick: false,
        newestOnTop: true,
        showCloseButtonOnHover: true,
    }
    nuxtApp.vueApp.use(Toast, options)
})
