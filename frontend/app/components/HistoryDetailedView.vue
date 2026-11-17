<template>
  <section class="ws-card overflow-hidden" aria-label="History inspection">
    <Lazy
      v-for="item in items"
      :key="`${user}:${item.id}`"
      :unrender="true"
      :min-height="152"
      class="border-b border-default last:border-b-0"
    >
      <article class="flex items-start gap-3 p-4">
        <HistoryPoster
          v-if="posterEnable"
          preview
          :history-id="item.id"
          :title="item.title"
          :user="user"
          class="hidden w-16 shrink-0 sm:block"
        />
        <div class="min-w-0 flex-1 space-y-3">
          <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="font-mono text-xs text-dimmed">#{{ item.id }}</span>
                <UBadge :color="item.watched ? 'success' : 'neutral'" variant="soft" size="xs">
                  {{
                    item.watched
                      ? 'Watched'
                      : 0 < Number(item.progress)
                        ? 'In progress'
                        : 'Unwatched'
                  }}
                </UBadge>
                <span class="inline-flex items-center gap-1 text-xs text-toned">
                  <UIcon
                    :name="'episode' === item.type ? 'i-lucide-tv' : 'i-lucide-film'"
                    class="size-3.5"
                  />
                  {{ 'episode' === item.type ? 'Episode' : 'Movie' }}
                </span>
              </div>
              <NuxtLink
                :to="`/history/${item.id}`"
                :title="item.full_title || item.title"
                class="mt-1 line-clamp-2 text-sm font-semibold text-highlighted hover:text-primary"
              >
                {{ item.full_title || makeName(item as unknown as JsonObject) }}
              </NuxtLink>
            </div>
            <UCheckbox
              color="primary"
              class="mt-1 shrink-0"
              :model-value="selectedIds.includes(item.id)"
              :disabled="selectionDisabled"
              :aria-label="`Select ${item.full_title || item.title}`"
              @update:model-value="emit('select', item.id, $event)"
            />
          </div>

          <dl
            v-if="item.content_title || item.content_path"
            class="space-y-1 rounded-md bg-muted/50 px-3 py-2 text-xs"
          >
            <div v-if="item.content_title" class="flex items-start gap-2">
              <dt class="w-12 shrink-0 py-1 text-dimmed">Subtitle</dt>
              <dd class="flex min-w-0 flex-1 items-start gap-1">
                <NuxtLink
                  :to="makeSearchLink('subtitle', item.content_title)"
                  :title="item.content_title"
                  class="min-w-0 flex-1 py-1 text-toned hover:text-primary"
                  :class="item.expand_title ? 'wrap-break-word' : 'truncate'"
                >
                  {{ item.content_title }}
                </NuxtLink>
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :icon="item.expand_title ? 'i-lucide-minimize-2' : 'i-lucide-maximize-2'"
                  :aria-label="`${item.expand_title ? 'Collapse' : 'Expand'} subtitle for ${item.title}`"
                  :title="item.expand_title ? 'Collapse subtitle' : 'Expand subtitle'"
                  :aria-expanded="!!item.expand_title"
                  @click="emit('expand', item.id, 'expand_title')"
                />
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-copy"
                  :aria-label="`Copy subtitle for ${item.title}`"
                  title="Copy subtitle"
                  @click="() => void copyText(item.content_title ?? '', false)"
                />
              </dd>
            </div>
            <div v-if="item.content_path" class="flex items-start gap-2">
              <dt class="w-12 shrink-0 py-1 text-dimmed">Path</dt>
              <dd class="flex min-w-0 flex-1 items-start gap-1">
                <NuxtLink
                  :to="makeSearchLink('path', item.content_path)"
                  :title="item.content_path"
                  class="min-w-0 flex-1 py-1 font-mono text-toned hover:text-primary"
                  :class="item.expand_path ? 'wrap-break-word' : 'truncate'"
                >
                  {{ item.content_path }}
                </NuxtLink>
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :icon="item.expand_path ? 'i-lucide-minimize-2' : 'i-lucide-maximize-2'"
                  :aria-label="`${item.expand_path ? 'Collapse' : 'Expand'} path for ${item.title}`"
                  :title="item.expand_path ? 'Collapse path' : 'Expand path'"
                  :aria-expanded="!!item.expand_path"
                  @click="emit('expand', item.id, 'expand_path')"
                />
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-copy"
                  :aria-label="`Copy file path for ${item.title}`"
                  title="Copy file path"
                  @click="() => void copyText(item.content_path ?? '', false)"
                />
              </dd>
            </div>
          </dl>

          <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-toned">
            <UTooltip
              :text="`Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`"
            >
              <span class="inline-flex cursor-help items-center gap-1.5">
                <UIcon name="i-lucide-clock" class="size-3.5" />
                {{ moment.unix(item.updated_at).fromNow() }}
              </span>
            </UTooltip>
            <span class="inline-flex items-center gap-1.5">
              <UIcon name="i-lucide-server" class="size-3.5" />
              <NuxtLink :to="`/backend/${item.via}`" class="hover:text-primary">
                {{ item.via }}
              </NuxtLink>
              <UTooltip
                v-if="item.metadata && 1 < Object.keys(item.metadata).length"
                :text="`Also reported by: ${Object.keys(item.metadata)
                  .filter((backend) => item.via !== backend)
                  .join(', ')}.`"
              >
                <span class="cursor-help">(+{{ Object.keys(item.metadata).length - 1 }})</span>
              </UTooltip>
            </span>
            <span class="inline-flex items-center gap-1.5">
              <UIcon name="i-lucide-mail" class="size-3.5" />
              <span>Event: {{ item.event ?? '-' }}</span>
            </span>
            <span v-if="item.progress" class="inline-flex items-center gap-1.5 tabular-nums">
              <UIcon name="i-lucide-gauge" class="size-3.5" />
              <span>Position: {{ formatDuration(Number(item.progress)) }}</span>
            </span>
          </div>
        </div>
      </article>
    </Lazy>
  </section>
</template>

<script setup lang="ts">
import moment from 'moment';
import { NuxtLink, UBadge, UButton, UCheckbox, UIcon, UTooltip } from '#components';
import HistoryPoster from '~/components/StatisticsPoster.vue';
import Lazy from '~/components/Lazy.vue';
import { copyText, formatDuration, makeName, makeSearchLink, TOOLTIP_DATE_FORMAT } from '~/utils';
import type { HistoryViewProps, JsonObject } from '~/types';

withDefaults(defineProps<HistoryViewProps & { selectionDisabled?: boolean }>(), {
  selectionDisabled: false,
});

const emit = defineEmits<{
  select: [id: number, value: boolean | 'indeterminate'];
  expand: [id: number, field: 'expand_title' | 'expand_path'];
}>();
</script>
