<template>
  <div>
    <div v-if="!selectedRequest">
      <div class="columns is-multiline">
        <div class="column is-12">
          <h2 class="title is-4">What are you trying to do?</h2>
        </div>
        <div v-for="(choice, index) in choices" :key="index" class="column is-6-tablet is-12-mobile">
          <div class="box content">
            <h3 class="title is-5">{{ choice.title }}</h3>
            <p>{{ choice.text }}</p>
            <button class="button is-link is-outlined" @click="pickRequest(index)">
              <span class="icon"><i class="fas fa-cogs"/></span>
              <span>Start</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="selectedRequest">
      <div v-for="stepId in steps" :key="stepId" v-show="stepId === currentStep" class="box">
        <slot :name="stepId">
          <h3 class="title is-5">Step: {{ stepId }}</h3>
          <p>Missing a guide for <code>{{ stepId }}</code> step.</p>
        </slot>
      </div>

      <nav class="level is-mobile">
        <div class="level-left">
          <div class="field is-grouped">
            <div class="control is-fullwidth">
              <button class="button is-fullwidth is-info" @click="nextStep" :disabled="isLastStep">
                Next
              </button>
            </div>
            <div class="control">
              <button class="button ml-2" @click="reset" :class="{'is-danger': !isLastStep, 'is-success': isLastStep}">
                {{ !isLastStep ? 'Reset' : 'Completed' }}
              </button>
            </div>
          </div>
        </div>
        <div class="level-right">
          <div class="level-item">
            Step {{ currentStepIndex + 1 }} of {{ steps.length }}
          </div>
        </div>
      </nav>

      <Message message_class="is-background-warning-90 has-text-dark" icon="fas fa-info-circle" title="Important">
        Your progress is saved even if you navigate away. So feel free to do the steps and come back after each one.
      </Message>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'

const choices = [
  {
    title: 'One-way sync',
    text: 'For example, You want to import data from plex, and send it to jellyfin/emby but not the other way around',
    steps: ['add_backend', 'import_data', 'force_export'],
  },
  {
    title: 'Two-way sync',
    text: 'This will allow all backends to sync with each other. I.e. plex to jellyfin, jellyfin to emby, emby to plex.',
    steps: ['add_backend', 'import_data', 'force_export', 'enabled_import'],
  },
  {
    title: 'Enable webhooks',
    text: 'Step step on how to get webhooks working.',
    steps: ['add_backend', 'import_data', 'force_export', 'enabled_import'],
  },
  {
    title: 'Enable Sub-users',
    text: 'Guide on how to enable sub-users.',
    steps: ['sub_users_main_user', 'sub_user_create'],
  },
]

const selectedRequest = useStorage('guide-request', null)
const currentStepIndex = useStorage('guide-step-index', 0)

const steps = computed(() => {
  const c = choices[selectedRequest.value] || null
  console.log(c)
  return c ? choices[selectedRequest.value].steps : []
})

const currentStep = computed(() => steps.value[currentStepIndex.value])
const isLastStep = computed(() => currentStepIndex.value >= steps.value.length - 1)

const pickRequest = request => {
  selectedRequest.value = String(request)
  currentStepIndex.value = 0
}

const nextStep = () => {
  if (!isLastStep.value) {
    currentStepIndex.value++
  }
}

const reset = () => {
  selectedRequest.value = null
  currentStepIndex.value = 0
}
</script>
