import Toast from 'vue-toastification'

export default defineNuxtPlugin(nuxtApp => nuxtApp.vueApp.use(Toast, {
    transition: "Vue-Toastification__bounce",
    maxToasts: 5,
    closeOnClick: false,
    newestOnTop: true,
    showCloseButtonOnHover: true,
}))
