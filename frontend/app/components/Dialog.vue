<style scoped>
/* container fades */
.dialog-enter-active,
.dialog-leave-active {
  transition: opacity .18s ease;
}

.dialog-enter-from,
.dialog-leave-to {
  opacity: 0;
}

/* animate the card itself */
.dialog-enter-active .modal-card,
.dialog-leave-active .modal-card {
  transition: transform .18s ease, opacity .18s ease;
}

.dialog-enter-from .modal-card {
  transform: translateY(-8px);
  opacity: .98;
}

.dialog-leave-to .modal-card {
  transform: translateY(-8px);
  opacity: .98;
}
</style>

<template>
  <Teleport to="body">
    <transition name="dialog" @after-enter="focusInput">
      <div id="app-dialog-host" v-if="state.current" class="modal is-active" @keydown.esc="onCancel">
        <div class="modal-background" @click="onCancel"/>
        <div class="modal-card" @keydown.enter.stop.prevent="onEnter">
          <header class="modal-card-head p-4">
            <p class="modal-card-title">{{ state.current?.opts.title ?? defaultTitle }}</p>
            <button class="delete" aria-label="close" @click="onCancel"/>
          </header>

          <section class="modal-card-body">
            <p v-if="state.current?.opts.message" class="mb-3">
              {{ state.current?.opts.message }}
            </p>

            <!-- prompt input -->
            <div v-if="state.current?.type === 'prompt'" class="field">
              <div class="control">
                <input ref="inputEl" class="input" type="text" v-model="localInput"
                       :placeholder="(state.current?.opts as any)?.placeholder ?? ''" @keyup.stop/>
              </div>
              <p v-if="state.errorMsg" class="help is-danger is-bold is-unselectable">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-exclamation-triangle"/></span>
                  <span>{{ state.errorMsg }}</span>
                </span>
              </p>
            </div>
            <div v-else-if="'confirm' === state.current?.type && (state.current?.opts as ConfirmOptions)?.rawHTML"
                 class="content" v-html="(state.current?.opts as ConfirmOptions)?.rawHTML"/>
          </section>

          <footer class="modal-card-foot p-4 is-justify-content-flex-end">
            <template v-if="state.current?.type === 'alert'">
              <button id="primaryButton" class="button is-danger" @click="onEnter">
                <span class="icon-text">
                  <span class="icon"><i class="fas fa-check"/></span>
                  <span>{{ (state.current?.opts as any)?.confirmText ?? 'OK' }}</span>
                </span>
              </button>
            </template>

            <template v-else-if="state.current?.type === 'confirm' || state.current?.type === 'prompt'">
              <div class="field is-grouped">
                <div class="control">
                  <button id="primaryButton" class="button" @click="onEnter"
                          :class="state.current?.opts.confirmColor ?? 'is-primary'"
                          :disabled="localInput === (state.current?.opts as PromptOptions)?.initial">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-check"/></span>
                      <span>{{ (state.current?.opts as any)?.confirmText ?? 'OK' }}</span>
                    </span>
                  </button>
                </div>
                <div class="control">
                  <button class="button is-info" @click="onCancel">
                    <span class="icon-text">
                      <span class="icon"><i class="fas fa-times"/></span>
                      <span>{{ (state.current?.opts as any)?.cancelText ?? 'Cancel' }}</span>
                    </span>
                  </button>
                </div>
              </div>
            </template>
          </footer>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup lang="ts">
import {ref, watch, nextTick, computed} from 'vue'
import {useDialog, type ConfirmOptions, type PromptOptions} from '~/composables/useDialog'

const {state, confirm, cancel} = useDialog()

const localInput = ref('')

watch(() => state.current, (cur) => {
  if (state.current) {
    disableOpacity()
  } else {
    enableOpacity()
  }

  localInput.value = cur?.type === 'prompt' ? (cur.opts as any).initial ?? '' : ''
}, {immediate: true})

const inputEl = ref<HTMLInputElement>()
const focusPrimary = () => {
  const root = document.getElementById('app-dialog-host')
  if (!root) {
    return
  }
  const btn = root.querySelector<HTMLButtonElement>('#primaryButton')
  btn?.focus()
}
const focusInput = async () => {
  await nextTick()
  if (state.current?.type === 'prompt') {
    requestAnimationFrame(() => inputEl.value?.focus({preventScroll: true}))
    return
  }
  requestAnimationFrame(focusPrimary)
}

const onCancel = () => cancel()
const onEnter = () => confirm(localInput.value)

const defaultTitle = computed(() => {
  if (!state.current) {
    return ''
  }
  switch (state.current.type) {
    case 'alert':
      return 'Alert'
    case 'confirm':
      return 'Confirm'
    case 'prompt':
      return 'Input required'
    default:
      return 'Dialog'
  }
})
</script>
