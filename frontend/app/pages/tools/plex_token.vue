<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-server"/></span>
          Validate Plex Token
        </span>
        <div class="is-pulled-right"></div>
        <div class="is-hidden-mobile">
          You can use this page to validate if given token is able to communicates with the plex.tv API.
        </div>
      </div>

      <div class="column is-12">
        <form @submit.prevent="validateToken">
          <div class="card">
            <div class="card-header">
              <p class="card-header-title">X-Plex-Token</p>
            </div>

            <div class="card-content">
              <Message v-if="success" message_class="has-background-success-90 has-text-dark" title="Success!"
                       icon="fas fa-check-circle" :useClose="true" @close="() => success = ''">
                <p>
                  <span class="icon"><i class="fas fa-check"/></span>
                  <span>{{ success }}</span>
                </p>
              </Message>
              <Message v-if="error" message_class="has-background-danger-90 has-text-dark"
                       title="Error" icon="fas fa-exclamation-triangle" :useClose="true"
                       @close="() => error = ''">
                <p>
                  <span class="icon"><i class="fas fa-exclamation"/></span>
                  <span>{{ error }}</span>
                </p>
              </Message>

              <div class="field">
                <div class="field has-addons">
                  <div class="control is-expanded has-icons-left">
                    <input class="input" v-model="token" required placeholder="X-Plex-Token"
                           :type="false === exposeToken ? 'password' : 'text'">
                    <span class="icon is-left"><i class="fas fa-key"/></span>
                  </div>
                  <div class="control">
                    <button type="button" class="button is-primary" @click="exposeToken = !exposeToken"
                            v-tooltip="'Show/Hide token'">
                      <span class="icon" v-if="!exposeToken"><i class="fas fa-eye"/></span>
                      <span class="icon" v-else><i class="fas fa-eye-slash"/></span>
                    </button>
                  </div>
                </div>
                <p>
                  Enter the <code>X-Plex-Token</code>.
                  <NuxtLink target="_blank" to="https://support.plex.tv/articles/204059436"
                            v-text="'Visit This link'"/>
                  to learn how to get the token.
                </p>
              </div>
            </div>
            <div class="card-footer">
              <div class="card-footer-item">
                <button class="button is-fullwidth is-primary" type="submit" :disabled="!token || isLoading">
                  <template v-if="isLoading">
                    <span class="icon"><i class="fas fa-spinner fa-spin"></i></span>
                    <span>Validating...</span>
                  </template>
                  <template v-else>
                    <span class="icon"><i class="fas fa-check"></i></span>
                    <span>Validate</span>
                  </template>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import request from "~/utils/request.js"
import {parse_api_response} from "~/utils/index.js"

const isLoading = ref(false)
const token = ref('')
const error = ref('')
const success = ref('')
const exposeToken = ref(false)

const validateToken = async () => {
  error.value = ''
  success.value = ''

  if (!token.value) {
    error.value = 'Please enter a valid token.'
    return
  }

  try {

    isLoading.value = true

    const response = await request(`/backends/validate/token/plex`, {
      method: 'POST',
      body: JSON.stringify({token: token.value})
    })

    const resp = await parse_api_response(response)

    if (200 !== response.status) {
      error.value = resp.error.message
      return
    }

    success.value = resp.info.message
  } catch (e) {
    error.value = `An error occurred while validating the token. ${e.message}`
  } finally {
    isLoading.value = false
  }
}

</script>
