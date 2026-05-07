import { useState } from '#app';

export function usePlayerShortcutHelp() {
  return useState<boolean>('player-shortcut-help', () => false);
}
