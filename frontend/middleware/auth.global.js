import { storeToRefs } from 'pinia'
import { useAuthStore } from '~/store/auth'
import { useStorage } from '@vueuse/core'

let next_check = 0

export default defineNuxtRouteMiddleware(async to => {
    if (to.fullPath.startsWith('/auth') || to.fullPath.startsWith('/v1/api')) {
        return
    }

    const { authenticated } = storeToRefs(useAuthStore())
    const token = useStorage('token', null)

    if (token.value) {
        if (Date.now() > next_check) {
            console.debug('Validating user token...')
            const { validate } = useAuthStore()
            if (!await validate(token.value)) {
                token.value = null
                abortNavigation()
                console.error('Token is invalid, redirecting to login page...')
                return navigateTo('/auth')
            }
            console.debug('Token is valid.')
            next_check = Date.now() + 1000 * 60 * 5
        }

        authenticated.value = true
    }

    if (!token.value && to?.name !== 'auth') {
        abortNavigation()
        return navigateTo('/auth')
    }
})
