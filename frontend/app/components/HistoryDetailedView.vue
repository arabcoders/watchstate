<template>
  <div class="grid gap-4 xl:grid-cols-2">
    <Lazy
      v-for="item in items"
      :key="`${user}:${item.id}`"
      :unrender="true"
      :min-height="260"
      class="min-h-65"
    >
      <UCard
        class="h-full shadow-sm"
        :class="item.watched ? 'ring-1 ring-success/20' : ''"
        :ui="historyCardUi"
      >
        <template #header>
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <div
                class="flex min-w-0 items-start gap-2 text-base font-semibold leading-6 text-highlighted"
              >
                <UIcon
                  :name="'episode' === item.type ? 'i-lucide-tv' : 'i-lucide-film'"
                  class="mt-0.5 size-4 shrink-0 text-toned"
                />

                <div class="min-w-0 flex-1">
                  <FloatingImage v-if="posterEnable" :image="`/history/${item.id}/images/poster`">
                    <UTooltip
                      :text="String(item.full_title || makeName(item as unknown as JsonObject))"
                    >
                      <NuxtLink
                        :to="`/history/${item.id}`"
                        class="block truncate text-highlighted hover:text-primary"
                      >
                        {{ item.full_title || makeName(item as unknown as JsonObject) }}
                      </NuxtLink>
                    </UTooltip>
                  </FloatingImage>

                  <UTooltip
                    v-else
                    :text="String(item.full_title || makeName(item as unknown as JsonObject))"
                  >
                    <NuxtLink
                      :to="`/history/${item.id}`"
                      class="block truncate text-highlighted hover:text-primary"
                    >
                      {{ item.full_title || makeName(item as unknown as JsonObject) }}
                    </NuxtLink>
                  </UTooltip>
                </div>
              </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
              <UTooltip :text="selectedIds.includes(item.id) ? 'Unselect item' : 'Select item'">
                <UCheckbox
                  color="primary"
                  :model-value="selectedIds.includes(item.id)"
                  @update:model-value="emit('select', item.id, $event)"
                />
              </UTooltip>
            </div>
          </div>
        </template>

        <div class="space-y-3">
          <div
            v-if="item.content_title"
            class="flex items-start justify-between gap-3 rounded-md border border-default bg-elevated/40 px-3 py-2.5"
          >
            <div
              class="flex min-w-0 flex-1 cursor-pointer items-start gap-2 text-sm font-medium text-default"
              @click="emit('expand', item.id, 'expand_title')"
            >
              <UIcon name="i-lucide-heading" class="mt-0.5 size-4 shrink-0 text-toned" />
              <NuxtLink
                :to="makeSearchLink('subtitle', item.content_title ?? '')"
                class="min-w-0 hover:text-primary"
                :class="item.expand_title ? 'wrap-break-word' : 'truncate'"
              >
                {{ item.content_title }}
              </NuxtLink>
            </div>

            <UTooltip text="Copy subtitle">
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                square
                icon="i-lucide-copy"
                aria-label="Copy subtitle"
                @click="() => void copyText(item.content_title ?? '', false)"
              />
            </UTooltip>
          </div>

          <div
            v-if="item.content_path"
            class="flex items-start justify-between gap-3 rounded-md border border-default bg-elevated/40 px-3 py-2.5"
          >
            <div
              class="flex min-w-0 flex-1 cursor-pointer items-start gap-2 text-sm font-medium text-default"
              @click="emit('expand', item.id, 'expand_path')"
            >
              <UIcon name="i-lucide-file-text" class="mt-0.5 size-4 shrink-0 text-toned" />
              <NuxtLink
                :to="makeSearchLink('path', item.content_path ?? '')"
                class="min-w-0 hover:text-primary"
                :class="item.expand_path ? 'wrap-break-word' : 'truncate'"
              >
                {{ item.content_path }}
              </NuxtLink>
            </div>

            <UTooltip text="Copy file path">
              <UButton
                color="neutral"
                variant="ghost"
                size="sm"
                square
                icon="i-lucide-copy"
                aria-label="Copy file path"
                @click="() => void copyText(item.content_path ?? '', false)"
              />
            </UTooltip>
          </div>
        </div>

        <template #footer>
          <div
            :class="[
              'grid grid-cols-2 gap-2.5',
              item.progress ? 'xl:grid-cols-4' : 'xl:grid-cols-3',
            ]"
          >
            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-calendar" class="size-4 shrink-0 text-toned" />
              <UTooltip
                :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
              >
                <span class="cursor-help">{{ moment.unix(item.updated_at).fromNow() }}</span>
              </UTooltip>
            </div>

            <div
              class="flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default"
            >
              <UIcon name="i-lucide-server" class="size-4 shrink-0 text-toned" />
              <div>
                <NuxtLink :to="`/backend/${item.via}`" class="hover:text-primary">{{
                  item.via
                }}</NuxtLink>
                <UTooltip
                  v-if="item.metadata && Object.keys(item.metadata).length > 1"
                  :text="`Also reported by: ${Object.keys(item.metadata)
                    .filter((i) => i !== item.via)
                    .join(', ')}.`"
                >
                  <span class="ml-1 cursor-help text-toned">
                    (+{{ Object.keys(item.metadata).length - 1 }})
                  </span>
                </UTooltip>
              </div>
            </div>

            <div
              :class="[
                'flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default',
                item.updated_at && !item.progress ? 'col-span-2 xl:col-span-1' : '',
              ]"
            >
              <UIcon name="i-lucide-mail" class="size-4 shrink-0 text-toned" />
              <span>{{ item.event ?? '-' }}</span>
            </div>

            <div
              v-if="item.progress"
              :class="[
                'flex items-center justify-center gap-2 rounded-md border border-default bg-elevated/40 px-3 py-2 text-center text-sm font-medium text-default',
                !item.updated_at ? 'col-span-2 xl:col-span-1' : '',
              ]"
            >
              <UIcon name="i-lucide-gauge" class="size-4 shrink-0 text-toned" />
              <span>{{ formatDuration(item.progress as number) }}</span>
            </div>
          </div>
        </template>
      </UCard>
    </Lazy>
  </div>
</template>

<script setup lang="ts">
import moment from 'moment';
import { NuxtLink, UButton, UCard, UCheckbox, UIcon, UTooltip } from '#components';
import FloatingImage from '~/components/FloatingImage.vue';
import Lazy from '~/components/Lazy.vue';
import { copyText, formatDuration, makeName, makeSearchLink, TOOLTIP_DATE_FORMAT } from '~/utils';
import type { HistoryViewProps, JsonObject } from '~/types';

defineProps<HistoryViewProps>();

const emit = defineEmits<{
  select: [id: number, value: boolean | 'indeterminate'];
  expand: [id: number, field: 'expand_title' | 'expand_path'];
}>();

const historyCardUi = {
  header: 'p-4 sm:p-5',
  body: 'px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
  footer: 'sm:p-6 px-4 pb-4 pt-0 sm:px-5 sm:pb-5',
};
</script>
