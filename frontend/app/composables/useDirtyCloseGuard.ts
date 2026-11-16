import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router';
import { computed, onBeforeUnmount, toValue, type MaybeRefOrGetter, type Ref } from 'vue';
import { useDialog } from '~/composables/useDialog';

type DirtyCloseGuardOptions = {
  dirty: MaybeRefOrGetter<boolean>;
  title?: string;
  message?: string;
  confirmText?: string;
  cancelText?: string;
  confirmColor?: 'primary' | 'success' | 'info' | 'warning' | 'error' | 'neutral';
  onDiscard?: () => void | Promise<void>;
};

export const useDirtyCloseGuard = (open: Ref<boolean>, options: DirtyCloseGuardOptions) => {
  const dialog = useDialog();
  let pendingCloseRequest: Promise<boolean> | null = null;

  const isDirty = computed<boolean>(() => Boolean(toValue(options.dirty)));
  const shouldGuard = computed<boolean>(() => Boolean(open.value) && true === isDirty.value);

  const confirmClose = async (): Promise<boolean> => {
    if (false === isDirty.value) {
      open.value = false;
      return true;
    }

    const { status } = await dialog.confirmDialog({
      title: options.title ?? 'Discard changes?',
      message: options.message ?? 'Unsaved changes will be lost.',
      confirmText: options.confirmText ?? 'Discard changes',
      cancelText: options.cancelText ?? 'Keep editing',
      confirmColor: options.confirmColor ?? 'warning',
    });

    if (true !== status) {
      return false;
    }

    await options.onDiscard?.();
    open.value = false;
    return true;
  };

  const requestClose = async (): Promise<boolean> => {
    if (pendingCloseRequest) {
      return pendingCloseRequest;
    }

    pendingCloseRequest = confirmClose().finally(() => {
      pendingCloseRequest = null;
    });

    return pendingCloseRequest;
  };

  const handleOpenChange = async (value: boolean): Promise<void> => {
    if (true === value) {
      open.value = true;
      return;
    }

    await requestClose();
  };

  onBeforeRouteLeave(async () => {
    if (false === shouldGuard.value) {
      return;
    }

    return requestClose();
  });

  onBeforeRouteUpdate(async () => {
    if (false === shouldGuard.value) {
      return;
    }

    return requestClose();
  });

  if ('undefined' !== typeof window) {
    const handleBeforeUnload = (event: BeforeUnloadEvent): void => {
      if (false === shouldGuard.value) {
        return;
      }

      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', handleBeforeUnload);

    onBeforeUnmount(() => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
    });
  }

  return {
    isDirty,
    requestClose,
    handleOpenChange,
  };
};
