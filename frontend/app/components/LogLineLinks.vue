<template>
  <span v-if="items.length > 0" class="inline-flex max-w-full items-center gap-1 align-middle">
    <button
      v-for="entry in visibleItems"
      :key="entry.key"
      type="button"
      :title="entry.label"
      class="inline-flex h-5 items-center gap-1 rounded px-1.5 text-[11px] font-medium text-primary transition hover:bg-primary/10 hover:text-primary"
      @click="void entry.action()"
    >
      <UIcon :name="entry.icon" class="size-3.5 shrink-0" />
      <span v-if="!compact" class="truncate">{{ entry.shortLabel ?? entry.label }}</span>
    </button>

    <Popover
      v-if="!compact && overflowItems.length > 0"
      placement="bottom-start"
      trigger="click"
      :offset="6"
      :show-arrow="false"
      :z-index="13000"
      popover-class="w-56"
      content-class="p-1"
    >
      <template #trigger>
        <UButton
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-ellipsis"
          aria-label="Open more related links"
          title="Open more related links"
          class="h-5 cursor-pointer rounded px-1.5 text-primary hover:bg-primary/10 hover:text-primary"
          :ui="{
            base: 'min-h-0 min-w-0',
            leadingIcon: 'size-3.5',
          }"
        />
      </template>

      <template #content="{ hide }">
        <div class="space-y-1">
          <button
            v-for="entry in overflowItems"
            :key="entry.key"
            type="button"
            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
            @click="void onSelect(entry, hide)"
          >
            <UIcon :name="entry.icon" class="size-4 shrink-0 text-toned" />
            <span class="truncate">{{ entry.label }}</span>
          </button>
        </div>
      </template>
    </Popover>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { navigateTo } from '#app';
import { useStorage } from '@vueuse/core';
import Popover from '~/components/Popover.vue';
import { useDialog } from '~/composables/useDialog';
import type { LogEntry } from '~/types';
import { goto_history_item, makeEventName } from '~/utils';

type LinkItem = {
  key: string;
  label: string;
  shortLabel?: string;
  icon: string;
  action: () => Promise<void>;
};

const props = defineProps<{
  item: Pick<LogEntry, 'item_id' | 'event_id' | 'user' | 'backend'>;
  openEvent?: (id: string) => void;
  compact?: boolean;
}>();

const dialog = useDialog();
const api_user = useStorage('api_user', 'main');

const asString = (value: unknown): string | null => {
  if ('string' === typeof value) {
    return '' === value ? null : value;
  }

  if ('number' === typeof value || 'boolean' === typeof value) {
    return String(value);
  }

  return null;
};

const eventId = computed<string | null>(() => asString(props.item.event_id));
const itemId = computed<string | null>(() => asString(props.item.item_id));
const backend = computed<string | null>(() => asString(props.item.backend));
const user = computed<string | null>(() => asString(props.item.user));

const switchIdentity = async (identity: string): Promise<boolean> => {
  if (identity === api_user.value) {
    return true;
  }

  const { status } = await dialog.confirmDialog({
    title: 'Switch Identity',
    message: `This log is related to identity '${identity}'. You are currently using '${api_user.value}'. Do you want to switch to view it?`,
  });

  if (true !== status) {
    return false;
  }

  api_user.value = identity;
  return true;
};

const items = computed<Array<LinkItem>>(() => {
  const list: Array<LinkItem> = [];

  if (eventId.value) {
    list.push({
      key: `event:${eventId.value}`,
      label: `Event #${makeEventName(eventId.value)}`,
      shortLabel: `#${makeEventName(eventId.value)}`,
      icon: 'i-lucide-activity',
      action: async () => {
        if (props.openEvent) {
          props.openEvent(eventId.value as string);
          return;
        }

        await navigateTo({
          path: '/events',
          query: { view: eventId.value },
        });
      },
    });
  }

  if (itemId.value) {
    list.push({
      key: `item:${itemId.value}`,
      label: `History #${itemId.value}`,
      shortLabel: `#${itemId.value}`,
      icon: 'i-lucide-history',
      action: async () => {
        await goto_history_item({ item_id: itemId.value, user: user.value });
      },
    });
  }

  if (backend.value) {
    list.push({
      key: `backend:${backend.value}`,
      label: `Backend ${backend.value}`,
      shortLabel: backend.value,
      icon: 'i-lucide-server',
      action: async () => {
        const identity = user.value;
        if (identity && false === (await switchIdentity(identity))) {
          return;
        }

        await navigateTo(`/backend/${backend.value}`);
      },
    });
  }

  if (user.value) {
    list.push({
      key: `identity:${user.value}`,
      label: `Use identity ${user.value}`,
      shortLabel: user.value,
      icon: 'i-lucide-user-round',
      action: async () => {
        await switchIdentity(user.value as string);
      },
    });
  }

  return list;
});

const compact = computed<boolean>(() => true === props.compact);

const visibleItems = computed<Array<LinkItem>>(() => {
  return items.value.slice(0, compact.value ? 0 : items.value.length);
});

const overflowItems = computed<Array<LinkItem>>(() => {
  if (!compact.value) {
    return [];
  }

  return items.value.slice(3);
});

const onSelect = async (entry: LinkItem, hide: () => void): Promise<void> => {
  hide();
  await entry.action();
};
</script>
