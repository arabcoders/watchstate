<template>
  <span class="inline-flex max-w-full flex-wrap items-center gap-x-2 gap-y-1 align-middle">
    <UTooltip v-if="log.datetime" :text="logTimeTitle(log.datetime)">
      <span
        :class="
          compact
            ? 'text-[10px] font-semibold text-toned'
            : 'inline cursor-pointer text-[11px] font-semibold text-toned'
        "
      >
        {{ logTimeLabel(log.datetime) }}
      </span>
    </UTooltip>

    <UButton
      v-if="showDetails"
      color="neutral"
      variant="ghost"
      size="xs"
      icon="i-lucide-panel-right-open"
      aria-label="Open log details"
      class="inline-flex align-[-0.2em] opacity-70 hover:opacity-100"
      @click="$emit('details', log)"
    />

    <span
      :class="[logLevelBadgeClass(getLogLevel(log.level)), compact ? 'w-16! text-[9px]' : '']"
      @click="showDetails ? $emit('details', log) : undefined"
    >
      <UIcon :name="LOG_LEVEL_ICON[getLogLevel(log.level)]" class="size-3" />
      {{ getLogLevel(log.level) }}
    </span>

    <span
      v-if="log.logger"
      :title="log.logger"
      class="inline-block max-w-[46vw] truncate align-middle text-[11px] font-semibold text-toned sm:max-w-104"
    >
      [{{ log.logger }}]
    </span>
  </span>

  <span :class="compact ? 'ml-1' : 'ml-2'">{{ log.message }}</span>
</template>

<script setup lang="ts">
import type { ServerJsonLogEntry } from '~/types';
import {
  getLogLevel,
  LOG_LEVEL_ICON,
  logLevelBadgeClass,
  logTimeLabel,
  logTimeTitle,
} from '~/utils/logs';

defineProps<{
  log: ServerJsonLogEntry;
  showDetails?: boolean;
  compact?: boolean;
}>();

defineEmits<{
  details: [entry: ServerJsonLogEntry];
}>();
</script>
