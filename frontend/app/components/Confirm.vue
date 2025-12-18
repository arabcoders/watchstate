<template>
  <form @submit.prevent="checkForm">
    <div class="card">
      <header class="card-header">
        <p class="card-header-title">{{ props.title }}</p>
        <p class="card-header-icon"><span class="icon"><i class="fas" :class="props.titleIcon"></i></span></p>
      </header>
      <div class="card-content">
        <div class="field">
          <label class="label">
            To proceed, Enable the switch below to unlock the action.
          </label>
          <div class="control">
            <input id="user_confirm" type="checkbox" class="switch is-success" v-model="user_confirmed">
            <label for="user_confirm" class="is-unselectable">Unlock the action.</label>
          </div>
          <p class="help is-bold" v-if="props.warning">
            <span class="icon" :class="props.warningIconClass">
              <i class="fas" :class="props.warningIcon"></i>
            </span>
            {{ props.warning }}
          </p>
        </div>
      </div>
      <footer class="card-footer">
        <div class="card-footer-item">
          <button class="button is-fullwidth" type="submit" :disabled="!user_confirmed"
                  :class="!user_confirmed ? 'is-primary' : 'is-danger'">
            <span class="icon">
              <i class="fas"
                 :class="!user_confirmed ? props.lockedButtonIcon : props.unlockedButtonIcon"></i>
            </span>
            <span>
              {{ !user_confirmed ? props.lockedButton : props.unlockedButton }}
            </span>
          </button>
        </div>
      </footer>
    </div>
  </form>
</template>

<script setup lang="ts">
import {ref, onBeforeUnmount, onMounted} from 'vue'
import {disableOpacity, enableOpacity, notification} from '~/utils'

const props = withDefaults(defineProps<{
  /** Warning message to display (optional) */
  warning?: string
  /** Icon class for the warning (optional) */
  warningIcon?: string
  /** CSS class for the warning icon (optional) */
  warningIconClass?: string
  /** Title of the confirmation dialog */
  title?: string
  /** Icon class for the title */
  titleIcon?: string
  /** Text for the locked button state */
  lockedButton?: string
  /** Icon class for the locked button */
  lockedButtonIcon?: string
  /** Text for the unlocked button state */
  unlockedButton?: string
  /** Icon class for the unlocked button */
  unlockedButtonIcon?: string
  /** Length of the secret key to generate */
  length?: number
}>(), {
  warning: '',
  warningIcon: 'fa-info-circle',
  warningIconClass: 'has-text-warning',
  title: 'Confirm action.',
  titleIcon: 'fa-exclamation-triangle',
  lockedButton: 'Action is locked.',
  lockedButtonIcon: 'fa-lock',
  unlockedButton: 'Perform the requested action.',
  unlockedButtonIcon: 'fa-unlock',
  length: 8,
})

const emit = defineEmits<{
  (e: 'confirmed'): void
}>()

const user_confirmed = ref<boolean>(false)

const checkForm = (): void => {
  if (!user_confirmed.value) {
    notification('error', 'Error', 'You must confirm the action by enabling the switch.')
    return
  }
  user_confirmed.value = false
  emit('confirmed')
}

onMounted(() => disableOpacity())
onBeforeUnmount(() => enableOpacity())
</script>
