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

    <UDropdownMenu
      v-if="showDetails && menuItems.length > 0"
      :items="menuItems"
      :content="{ align: 'start' }"
      :modal="false"
    >
      <UButton
        color="neutral"
        variant="ghost"
        size="xs"
        icon="i-lucide-ellipsis-vertical"
        trailing-icon="i-lucide-chevron-down"
        class="inline-flex h-5! align-[-0.2em] opacity-70 hover:opacity-100"
        :ui="{ base: 'min-h-0 min-w-0', leadingIcon: 'size-3', trailingIcon: 'size-3' }"
      >
        <span class="text-[10px] font-medium">Actions</span>
      </UButton>
    </UDropdownMenu>

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
import { computed } from 'vue';
import type { ServerJsonLogEntry } from '~/types';
import { makeEventName } from '~/utils';
import {
  getLogLevel,
  LOG_LEVEL_ICON,
  logLevelBadgeClass,
  logTimeLabel,
  logTimeTitle,
} from '~/utils/logs';

const props = defineProps<{
  log: ServerJsonLogEntry;
  showDetails?: boolean;
  compact?: boolean;
}>();

const emit = defineEmits<{
  details: [entry: ServerJsonLogEntry];
  openEvent: [eventId: string];
}>();

const eventId = computed<string | null>(() => {
  const fields = props.log.fields;
  if (!fields) {
    return null;
  }

  const fromNested = fields['event.id'];
  if ('string' === typeof fromNested && '' !== fromNested) {
    return fromNested;
  }

  const fromFlat = fields['event_id'];
  if ('string' === typeof fromFlat && '' !== fromFlat) {
    return fromFlat;
  }

  return null;
});

type MenuItem = {
  label: string;
  icon: string;
  onSelect: () => void;
};

const menuItems = computed<Array<Array<MenuItem>>>(() => {
  const group: Array<MenuItem> = [
    {
      label: 'Log details',
      icon: 'i-lucide-panel-right-open',
      onSelect: () => emit('details', props.log),
    },
  ];

  if (eventId.value) {
    group.push({
      label: `Event #${makeEventName(eventId.value)}`,
      icon: 'i-lucide-activity',
      onSelect: () => emit('openEvent', eventId.value as string),
    });
  }

  return [group];
});
</script>
