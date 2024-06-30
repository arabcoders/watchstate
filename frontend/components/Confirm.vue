<template>
  <form @submit.prevent="checkSecret">
    <div class="card">
      <header class="card-header">
        <p class="card-header-title">{{ props.title }}</p>
        <p class="card-header-icon"><span class="icon"><i class="fas" :class="props.titleIcon"></i></span></p>
      </header>
      <div class="card-content">
        <div class="field">
          <label class="label">
            To proceed, please write '<code>{{ randomSecret }}</code>' in the box below.
          </label>
          <div class="control">
            <input class="input" type="text" v-model="userSecret" placeholder="Enter the secret key."/>
          </div>
          <p class="help" v-if="props.warning">
            <span class="icon" :class="props.warningIconClass">
              <i class="fas" :class="props.warningIcon"></i>
            </span>
            {{ props.warning }}
          </p>
        </div>
      </div>
      <footer class="card-footer">
        <div class="card-footer-item">
          <button class="button is-fullwidth" type="submit" :disabled="userSecret !== randomSecret"
                  :class="userSecret !== randomSecret ? 'is-primary' : 'is-danger'">
            <span class="icon">
              <i class="fas" :class="userSecret !== randomSecret ? props.lockedButtonIcon : props.unlockedButton "></i>
            </span>
            <span>
              {{ userSecret !== randomSecret ? props.lockedButton : props.unlockedButton }}
            </span>
          </button>
        </div>
      </footer>
    </div>
  </form>
</template>

<script setup>
import {makeSecret, notification} from '~/utils/index'

const props = defineProps({
  warning: {
    type: String,
    required: false
  },
  warningIcon: {
    type: String,
    required: false,
    default: 'fa-info-circle',
  },
  warningIconClass: {
    type: String,
    required: false,
    default: 'has-text-warning',
  },
  title: {
    type: String,
    required: false,
    default: 'Confirm action.',
  },
  titleIcon: {
    type: String,
    required: false,
    default: 'fa-exclamation-triangle',
  },
  lockedButton: {
    type: String,
    required: false,
    default: 'Action is locked.',
  },
  lockedButtonIcon: {
    type: String,
    required: false,
    default: 'fa-lock',
  },
  unlockedButton: {
    type: String,
    required: false,
    default: 'Perform the requested action.',
  },
  unlockedButtonIcon: {
    type: String,
    required: false,
    default: 'fa-unlock',
  },
  length: {
    type: Number,
    required: false,
    default: 8,
  },
})

const emit = defineEmits(['confirmed'])

const randomSecret = ref('')
const userSecret = ref('')

const checkSecret = () => {
  if (userSecret.value !== randomSecret.value) {
    notification('error', 'Error', 'Invalid secret key. Please try again.')
    return
  }

  userSecret.value = ''

  emit('confirmed')
}

onMounted(() => randomSecret.value = makeSecret(props.length))
</script>
