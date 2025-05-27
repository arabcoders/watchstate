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

<script setup>
import Message from '~/components/Message'
import request from '~/utils/request'
import {notification} from '~/utils/index'

const emitter = defineEmits(['update']);

const props = defineProps({
  forceShow: {type: Boolean, default: false}
});

let timer = null;
const isLoading = ref(false);
const isRestarting = ref(false);
const status = ref({status: false, message: 'Loading...', restartable: false});

const loadContent = async () => {
  if (isLoading.value) {
    return;
  }
  try {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }

    isLoading.value = true;
    const response = await request('/system/scheduler')
    const json = await response.json()

    status.value = json;
    emitter('update', json);
    timer = setTimeout(loadContent, 60000);
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
}

const restart = async () => {
  if (isRestarting.value || false === confirm('Restart the task scheduler?')) {
    return;
  }

  try {
    isRestarting.value = true;
    const response = await request('/system/scheduler/restart', {method: 'POST'})
    const json = await response.json()
    notification(200 === response.status ? 'success' : 'error', '', json.message ?? json.error.message ?? '??')

    if (200 !== response.status) {
      return;
    }

    status.value = json;
    emitter('update', json);
  } catch (e) {
    console.error(e);
  } finally {
    isRestarting.value = false;
  }
}

onMounted(async () => await loadContent());
onUnmounted(async () => {
  if (!timer) {
    return
  }
  clearTimeout(timer);
  timer = null;
});
</script>
