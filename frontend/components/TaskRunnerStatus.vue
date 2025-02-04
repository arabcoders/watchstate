<template>
  <Message
      class="is-2 is-position-relative"
      :message_class="`has-text-dark ${status.status ? 'has-background-success-90' : 'has-background-warning-90'}`"
      :title="`Task runner process is ${status.status ? 'active' : 'not active'}`"
      :icon="`fas fa-${status.status ? 'pause' : 'exclamation-circle'}`">
    {{ status.message }}

    <div class="m-2 mr-3 is-position-absolute" style="top:0; right: 0;">
      <div class="field is-grouped is-unselectable">
        <div class="control">
          <NuxtLink @click="loadContent" class="is-small">
            <span class="icon"><i class="fas fa-sync" :class="{ 'fa-spin': isLoading }"></i></span> Refresh
          </NuxtLink>
        </div>
        <div class="control">
          <NuxtLink @click="restartTaskRunner" class="is-small" v-if="status.restartable">
            <span class="icon"><i class="fas fa-power-off" :class="{ 'fa-spin': isRestarting }"></i></span> Restart
          </NuxtLink>
        </div>
      </div>
    </div>
  </Message>
</template>

<script setup>
import Message from '~/components/Message'
import request from '~/utils/request'
import {notification} from '~/utils/index'

defineProps({
  status: {
    type: Object,
    required: false,
  }
});

const isLoading = ref(false);
const isRestarting = ref(false);
const emitter = defineEmits(['taskrunner_update']);

const loadContent = async () => {
  if (isLoading.value) {
    return;
  }
  try {
    isLoading.value = true;
    const response = await request('/system/taskrunner')
    const json = await response.json()
    emitter('taskrunner_update', json);
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
}

const restartTaskRunner = async () => {
  if (isRestarting.value || false === confirm('Restart the task runner?')) {
    return;
  }

  try {
    isRestarting.value = true;
    const response = await request('/system/taskrunner/restart', {method: 'POST'})
    const json = await response.json()
    notification(200 === response.status ? 'success' : 'error', 'Task Runner', json.message ?? json.error.message ?? '??')

    if (200 !== response.status) {
      return;
    }

    emitter('taskrunner_update', json);
  } catch (e) {
    console.error(e);
  } finally {
    isRestarting.value = false;
  }
}

onMounted(async () => await loadContent());
</script>
