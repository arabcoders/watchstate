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

              <div class="box" v-if="forgot_password">
                <div class="content">
                  <h4>
                    <span class="icon"><i class="fa fa-lock" :class="{ 'fa-fade': polling_timer }" /></span>
                    <span>How to Reset system login</span>
                  </h4>
                  <p>
                    To reset your system password, you need to run the following command from your docker host
                  </p>
                  <div class="is-relative">
                    <code class="text-container is-block p-4 is-terminal is-pre-wrap">
                        {{ reset_cmd }}
                      </code>
                    <button class="button m-4 is-small" title="Copy to clipboard" @click="() => copyText(reset_cmd)"
                      style="position: absolute; top:0; right:0;">
                      <span class="icon"><i class="fas fa-copy" /></span>
                    </button>
                  </div>
                  <p class="has-text-danger">
                    <ul>
                      <li>Once you have run the command, return to this page and to create a new user account.</li>
                      <li>You can also reset the password by removing the <code>.env</code> file found inside the config directory.</li>
                    </ul>
                  </p>
                  <p class="has-text-info" v-if="polling_timer">
                    <span class="icon"><i class="fas fa-spinner fa-spin" /></span>
                    <span>Checking for reset completion...</span>
                  </p>
                </div>
                <div class="field is-grouped">
                  <div class="control is-expanded">
                    <button class="button is-fullwidth is-info is-dark" @click="forgot_password = false">
                      <span class="icon"><i class="fa fa-arrow-left" /></span>
                      <span>Back to login</span>
                    </button>
                  </div>
                </div>
              </div>

              <form method="post" @submit.prevent="form_validate()" class="box" v-if="!forgot_password">
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
                          <input class="input" id="user-password" v-model="user.password" required
                            placeholder="Password" :type="false === form_expose ? 'password' : 'text'"
                            autocomplete="current-password">
                          <span class="icon is-left"><i class="fa fa-lock" /></span>
                        </div>
                        <div class="control">
                          <button type="button" class="button is-secondary" @click="form_expose = !form_expose">
                            <span class="icon" v-if="!form_expose"><i class="fas fa-eye" /></span>
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
                      {{ signup ? 'Signup' : 'Login' }}
                    </span>
                  </button>
                </div>
                <div class="field" v-if="!signup">
                  <button type="button" class="button is-fullwidth is-info is-dark"
                    @click="forgot_password = !forgot_password">
                    <span class="icon"><i class="fa fa-lock" /></span>
                    <span>Forgot your credentials?</span>
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

<script setup lang="ts">
import { onMounted, ref, watch, onBeforeUnmount } from 'vue'
import { useHead, navigateTo } from '#app'
import { useAuthStore } from '~/store/auth'
import { notification, copyText } from '~/utils'

type UserCredentials = {
  username: string
  password: string
}

definePageMeta({ name: 'auth', layout: 'guest' })
useHead({ title: 'WatchState: Auth' })

const error = ref<string>('')
const form_expose = ref<boolean>(false)
const signup = ref<boolean>(false)

const auth = useAuthStore()
const forgot_password = ref<boolean>(false)
const polling_timer = ref<NodeJS.Timeout | null>(null)

const user = ref<UserCredentials>({ username: '', password: '' })
const reset_cmd = ref<string>('docker exec -ti -- watchstate console system:resetpassword')

const startPolling = (): void => {
  if (null !== polling_timer.value) {
    clearInterval(polling_timer.value)
  }

  polling_timer.value = setInterval(async (): Promise<void> => {
    try {
      const hasUser: boolean = await auth.has_user(true)
      if (false === hasUser) {
        stopPolling()
        forgot_password.value = false
        signup.value = true
        notification('info', 'Ready', 'No users found. You can now create a new account.')
      }
    } catch (e) {
      console.log('Polling error:', e)
    }
  }, 3000)
}

const stopPolling = (): void => {
  if (null !== polling_timer.value) {
    clearInterval(polling_timer.value)
    polling_timer.value = null
  }
}

onMounted(async (): Promise<void> => {
  signup.value = false === (await auth.has_user())
  if (auth.authenticated) {
    await navigateTo('/')
  }
})

onBeforeUnmount((): void => stopPolling())

watch(forgot_password, async (newValue: boolean): Promise<void> => {
  if (false === newValue) {
    stopPolling()
    signup.value = false === (await auth.has_user())
    return
  }
  startPolling()
})

const form_validate = async (): Promise<boolean> => {
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

const do_login = async (): Promise<boolean> => {
  try {
    await auth.login(user.value.username, user.value.password)
    if (auth.authenticated) {
      notification('success', 'Success', 'Login successful. Redirecting...')
      await navigateTo('/')
      return true
    }
    throw new Error('Login failed. Please check your username and password.')
  } catch (e) {
    console.log(e)
    error.value = (e as Error).message
    return false
  }
}

const do_signup = async (): Promise<boolean> => {
  try {
    const state: boolean = await auth.signup(user.value.username, user.value.password)
    if (false === state) {
      error.value = 'Failed to create an account.'
      return false
    }
    return await do_login()
  } catch (e) {
    console.log(e)
    error.value = (e as Error).message
    return false
  }
}
</script>
