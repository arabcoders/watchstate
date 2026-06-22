<template>
  <div class="ws-card bg-elevated/40 p-3">
    <div class="flex items-center justify-between gap-3">
      <div class="min-w-0 space-y-1">
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-toned">{{ label }}</p>
        <UTooltip v-if="tooltip" :text="tooltip">
          <div
            :class="valueWrap ? 'space-y-1 cursor-help' : 'flex cursor-help items-baseline gap-1.5'"
          >
            <span class="text-sm font-semibold text-highlighted">{{ value }}</span>
            <span v-if="hint" class="truncate text-xs text-toned">{{ hint }}</span>
          </div>
        </UTooltip>
        <div v-else :class="valueWrap ? 'space-y-1' : 'flex items-baseline gap-1.5'">
          <span
            :class="[
              'text-sm font-semibold text-highlighted',
              valueWrap ? 'block wrap-break-word' : '',
            ]"
          >
            {{ value }}
          </span>
          <span v-if="hint" class="truncate text-xs text-toned">{{ hint }}</span>
        </div>
      </div>
      <span
        :class="[
          'flex size-8 shrink-0 items-center justify-center rounded-md ring-1 ring-inset',
          iconTileClass,
        ]"
      >
        <UIcon :name="icon" :class="['size-4', iconTextClass]" />
      </span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

type Color = 'primary' | 'success' | 'error' | 'warning' | 'info' | 'neutral';

const props = withDefaults(
  defineProps<{
    label: string;
    value: string | number;
    icon: string;
    hint?: string;
    tooltip?: string;
    color?: Color;
    valueWrap?: boolean;
  }>(),
  { color: 'primary', hint: '', tooltip: '', valueWrap: false },
);

const TILE_BG: Record<Color, string> = {
  primary: 'bg-elevated',
  success: 'bg-success/10',
  error: 'bg-error/10',
  warning: 'bg-warning/10',
  info: 'bg-info/10',
  neutral: 'bg-elevated',
};

const TILE_RING: Record<Color, string> = {
  primary: 'ring-default',
  success: 'ring-success/30',
  error: 'ring-error/30',
  warning: 'ring-warning/30',
  info: 'ring-info/30',
  neutral: 'ring-default',
};

const TILE_TEXT: Record<Color, string> = {
  primary: 'text-primary',
  success: 'text-success',
  error: 'text-error',
  warning: 'text-warning',
  info: 'text-info',
  neutral: 'text-toned',
};

const iconTileClass = computed(() => `${TILE_BG[props.color]} ${TILE_RING[props.color]}`);
const iconTextClass = computed(() => TILE_TEXT[props.color]);
</script>
