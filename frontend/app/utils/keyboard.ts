export const clampMediaTime = (media: HTMLMediaElement, nextTime: number) => {
  const duration =
    Number.isFinite(media.duration) && media.duration > 0 ? media.duration : Infinity;
  media.currentTime = Math.min(Math.max(nextTime, 0), duration);
};

export const clampMediaVolume = (volume: number) => {
  return Math.min(1, Math.max(0, volume));
};

export const hasModifierKey = (event: KeyboardEvent): boolean =>
  event.ctrlKey || event.metaKey || event.altKey;

export const shouldHandleKeyboardShortcut = (event: KeyboardEvent): boolean => {
  const target = event.target as HTMLElement | null;
  const tagName = target?.tagName?.toLowerCase();

  if (
    'input' === tagName ||
    'textarea' === tagName ||
    'true' === target?.contentEditable ||
    'true' === target?.getAttribute('contenteditable')
  ) {
    return false;
  }

  return true;
};
