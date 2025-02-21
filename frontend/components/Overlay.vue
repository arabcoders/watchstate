<template>
  <div class="modal is-active">
    <div class="modal-background" @click="closeOverLay()"></div>
    <div class="modal-card" style="min-width: calc(100% - 30%);">
      <header class="modal-card-head">
        <p class="modal-card-title" v-text="model_title"></p>
        <button class="delete" @click="closeOverLay"></button>
      </header>
      <div class="modal-card-body">
        <slot />
      </div>
    </div>
  </div>
</template>

<script setup>
const emit = defineEmits(['closeOverlay']);

const props = defineProps({
  title: {
    type: String,
    required: true,
  },
});

const model_title = ref(props.title.replace(/^\//g, ''));

const closeOverLay = () => emit('closeOverlay');

const eventHandler = e => {
  if (e.key !== 'Escape') {
    return
  }
  closeOverLay()
}

onMounted(() => window.addEventListener('keydown', eventHandler))
onUnmounted(() => window.removeEventListener('keydown', eventHandler))
</script>
