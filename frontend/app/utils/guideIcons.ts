import { guideIcons as generatedGuideIcons } from '#build/guide-icons';
import type { GuideIcon } from '#build/guide-icons';

const fallbackName = 'circle-question-mark';
export const GUIDE_ICONS_UPDATED_EVENT = 'watchstate:guide-icons-updated';
let guideIcons: Record<string, GuideIcon> = generatedGuideIcons;

if (import.meta.hot) {
  import.meta.hot.accept('#build/guide-icons', (module) => {
    if (!module) {
      return;
    }

    guideIcons = module.guideIcons;
    window.dispatchEvent(new Event(GUIDE_ICONS_UPDATED_EVENT));
  });
}

const resolveGuideIcon = (value: string) => {
  const normalized = value.trim();
  const requestedName = normalized.startsWith('i-lucide-')
    ? normalized.replace(/^i-lucide-/, '')
    : fallbackName;

  return guideIcons[requestedName] ?? guideIcons[fallbackName] ?? null;
};

export const renderGuideIcon = (value: string): string => {
  const icon = resolveGuideIcon(value);
  if (!icon?.body) {
    return '';
  }

  const width = icon.width ?? 24;
  const height = icon.height ?? 24;

  return `<span class="ws-guide-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}">${icon.body}</svg></span>`;
};
