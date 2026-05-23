<template>
  <UTooltip v-if="menuItems.length < 1" text="View details">
    <button
      type="button"
      class="inline-flex h-5 items-center gap-1 rounded px-1.5 text-[11px] font-medium text-primary transition hover:bg-primary/10 hover:text-primary"
      @click="props.openDetails(props.item)"
    >
      <UIcon name="i-lucide-panel-right-open" class="size-3.5 shrink-0" />
      <span>View</span>
    </button>
  </UTooltip>

  <Popover
    v-else
    placement="bottom-start"
    trigger="click"
    :offset="6"
    :show-arrow="false"
    :z-index="13000"
    popover-class="w-60"
    content-class="p-1"
  >
    <template #trigger>
      <UTooltip text="Extended view">
        <button
          type="button"
          class="inline-flex h-5 items-center gap-1 rounded px-1.5 text-[11px] font-medium text-primary transition hover:bg-primary/10 hover:text-primary"
        >
          <UIcon name="i-lucide-panel-right-open" class="size-3.5 shrink-0" />
          <span>View</span>
        </button>
      </UTooltip>
    </template>

    <template #content="{ hide }">
      <div class="space-y-1">
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-default hover:bg-elevated hover:text-highlighted"
          @click="onOpenDetails(hide)"
        >
          <UIcon name="i-lucide-panel-right-open" class="size-4 shrink-0 text-toned" />
          <span class="truncate">View details</span>
        </button>

        <button
          v-for="entry in menuItems"
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
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useStorage } from '@vueuse/core';
import Popover from '~/components/Popover.vue';
import { useDialog } from '~/composables/useDialog';
import type { LogEntry } from '~/types';
import { goto_history_item, makeEventName } from '~/utils';
import { navigateTo } from '#app';

type ActionItem = {
  key: string;
  label: string;
  icon: string;
  action: () => Promise<void>;
};

const props = defineProps<{
  item: LogEntry;
  openDetails: (item: LogEntry) => void;
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

const menuItems = computed<Array<ActionItem>>(() => {
  const list: Array<ActionItem> = [];
  const user = asString(props.item.user);

  if (stateId.value) {
    list.push({
      key: `item:${stateId.value}`,
      label: `View item #${stateId.value}`,
      icon: 'i-lucide-history',
      action: async () => {
        await goto_history_item({ state_id: stateId.value, user });
      },
    });
  }

  if (eventId.value) {
    const currentEventId = eventId.value;

    list.push({
      key: `event:${currentEventId}`,
      label: `View event #${makeEventName(currentEventId)}`,
      icon: 'i-lucide-activity',
      action: async () => {
        if (props.openEvent) {
          props.openEvent(currentEventId);
          return;
        }

        await navigateTo({
          path: '/events',
          query: { view: currentEventId },
        });
      },
    });
  }

  if (backend.value) {
    list.push({
      key: `backend:${backend.value}`,
      label: `View backend ${backend.value}`,
      icon: 'i-lucide-server',
      action: async () => {
        if (user && false === (await switchIdentity(user))) {
          return;
        }

        await navigateTo(`/backend/${backend.value}`);
      },
    });
  }

  return list;
});

const stateId = computed<string | null>(() => asString(props.item.state_id));
const eventId = computed<string | null>(() => asString(props.item.event_id));
const backend = computed<string | null>(() => asString(props.item.backend));

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

const onOpenDetails = (hide: () => void): void => {
  hide();
  props.openDetails(props.item);
};

const onSelect = async (entry: ActionItem, hide: () => void): Promise<void> => {
  hide();
  await entry.action();
};
</script>
