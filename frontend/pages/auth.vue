<template>
    <div>
        <div class="hero is-dark is-fullheight">
            <div class="hero-body">
                <div class="container" style="background-color: unset !important;">
                    <div class="columns is-centered">
                        <div class="column is-6-tablet is-6-desktop is-4-widescreen">
                            <div class="box" v-if="error">
                                <span class="icon"><i class="fa fa-info" /></span>
                                <span class="has-text-danger">{{ error }}</span>
                            </div>

                            <form method="post" @submit.prevent="formValidate()" class="box">
                                <div class="field">
                                    <label for="user-id" class="label">
                                        {{ signup ? 'Create an account' : 'Login' }}
                                    </label>
                                    <div class="control has-icons-left">
                                        <input id="user-id" type="text" placeholder="Username" class="input" required
                                            autocomplete="username" name="username" v-model="user.username" autofocus>
                                        <span class="icon is-left"><i class="fa fa-user" /></span>
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="user-password" class="label">Password</label>
                                    <div class="field-body">
                                        <div class="field">
                                            <div class="field has-addons">
                                                <div class="control is-expanded has-icons-left">
                                                    <input class="input" id="user-password" v-model="user.password"
                                                        required placeholder="Password"
                                                        :type="false === form_expose ? 'password' : 'text'">
                                                    <span class="icon is-left"><i class="fa fa-lock" /></span>
                                                </div>
                                                <div class="control">
                                                    <button type="button" class="button is-primary"
                                                        @click="form_expose = !form_expose">
                                                        <span class="icon" v-if="!form_expose"><i
                                                                class="fas fa-eye" /></span>
                                                        <span class="icon" v-else><i class="fas fa-eye-slash" /></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="field">
                                    <button type="submit" class="button is-fullwidth is-dark is-light">
                                        <span class="icon"><i class="fa fa-sign-in" /></span>
                                        <span>
                                            {{ signup ? 'Create an account' : 'Login' }}
                                        </span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useStorage } from '@vueuse/core'
import { useAuthStore } from '~/store/auth'

definePageMeta({ name: "auth", layout: 'guest' })
useHead({ title: 'Login / Signup' })

const error = ref('')
const form_expose = ref(false)
const signup = ref(false)
const token = useStorage('api_token', '')

const auth = useAuthStore()

const user = ref({ username: '', password: '' })

onMounted(async () => {
    const req = await request('/system/auth/has_user')
    if (204 === req.status) {
        signup.value = true
    }
});

const formValidate = async () => {
    if (user.value.username.length < 1) {
        error.value = 'Username is required.'
        return false
    }

    if (user.value.password.length < 1) {
        error.value = 'Password is required.'
        return false
    }

    if (false === /^[a-z_0-9]+$/.test(user.value.username)) {
        error.value = 'Username can only contain lowercase letters, numbers and underscores.'
        return false
    }


    if (signup.value) {
        return await do_signup()
    }

    return await do_login()
}

const do_login = async () => {
    try {
        await auth.login(user.value.username, user.value.password)
        if (auth.authenticated) {
            notification('success', 'Success', 'Login successful. Redirecting...')
            return await navigateTo('/')
        }
        throw new Error('Login failed. Please check your username and password.')
    }
    catch (e) {
        console.log(e)
        error.value = e.message
        return false
    }
}

const do_signup = async () => {
    try {
        const state = await auth.signup(user.value.username, user.value.password)
        if (false === state) {
            error.value = 'Failed to create an account.'
            return false
        }
        return await do_login()
    }
    catch (e) {
        console.log(e)
        error.value = e.message
        return false
    }
}

</script>
