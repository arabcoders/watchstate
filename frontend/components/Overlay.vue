<template>
  <div class="modal">
    <div class="modal-background"></div>
    <div class="modal-card" style="min-width: calc(100% - 30%);">
      <header class="modal-card-head">
        <p class="modal-card-title el" id="modal-head"></p>
        <button class="delete" @click="closeOverLay"></button>
      </header>
      <slot></slot>
    </div>
  </div>
</template>

<script setup>
const emit = defineEmits(['closeOverlay']);
const closeOverLay = () => emit('closeOverlay');
</script>
