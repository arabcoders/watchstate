<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-users"/>&nbsp;</span>
          <NuxtLink to="/users">Users</NuxtLink>
          - {{ id }} : Edit
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped"></div>
        </div>

        <div class="is-hidden-mobile">
          <span class="subtitle">Edit user backends configuration.</span>
        </div>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message :newStyle="true" message_class="is-info" title="Loading" icon="fas fa-spinner fa-spin"
                 message="Loading user configuration. Please wait..."/>
      </div>

      <div v-if="!isLoading" class="column is-12">
        <form id="user_edit_form" @submit.prevent="saveContent">
          <div class="card">
            <div class="card-content">
              <div class="field">
                <div class="control is-expanded has-icons-left">
                  <input class="input" type="text" v-model="commandInput" placeholder="s/backend1.import.enabled/false/"
                         @keydown.enter.prevent.stop="applyMiniCommand"/>
                  <span class="icon is-small is-left">
                    <i class="fas fa-terminal"></i>
                  </span>
                </div>
                <p class="help has-text-danger" v-if="commandError">
                  <span class="icon">
                    <i class="fas fa-exclamation-triangle"/>
                  </span>
                  <span>{{ commandError }}</span>
                </p>
              </div>

              <div class="field" v-if="errorForm.message">
                <Message :newStyle="true" title="Error" message_class="is-danger" :useClose="true"
                         icon="fas fa-exclamation-triangle" @close="errorForm.message = ''">
                  <p class="is-bold">{{ errorForm.message }}</p>
                  <ul v-if="errorForm.details && errorForm.details.length > 0">
                    <li v-for="(detail, index) in errorForm.details" :key="index">{{ detail }}</li>
                  </ul>
                </Message>
              </div>

              <div class="field">
                <label class="label has-text-danger">
                  <span class="icon">
                    <i class="fas fa-exclamation-triangle"/>
                  </span>
                  Do not edit the backends name, as indexing and data are keyed by it! This may lead to data loss.
                </label>
                <div class="control">
                  <textarea class="textarea is-family-monospace" v-model="configContent" rows="20"
                            placeholder="Enter server configuration in JSON format..."
                            @blur="formatJSON"></textarea>
                </div>
                <p class="help">
                </p>
              </div>


            </div>

            <footer class="card-footer">
              <span class="card-footer-item">
                <button type="button" class="button is-fullwidth is-info" @click="formatJSON">
                  <span class="icon"><i class="fas fa-indent"/></span>
                  <span>Format</span>
                </button>
              </span>
              <span class="card-footer-item">
                <NuxtLink class="button is-warning is-fullwidth" :to="useRoute().query.redirect as string || '/users'">
                  <span class="icon"><i class="fas fa-cancel"/></span>
                  <span>Cancel</span>
                </NuxtLink>
              </span>
              <span class="card-footer-item">
                <button type="submit" class="button is-primary is-fullwidth" :disabled="isSaving"
                        :class="{ 'is-loading': isSaving }">
                  <span class="icon"><i class="fas fa-save"/></span>
                  <span>Save</span>
                </button>
              </span>
            </footer>
          </div>
        </form>
      </div>

      <div class="column is-12">
        <Message message_class="is-info" :newStyle="true">
          <ul>
            <li>Each backend should have: name, type, url, token, uuid, user, import, export, and options. Format:
              <code>{ "backend_name": { ... }, ... }</code></li>
            <li class="has-text-danger">
              <span class="icon"><i class="fas fa-exclamation-triangle"/></span>
              <span>Directly editing the config must only be done as last resort. Making mistakes may break the user's
                backend configurations, or lead to data loss.</span>
            </li>
            <li>If you edit the backend name, you will have to force database reindex to re-generate the indexes. And
              you will have stale data. Best avoid changing backend names. If you must, than delete the user db file in
              order to re-generate data.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {onMounted, ref} from 'vue'
import {navigateTo, useRoute} from '#app'
import Message from '~/components/Message.vue'
import {notification, parse_api_response, request} from '~/utils'
import {applyCommand, parseCommand} from '~/utils/jsonCommand'
import type {GenericError, GenericResponse, JsonObject} from '~/types'

const id = useRoute().params.user as string
const isLoading = ref<boolean>(false)
const isSaving = ref<boolean>(false)
const configContent = ref<string>('')
const errorForm = ref<{
  message: string,
  details?: Array<string>,
}>({
  message: '',
  details: [],
})

const commandInput = ref<string>('')
const commandError = ref<string>('')

type UserSaveError = GenericError & {
  errors?: Array<string>
}

type UserSaveResponse = GenericResponse & {
  errors?: Array<string>
}

const loadContent = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await request(`/users/${id}`)
    const data = await parse_api_response<JsonObject>(response)

    if ('error' in data) {
      const errorData = data as GenericError
      notification('error', 'Error', errorData.error.message)
      errorForm.value.message = errorData.error.message
      return
    }

    configContent.value = JSON.stringify(data, null, 2)
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error'
    notification('error', 'Error', `Failed to load user configuration. ${message}`)
    errorForm.value.message = message
  } finally {
    isLoading.value = false
  }
}

const applyMiniCommand = async (): Promise<void> => {
  commandError.value = ''

  try {
    if (0 === commandInput.value.trim().length) {
      commandError.value = 'Command cannot be empty.'
      return
    }

    let obj: JsonObject
    try {
      obj = JSON.parse(configContent.value) as JsonObject
    } catch {
      commandError.value = 'Current JSON is invalid. Please fix it before applying commands.'
      return
    }

    const parsed = parseCommand(commandInput.value)
    if (!parsed.ok) {
      commandError.value = parsed.error
      notification('error', 'Invalid command', parsed.error)
      return
    }

    const result = applyCommand(obj, parsed.command)
    if (!result.ok) {
      commandError.value = result.error
      notification('error', 'Failed to apply command', result.error)
      return
    }
    configContent.value = JSON.stringify(result.obj, null, 2)
    commandInput.value = ''
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error'
    commandError.value = message
    notification('error', 'Error', `Failed to apply command. ${message}`)
  }
}

const saveContent = async (): Promise<void> => {
  if (true === isSaving.value) {
    return
  }

  errorForm.value.message = ''
  isSaving.value = true

  try {
    let data: JsonObject

    // Parse JSON content
    try {
      data = JSON.parse(configContent.value) as JsonObject
    } catch {
      errorForm.value.message = 'Invalid JSON format. Please check your syntax.'
      isSaving.value = false
      return
    }

    const response = await request(`/users/${id}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    })

    const result = await parse_api_response<UserSaveResponse>(response)

    if ('error' in result) {
      const errorResult = result as UserSaveError
      errorForm.value.message = errorResult.error.message
      if (errorResult.errors && Array.isArray(errorResult.errors)) {
        errorForm.value.details = errorResult.errors
      }
      return
    }

    notification('success', 'Success', `Server configuration updated for user '${id}'`)
    await navigateTo(useRoute().query.redirect as string || '/users')
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unexpected error'
    errorForm.value.message = `Failed to save configuration. ${message}`
  } finally {
    isSaving.value = false
  }
}

const formatJSON = (): void => {
  if (0 === configContent.value.trim().length) {
    return
  }

  try {
    const data = JSON.parse(configContent.value)
    configContent.value = JSON.stringify(data, null, 2)
    errorForm.value.message = ''
  } catch {
    // Silently fail - don't show error on blur, user might still be editing
  }
}

onMounted((): void => {
  loadContent()
})
</script>

<style scoped>
.textarea.is-family-monospace {
  font-family: 'Courier New', Courier, monospace;
  font-size: 0.875rem;
}
</style>
