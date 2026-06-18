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
import { navigateTo } from '#app';
import { useStorage } from '@vueuse/core';
import { computed } from 'vue';
import { useDialog } from '~/composables/useDialog';
import type { ServerJsonLogEntry } from '~/types';
import { goto_history_item, makeEventName } from '~/utils';
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

const apiUser = useStorage('api_user', 'main');
const dialog = useDialog();

const asString = (value: unknown): string | null => {
  if ('string' === typeof value) {
    const trimmed = value.trim();
    return '' === trimmed ? null : trimmed;
  }

  if ('number' === typeof value || 'boolean' === typeof value) {
    return String(value);
  }

  return null;
};

const fieldValue = (path: string): unknown => {
  const fields = props.log.fields;
  if (!fields) {
    return null;
  }

  if (path in fields) {
    return fields[path];
  }

  let cursor: unknown = fields;
  for (const segment of path.split('.')) {
    if (!cursor || Array.isArray(cursor) || 'object' !== typeof cursor || !(segment in cursor)) {
      return null;
    }

    cursor = (cursor as Record<string, unknown>)[segment];
  }

  return cursor;
};

const fieldString = (path: string): string | null => asString(fieldValue(path));

const eventId = computed<string | null>(() => fieldString('event.id') ?? fieldString('event_id'));
const historyId = computed<string | null>(() => fieldString('history.id'));
const identityUser = computed<string | null>(() => fieldString('identity.user'));
const identityBackend = computed<string | null>(() => fieldString('identity.backend'));

const switchIdentity = async (identity: string): Promise<boolean> => {
  if (identity === apiUser.value) {
    return true;
  }

  const { status } = await dialog.confirmDialog({
    title: 'Switch Identity',
    message: `This log is related to identity '${identity}'. You are currently using '${apiUser.value}'. Do you want to switch to view it?`,
  });

  if (true !== status) {
    return false;
  }

  apiUser.value = identity;
  return true;
};

type MenuItem = {
  label: string;
  icon: string;
  onSelect: () => void | Promise<void>;
};

const menuItems = computed<Array<Array<MenuItem>>>(() => {
  const group: Array<MenuItem> = [
    {
      label: 'Log details',
      icon: 'i-lucide-panel-right-open',
      onSelect: () => emit('details', props.log),
    },
  ];

  const event = eventId.value;
  if (event) {
    group.push({
      label: `Event #${makeEventName(event)}`,
      icon: 'i-lucide-activity',
      onSelect: () => emit('openEvent', event),
    });
  }

  const history = historyId.value;
  if (history) {
    group.push({
      label: `History #${history}`,
      icon: 'i-lucide-history',
      onSelect: async () => {
        await goto_history_item({ history_id: history, user: identityUser.value });
      },
    });
  }

  const backend = identityBackend.value;
  if (backend) {
    group.push({
      label: `Backend ${backend}`,
      icon: 'i-lucide-server',
      onSelect: async () => {
        const user = identityUser.value;
        if (user && false === (await switchIdentity(user))) {
          return;
        }

        await navigateTo(`/backend/${backend}`);
      },
    });
  }

  return [group];
});
</script>
