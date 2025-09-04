<template>
  <div>
    <div class="hero is-dark is-fullheight">
      <div class="hero-body">
        <div class="container" style="background-color: unset !important;">
          <div class="columns is-centered">
            <div class="column is-6-tablet is-6-desktop is-4-widescreen">
              <div class="box" v-if="error">
                <span class="icon"><i class="fa fa-info"/></span>
                <span class="has-text-danger">{{ error }}</span>
              </div>

              <div class="box" v-if="forgot_password">
                <div class="content">
                  <h4>
                    <span class="icon"><i class="fa fa-lock"/></span>
                    <span>How to Reset system login</span>
                  </h4>
                  <p>
                    To reset your system password, you need to run the following command from your docker host
                  </p>
                  <p>
                    <code style="min-height: 50px;" class="is-block p-2">{{ reset_cmd }}</code>
                  </p>
                  <p>
                    <a @click.prevent="copyText(reset_cmd)">
                      <span class="icon"><i class="fa fa-copy"/></span>
                      <span>Copy command</span>
                    </a>
                  </p>
                </div>
                <div class="field is-grouped">
                  <div class="control is-expanded">
                    <button class="button is-fullwidth is-info is-dark" @click="forgot_password = false">
                      <span class="icon"><i class="fa fa-arrow-left"/></span>
                      <span>Back to login</span>
                    </button>
                  </div>
                </div>
              </div>

              <form method="post" @submit.prevent="formValidate()" class="box" v-if="!forgot_password">
                <div class="field">
                  <label for="user-id" class="label">
                    {{ signup ? 'Create an account' : 'Login' }}
                  </label>
                  <div class="control has-icons-left">
                    <input id="user-id" type="text" placeholder="Username" class="input" required
                           autocomplete="username" name="username" v-model="user.username" autofocus>
                    <span class="icon is-left"><i class="fa fa-user"/></span>
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
                          <span class="icon is-left"><i class="fa fa-lock"/></span>
                        </div>
                        <div class="control">
                          <button type="button" class="button is-secondary" @click="form_expose = !form_expose">
                            <span class="icon" v-if="!form_expose"><i class="fas fa-eye"/></span>
                            <span class="icon" v-else><i class="fas fa-eye-slash"/></span>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="field">
                  <button type="submit" class="button is-fullwidth is-dark is-light">
                    <span class="icon"><i class="fa fa-sign-in"/></span>
                    <span>
                      {{ signup ? 'Signup' : 'Login' }}
                    </span>
                  </button>
                </div>
                <div class="field" v-if="!signup">
                  <button type="button" class="button is-fullwidth is-info is-dark"
                          @click="forgot_password = !forgot_password">
                    <span class="icon"><i class="fa fa-lock"/></span>
                    <span>Forgot your user or password?</span>
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
import {onMounted, ref} from 'vue'
import {useAuthStore} from '~/store/auth.ts'

definePageMeta({name: "auth", layout: 'guest'})
useHead({title: 'WatchState: Auth'})

const error = ref('')
const form_expose = ref(false)
const signup = ref(false)

const auth = useAuthStore()
const forgot_password = ref(false)

const user = ref({username: '', password: ''})
const reset_cmd = ref('docker exec -ti -- watchstate console system:resetpassword')

onMounted(async () => {
  signup.value = false === (await auth.has_user())
  if (auth.authenticated) {
    return await navigateTo('/')
  }
})

watch(forgot_password, async newValue => {
  if (false === newValue) {
    signup.value = false === (await auth.has_user())
  }
})

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
  } catch (e) {
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
  } catch (e) {
    console.log(e)
    error.value = e.message
    return false
  }
}

</script>
