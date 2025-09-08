<template>
  <div class="columns is-multiline" v-show="!status.status || forceShow">
    <div class="column is-12">
      <Message
          class="is-2 is-position-relative"
          :message_class="`has-text-dark ${status.status ? 'has-background-success-90' : 'has-background-danger-90'}`"
          :title="`Task scheduler is ${status.status ? 'running' : 'not running'}.`"
          :icon="`fas fa-${status.status ? 'check' : 'exclamation-circle'}`">
        {{ status.message }}

        <div class="m-2 mr-3 is-position-absolute" style="top:0; right: 0;">
          <div class="field is-grouped is-unselectable">
            <div class="control">
              <NuxtLink @click="loadContent" class="is-small">
                <span class="icon"><i class="fas fa-sync" :class="{ 'fa-spin': isLoading }"></i></span> Refresh
              </NuxtLink>
            </div>
            <div class="control">
              <NuxtLink @click="restart" class="is-small" v-if="status.restartable">
                <span class="icon"><i class="fas fa-power-off" :class="{ 'fa-spin': isRestarting }"></i></span> Restart
              </NuxtLink>
            </div>
          </div>
        </div>
      </Message>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount} from 'vue'
import Message from '~/components/Message.vue'
import {request, notification} from '~/utils'

const emit = defineEmits<{
  (e: 'update', status: { status: boolean; message: string; restartable: boolean }): void
}>()

withDefaults(defineProps<{
  /** Force show the scheduler status */
  forceShow?: boolean
}>(), {
  forceShow: false
})

let timer: ReturnType<typeof setTimeout> | null = null
const isLoading = ref<boolean>(false)
const isRestarting = ref<boolean>(false)
const status = ref<{ status: boolean; message: string; restartable: boolean }>({
  status: true,
  message: 'Loading...',
  restartable: false
})

const loadContent = async (): Promise<void> => {
  if (isLoading.value) {
    return
  }
  try {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
    isLoading.value = true
    const response = await request('/system/scheduler')
    const json = await response.json()
    status.value = json
    emit('update', json)
    timer = setTimeout(loadContent, 60000)
  } catch (e) {
    console.error(e)
  } finally {
    isLoading.value = false
  }
}

const restart = async (): Promise<void> => {
  if (isRestarting.value) {
    return
  }

  const {status: confirmStatus} = await useDialog().confirmDialog({
    message: 'Restart the task scheduler?',
    confirmText: 'Restart',
    confirmColor: 'is-warning'
  })

  if (true !== confirmStatus) {
    return
  }

  try {
    isRestarting.value = true
    const response = await request('/system/scheduler/restart', {method: 'POST'})
    const json = await response.json()
    notification(200 === response.status ? 'success' : 'error', '', json.message ?? json.error?.message ?? '??')
    if (200 !== response.status) {
      return
    }
    status.value = json
    emit('update', json)
  } catch (e) {
    console.error(e)
  } finally {
    isRestarting.value = false
  }
}

onMounted(() => loadContent())

onBeforeUnmount(() => {
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
})
</script>
