import { ref } from 'vue';

// Global state to manage popovers
const activePopover = ref<string | null>(null);

export const usePopoverManager = () => {
  const register = (id: string): boolean => {
    if (activePopover.value && activePopover.value !== id) {
      // Close any existing popover
      return false;
    }
    activePopover.value = id;
    return true;
  };

  const unregister = (id: string): void => {
    if (activePopover.value === id) {
      activePopover.value = null;
    }
  };

  const closeAll = (): void => {
    activePopover.value = null;
  };

  const isActive = (id: string): boolean => {
    return activePopover.value === id;
  };

  return {
    register,
    unregister,
    closeAll,
    isActive,
    activePopover: activePopover.value,
  };
};
