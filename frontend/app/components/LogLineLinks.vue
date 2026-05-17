<template>
  <span v-if="items.length > 0" class="inline-flex items-center align-middle">
    <Popover
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
          icon="i-lucide-link-2"
          aria-label="Open related links"
          title="Open related links"
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
            v-for="entry in items"
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
  icon: string;
  action: () => Promise<void>;
};

const props = defineProps<{
  item: Pick<LogEntry, 'item_id' | 'event_id' | 'user' | 'backend'>;
  openEvent?: (id: string) => void;
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

  if (eventId.value && props.openEvent) {
    list.push({
      key: `event:${eventId.value}`,
      label: `Event #${makeEventName(eventId.value)}`,
      icon: 'i-lucide-activity',
      action: async () => {
        props.openEvent?.(eventId.value as string);
      },
    });
  }

  if (itemId.value) {
    list.push({
      key: `item:${itemId.value}`,
      label: `History #${itemId.value}`,
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

  return list;
});

const onSelect = async (entry: LinkItem, hide: () => void): Promise<void> => {
  hide();
  await entry.action();
};
</script>
