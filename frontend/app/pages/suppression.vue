<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span id="env_page_title" class="title is-4">
          <span class="icon"><i class="fa fa-bug-slash"></i></span>
          Log Suppressor
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button class="button is-primary" v-tooltip.bottom="'Add new rule'" @click="toggleForm = !toggleForm">
                <span class="icon">
                  <i class="fa fa-add"></i>
                </span>
              </button>
            </p>
            <p class="control">
              <button class="button is-info" @click="loadContent">
                <span class="icon"><i class="fa fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle">
            This page allow you to suppress some logs from being shown or recorded.
          </span>
        </div>
      </div>

      <div class="column is-12" v-if="toggleForm">
        <form id="page_form" @submit.prevent="sendData">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-unselectable is-justify-center">
                <template v-if="formData.id">
                  Edit suppression rule
                </template>
                <template v-else>
                  Add new suppression rule
                </template>
              </p>
            </header>

            <div class="card-content">
              <div class="field">
                <label class="label is-unselectable" for="form_type">Matching type</label>
                <div class="control has-icons-left">
                  <div class="select is-fullwidth">
                    <select v-model="formData.type" id="form_type" :disabled="formData.id !== null"
                            class="is-capitalized">
                      <option value="" disabled>Select Type</option>
                      <option v-for="type in types" :key="`form-${type}`" :value="type">
                        {{ type }}
                      </option>
                    </select>
                  </div>
                  <div class="icon is-left">
                    <i class="fa fa-microchip"></i>
                  </div>
                </div>
              </div>

              <div class="field">
                <label class="label is-unselectable" for="form_rule">Rule</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_rule" type="text" v-model="formData.rule"
                         :placeholder="'regex' === formData.type ? '/this match \d+/is' : 'hide_me'">
                  <div class="icon is-small is-left">
                    <i class="fa"
                       :class="{ 'fa-code': 'regex' === formData.type, 'fa-heading': 'contains' === formData.type }"></i>
                  </div>
                  <p class="help">
                    <template v-if="'regex' === formData.type">
                      Regular expression. To test try
                      <span>
                        <NuxtLink to="https://regex101.com/" target="_blank" v-text="'this link'"/>
                      </span><span></span>. Select <code>PCRE2 (PHP >=7.3)</code> flavor.
                    </template>
                    <template v-else>
                      Case sensitive string contains match.
                    </template>
                  </p>
                </div>
              </div>

              <div class="field">
                <label class="label is-unselectable" for="form_example">Example</label>
                <div class="control has-icons-left">
                  <input class="input" id="form_example" type="text" v-model="formData.example"
                         placeholder="String example to test the rule against.">
                  <div class="icon is-small is-left"><i class="fa fa-font"></i></div>
                  <p class="help">
                    The example text must trigger the supplied rule. This is used to test the rule. if it's working as
                    expected.
                  </p>
                </div>
              </div>
            </div>

            <div class="card-footer">
              <div class="card-footer-item">
                <button class="button is-fullwidth is-primary" type="submit"
                        :disabled="!formData?.rule || !formData.example">
                  <span class="icon-text">
                    <span class="icon"><i class="fa fa-save"></i></span>
                    <span>Save</span>
                  </span>
                </button>
              </div>
              <div class="card-footer-item">
                <button class="button is-fullwidth is-danger" type="button"
                        @click="cancelForm">
                  <span class="icon-text">
                    <span class="icon"><i class="fa fa-cancel"></i></span>
                    <span>Cancel</span>
                  </span>
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div class="column is-12" v-if="isLoading">
        <Message message_class="has-background-info-90 has-text-dark" title="Loading" icon="fas fa-spinner fa-spin"
                 message="Loading data. Please wait..."/>
      </div>

      <div class="column is-12" v-if="false === isLoading && items.length<1">
        <Message message_class="has-background-warning-90 has-text-dark" title="No suppression rules"
                 icon="fa fa-exclamation-triangle">
          <p>
            No suppression rules were found. To add a new rule click the <span class="is-clickable icon"><i
              class="fa fa-add"></i></span> button on top right of this page.
          </p>
        </Message>
      </div>

      <div class="column is-12" v-if="items">
        <div class="columns is-multiline">
          <div class="column is-6" v-for="item in items" :key="item.id as string">
            <div class="card is-flex is-full-height is-flex-direction-column">
              <header class="card-header">
                <p class="card-header-title is-justify-center is-unselectable">
                  <span class="icon"><i class="fa fa-microchip"></i></span>
                  <span class="is-capitalized">{{ item.type }}</span>
                </p>
              </header>
              <div class="card-content is-flex-grow-1">
                <div class="columns is-multiline">
                  <div class="column is-12">
                    <span class="icon">
                      <i class="fa"
                         :class="{ 'fa-code': 'regex' === formData.type, 'fa-r': 'contains' === formData.type }"></i>
                    </span>
                    <code>{{ item.rule }}</code>
                  </div>
                  <div class="column is-12">
                    <span class="icon"><i class="fa fa-e"></i></span>
                    <code>{{ item.example }}</code>
                  </div>
                </div>
              </div>
              <footer class="card-footer">
                <div class="card-footer-item">
                  <button class="button is-primary is-fullwidth" @click="editItem(item)">
                    <span class="icon-text">
                      <span class="icon"><i class="fa fa-edit"></i></span>
                      <span>Edit</span>
                    </span>
                  </button>
                </div>
                <div class="card-footer-item">
                  <button class="button is-fullwidth is-danger" @click="deleteItem(item)">
                    <span class="icon-text">
                      <span class="icon"><i class="fa fa-trash"></i></span>
                      <span>Delete</span>
                    </span>
                  </button>
                </div>
              </footer>
            </div>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <Message message_class="has-background-info-90 has-text-dark" :toggle="show_page_tips"
                 @toggle="show_page_tips = !show_page_tips" :use-toggle="true" title="Tips" icon="fa fa-info-circle">
          <ul>
            <li>The log suppressor work on almost everything that <strong>WatchState</strong> output. However, there are
              some exceptions.
            </li>
            <li>The use case for this feature, is that sometimes it's out of your hands to fix a problem, and the
              constant logging of the same error can be annoying. This feature allows you to suppress the error from
              being shown/recorded.
            </li>
            <li>It is less compute intensive to use <strong>contains</strong> type, than <strong>regex</strong> type, as
              each rule will be tested against every single output message. The less rules you have, the better. Having
              many rules will lead to performance degradation.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, watch, onMounted} from 'vue'
import {useHead, useRoute} from '#app'
import {useStorage} from '@vueuse/core'
import Message from '~/components/Message.vue'
import {request, notification} from '~/utils'
import {useDialog} from '~/composables/useDialog'
import type {SuppressionItem} from '~/types'

useHead({title: 'Log Suppressor'})

const defaultData = (): SuppressionItem => ({id: null, rule: '', example: '', type: 'contains'})

const isLoading = ref<boolean>(false)
const items = ref<Array<SuppressionItem>>([])
const toggleForm = ref<boolean>(false)
const formData = ref<SuppressionItem>(defaultData())
const show_page_tips = useStorage('show_page_tips', true)
const types = ref<Array<'contains' | 'regex'>>(['contains', 'regex'])

const loadContent = async (): Promise<void> => {
  isLoading.value = true
  items.value = []
  let response: Response, json: any
  try {
    response = await request(`/system/suppressor`)
    json = await response.json()
    if (useRoute().name !== 'suppression') {
      return
    }
    if (!response.ok) {
      notification('error', 'Error', `${json.error.code ?? response.status}: ${json.error.message ?? response.statusText}`)
      return
    }
    items.value = json.items
    types.value = json.types
  } catch (e: any) {
    notification('error', 'Error', e.message)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => loadContent())

const deleteItem = async (item: SuppressionItem): Promise<void> => {
  const {status: confirmStatus} = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete rule id '${item.id}'?`,
    confirmColor: 'is-danger',
  })

  if (true !== confirmStatus) {
    return
  }

  const response = await request(`/system/suppressor/${item.id}`, {method: 'DELETE'})
  if (response.ok) {
    items.value = items.value.filter(i => i.id !== item.id)
    notification('success', 'Success', `Suppression rule id '${item.id}' successfully deleted.`, 5000)
  }
}

const sendData = async (): Promise<void> => {
  const requiredFields: Array<keyof SuppressionItem> = ['rule', 'example', 'type']
  for (const field of requiredFields) {
    if (!formData.value[field]) {
      notification('error', 'Error', `${field} field is required.`, 5000)
      return
    }
  }
  try {
    const response = await request(`/system/suppressor${formData.value.id ? `/${formData.value.id}` : ''}`, {
      method: formData.value.id ? 'PUT' : 'POST',
      body: JSON.stringify({
        rule: formData.value.rule,
        example: formData.value.example,
        type: formData.value.type,
      })
    })
    if (304 === response.status) {
      cancelForm()
      return
    }
    const json = await response.json()
    if (!response.ok) {
      notification('error', 'Error', `${json.error.code}: ${json.error.message}`, 5000)
      return
    }
    if (!formData.value.id) {
      items.value.push(json)
    } else {
      const idx = items.value.findIndex(i => i.id === formData.value.id)
      if (idx !== -1) {
        items.value[idx] = json
      }
    }
    const action = formData.value.id ? 'updated' : 'added'
    notification('success', 'Success', `Suppression rule successfully ${action}.`, 5000)
    cancelForm()
  } catch (e: any) {
    notification('error', 'Error', `Request error. ${e.message}`, 5000)
  }
}

const editItem = (item: SuppressionItem): void => {
  formData.value = {
    id: item.id,
    rule: item.rule,
    example: item.example,
    type: item.type,
  }
  toggleForm.value = true
}

const cancelForm = (): void => {
  formData.value = defaultData()
  toggleForm.value = false
}

watch(toggleForm, (value: boolean) => {
  if (!value) {
    formData.value = defaultData()
  }
})
</script>
