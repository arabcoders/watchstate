<template>
  <UModal v-model:open="isOpen" :title="dialogTitle" :ui="modalUi" @after:enter="focusTarget">
    <template #body>
      <div class="space-y-4">
        <p v-if="dialogMessage" class="text-sm text-default whitespace-pre-line wrap-break-word">
          {{ dialogMessage }}
        </p>

        <UFormField v-if="isPrompt" :error="state.errorMsg ?? undefined" class="w-full">
          <UInput
            ref="inputEl"
            v-model="localInput"
            type="text"
            class="w-full"
            :placeholder="promptOptions?.placeholder ?? ''"
            @keydown.enter.stop.prevent="onEnter"
          />
        </UFormField>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-end gap-2">
        <template v-if="isAlert">
          <UButton
            type="button"
            icon="i-lucide-check"
            data-dialog-primary="true"
            :color="primaryButtonTheme.color"
            :variant="primaryButtonTheme.variant"
            @click="onEnter"
          >
            {{ confirmText }}
          </UButton>
        </template>

        <template v-else-if="isConfirm || isPrompt">
          <UButton
            type="button"
            color="neutral"
            variant="outline"
            icon="i-lucide-x"
            @click="onCancel"
          >
            {{ cancelText }}
          </UButton>
          <UButton
            type="button"
            icon="i-lucide-check"
            data-dialog-primary="true"
            :color="primaryButtonTheme.color"
            :variant="primaryButtonTheme.variant"
            :disabled="isPromptUnchanged"
            @click="onEnter"
          >
            {{ confirmText }}
          </UButton>
        </template>
      </div>
    </template>
  </UModal>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import {
  useDialog,
  type AlertOptions,
  type ConfirmOptions,
  type PromptOptions,
} from '~/composables/useDialog';

type InputExpose = {
  inputRef: HTMLInputElement | null;
};

type ButtonColor = 'primary' | 'secondary' | 'success' | 'info' | 'warning' | 'error' | 'neutral';
type ButtonVariant = 'solid' | 'outline' | 'soft' | 'subtle' | 'ghost' | 'link';

type ButtonTheme = {
  color: ButtonColor;
  variant: ButtonVariant;
};

const { state, confirm, cancel } = useDialog();

const localInput = ref('');
const inputEl = ref<InputExpose | null>(null);

const modalUi = {
  overlay: 'z-[120]',
  content: 'z-[121] max-w-lg',
  footer: 'flex items-center justify-end gap-2 p-4 sm:px-6',
};

const currentDialog = computed(() => state.current);

const isOpen = computed({
  get: () => currentDialog.value !== null,
  set: (value: boolean) => {
    if (!value && currentDialog.value) {
      onCancel();
    }
  },
});

const isPrompt = computed(() => currentDialog.value?.type === 'prompt');
const isConfirm = computed(() => currentDialog.value?.type === 'confirm');
const isAlert = computed(() => currentDialog.value?.type === 'alert');

const promptOptions = computed<PromptOptions | null>(() => {
  return currentDialog.value?.type === 'prompt'
    ? (currentDialog.value.opts as PromptOptions)
    : null;
});

const confirmOptions = computed<ConfirmOptions | null>(() => {
  return currentDialog.value?.type === 'confirm'
    ? (currentDialog.value.opts as ConfirmOptions)
    : null;
});

const alertOptions = computed<AlertOptions | null>(() => {
  return currentDialog.value?.type === 'alert' ? (currentDialog.value.opts as AlertOptions) : null;
});

watch(
  currentDialog,
  async (dialog) => {
    localInput.value =
      dialog?.type === 'prompt' ? ((dialog.opts as PromptOptions).initial ?? '') : '';

    if (!dialog) {
      return;
    }

    await focusTarget();
  },
  { immediate: true },
);

const resolveButtonTheme = (
  color?: ConfirmOptions['confirmColor'],
  fallback: ButtonColor = 'primary',
): ButtonTheme => {
  const map: Record<string, ButtonColor> = {
    'is-danger': 'error',
    'is-primary': 'primary',
    'is-link': 'secondary',
    'is-info': 'info',
    'is-success': 'success',
    'is-warning': 'warning',
    'is-light': 'neutral',
    'is-dark': 'neutral',
    'is-white': 'neutral',
    primary: 'primary',
    secondary: 'secondary',
    success: 'success',
    info: 'info',
    warning: 'warning',
    error: 'error',
    neutral: 'neutral',
  };

  const resolved = color ? (map[color] ?? fallback) : fallback;

  if ('neutral' === resolved) {
    return { color: 'neutral', variant: 'outline' };
  }

  return { color: resolved, variant: 'solid' };
};

const primaryButtonTheme = computed(() => {
  if (isAlert.value) {
    return resolveButtonTheme(alertOptions.value?.confirmColor, 'error');
  }

  if (isPrompt.value) {
    return resolveButtonTheme(promptOptions.value?.confirmColor);
  }

  return resolveButtonTheme(confirmOptions.value?.confirmColor);
});

const isPromptUnchanged = computed(() => {
  if (!isPrompt.value) {
    return false;
  }

  return localInput.value === (promptOptions.value?.initial ?? '');
});

const onCancel = () => cancel();
const onEnter = () => confirm(localInput.value);

const confirmText = computed(() => {
  if (isPrompt.value) {
    return promptOptions.value?.confirmText ?? 'OK';
  }

  if (isConfirm.value) {
    return confirmOptions.value?.confirmText ?? 'OK';
  }

  return alertOptions.value?.confirmText ?? 'OK';
});

const cancelText = computed(() => {
  if (isPrompt.value) {
    return promptOptions.value?.cancelText ?? 'Cancel';
  }

  return confirmOptions.value?.cancelText ?? 'Cancel';
});

const dialogMessage = computed(() => {
  if (isPrompt.value) {
    return promptOptions.value?.message ?? '';
  }

  if (isConfirm.value) {
    return confirmOptions.value?.message ?? '';
  }

  return alertOptions.value?.message ?? '';
});

const defaultTitle = computed(() => {
  if (!currentDialog.value) {
    return '';
  }

  switch (currentDialog.value.type) {
    case 'alert':
      return 'Alert';
    case 'confirm':
      return 'Confirm';
    case 'prompt':
      return 'Input required';
    default:
      return 'Dialog';
  }
});

const dialogTitle = computed(() => {
  if (isPrompt.value) {
    return promptOptions.value?.title ?? defaultTitle.value;
  }

  if (isConfirm.value) {
    return confirmOptions.value?.title ?? defaultTitle.value;
  }

  return alertOptions.value?.title ?? defaultTitle.value;
});

async function focusTarget(): Promise<void> {
  await nextTick();

  requestAnimationFrame(() => {
    if (isPrompt.value) {
      inputEl.value?.inputRef?.focus({ preventScroll: true });
      return;
    }

    const button = document.querySelector<HTMLButtonElement>('[data-dialog-primary="true"]');
    button?.focus();
  });
}
</script>
