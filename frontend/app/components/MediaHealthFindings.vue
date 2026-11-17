<script setup lang="ts">
import { computed } from 'vue';
import { NuxtLink, UButton, UIcon, UTooltip } from '#components';
import BackendItemLink from '~/components/BackendItemLink.vue';
import HistoryRecordLink from '~/components/HistoryRecordLink.vue';
import type { MediaHealthItemWithUI } from '~/types';
import { copyText, makeSearchLink } from '~/utils';
import { mediaHealthGuidLink } from '~/utils/mediaHealth';

const props = defineProps<{ item: MediaHealthItemWithUI }>();
const emit = defineEmits<{
  (event: 'toggle-file-paths' | 'toggle-duplicate-path'): void;
}>();

const guidConflicts = computed(() =>
  Object.entries(props.item.signals.guid_conflicts ?? {}).filter(
    ([, values]) => 1 < Object.keys(values).length,
  ),
);
const metadataConflicts = computed(() =>
  Object.entries(props.item.signals.metadata_conflicts ?? {}).filter(
    ([, values]) => 1 < Object.keys(values).length,
  ),
);
const missingFiles = computed(
  () =>
    props.item.signals.missing_files ??
    Object.values(props.item.signals.file_checks ?? {}).filter((check) => false === check.status),
);
const missingBackends = computed(() => props.item.signals.missing_backends ?? []);
const duplicateGuid = computed(() => props.item.signals.duplicate_guid);
const duplicateReference = computed(() => props.item.signals.duplicate_reference);
const duplicateGuidIds = computed(() =>
  Array.from(new Set([props.item.state_id, ...(duplicateGuid.value?.state_ids ?? [])])),
);
const duplicateReferenceIds = computed(() =>
  Array.from(new Set([props.item.state_id, ...(duplicateReference.value?.state_ids ?? [])])),
);
const pathSuffixes = computed(() => {
  if ('path_disagreement' !== props.item.status) {
    return [];
  }

  const groups = new Map<string, Array<string>>();
  for (const [backend, suffix] of Object.entries(props.item.signals.path_suffixes ?? {})) {
    if ('' === suffix.trim()) {
      continue;
    }

    groups.set(suffix, [...(groups.get(suffix) ?? []), backend]);
  }

  return 1 < groups.size ? Array.from(groups.entries()) : [];
});

const fallbackReasons = computed(() => {
  let hasPrimaryData = false;

  switch (props.item.status) {
    case 'file_missing':
      hasPrimaryData =
        0 < missingFiles.value.length &&
        missingFiles.value.every((check) => '' !== check.backend && '' !== check.message);
      break;
    case 'guid_conflict':
      hasPrimaryData = 0 < guidConflicts.value.length;
      break;
    case 'duplicate_guid':
      hasPrimaryData =
        !!duplicateGuid.value &&
        '' !== duplicateGuid.value.key &&
        '' !== String(duplicateGuid.value.value) &&
        1 < duplicateGuidIds.value.length;
      break;
    case 'duplicate_reference':
      hasPrimaryData =
        !!duplicateReference.value &&
        '' !== duplicateReference.value.path &&
        1 < duplicateReferenceIds.value.length;
      break;
    case 'metadata_disagreement':
      hasPrimaryData = 0 < metadataConflicts.value.length;
      break;
    case 'partial':
      hasPrimaryData = 0 < missingBackends.value.length;
      break;
    case 'path_disagreement':
      hasPrimaryData = 0 < pathSuffixes.value.length;
      break;
    case 'weak_match':
      hasPrimaryData = undefined !== props.item.signals.guids;
      break;
    case 'healthy':
      hasPrimaryData = true;
      break;
  }

  return hasPrimaryData ? [] : props.item.reasons;
});

const hasFindings = computed(
  () =>
    0 < guidConflicts.value.length ||
    !!duplicateGuid.value ||
    !!duplicateReference.value ||
    0 < metadataConflicts.value.length ||
    0 < missingFiles.value.length ||
    0 < missingBackends.value.length ||
    0 < pathSuffixes.value.length ||
    'weak_match' === props.item.status ||
    0 < fallbackReasons.value.length,
);
</script>

<template>
  <div v-if="hasFindings" class="min-w-0 space-y-3 text-sm">
    <section v-if="0 < missingFiles.length" class="min-w-0 space-y-2">
      <h3
        v-if="'file_missing' !== item.status"
        class="flex items-center gap-2 font-medium text-error"
      >
        <UIcon name="i-lucide-file-x-2" class="size-4 shrink-0 text-toned" />
        <span>File check failures</span>
      </h3>
      <p class="text-toned">WatchState could not confirm that these files exist.</p>
      <div
        v-for="check in missingFiles"
        :key="`${check.backend}-${check.path}`"
        class="min-w-0 space-y-1"
      >
        <div class="flex min-w-0 flex-wrap items-start gap-x-2 gap-y-1">
          <BackendItemLink
            :backend="check.backend"
            :url="item.signals.backend_items?.[check.backend]?.webUrl ?? null"
            color="error"
          />
          <p class="min-w-0 flex-1 basis-48 wrap-anywhere text-default">{{ check.message }}</p>
        </div>
        <div v-if="check.path" class="flex min-w-0 items-start gap-1">
          <UTooltip
            :text="
              item.expand_file_checks ? 'Truncate failed file paths' : 'Wrap failed file paths'
            "
          >
            <UButton
              color="neutral"
              :variant="item.expand_file_checks ? 'soft' : 'outline'"
              size="xs"
              square
              icon="i-lucide-wrap-text"
              :aria-label="
                item.expand_file_checks ? 'Truncate failed file paths' : 'Wrap failed file paths'
              "
              :aria-pressed="item.expand_file_checks"
              class="shrink-0"
              @click="emit('toggle-file-paths')"
            />
          </UTooltip>
          <UTooltip :text="check.path">
            <NuxtLink
              :to="makeSearchLink('path', check.path)"
              class="min-w-0 flex-1 font-mono text-xs text-primary hover:underline"
              :class="item.expand_file_checks ? 'wrap-anywhere' : 'truncate'"
            >
              {{ check.path }}
            </NuxtLink>
          </UTooltip>
          <UTooltip text="Copy file path">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              square
              icon="i-lucide-copy"
              aria-label="Copy file path"
              class="shrink-0"
              @click="copyText(check.path, false)"
            />
          </UTooltip>
        </div>
      </div>
    </section>

    <section v-if="0 < guidConflicts.length" class="min-w-0 space-y-2">
      <h3
        v-if="'guid_conflict' !== item.status"
        class="flex items-center gap-2 font-medium text-error"
      >
        <UIcon name="i-lucide-git-compare-arrows" class="size-4 shrink-0 text-toned" />
        <span>Conflicting external IDs</span>
      </h3>
      <p class="text-toned">Backends report different external IDs for this item.</p>
      <dl class="min-w-0 space-y-2">
        <div
          v-for="[provider, values] in guidConflicts"
          :key="provider"
          class="grid min-w-0 gap-x-3 gap-y-1 sm:grid-cols-[6rem_minmax(0,1fr)]"
        >
          <dt class="font-medium uppercase text-toned">{{ provider.replace(/^guid_/, '') }}</dt>
          <dd class="flex min-w-0 flex-wrap items-start gap-x-3 gap-y-2">
            <template v-for="([value, backends], index) in Object.entries(values)" :key="value">
              <span v-if="0 < index" class="self-center text-toned">vs</span>
              <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1">
                <NuxtLink
                  :to="mediaHealthGuidLink(item, provider, value)"
                  target="_blank"
                  class="min-w-0 wrap-anywhere font-mono text-xs text-primary hover:underline"
                >
                  {{ value }}
                </NuxtLink>
                <span v-if="0 < backends.length" class="text-xs text-toned">on</span>
                <BackendItemLink
                  v-for="backend in backends"
                  :key="backend"
                  :backend="backend"
                  :url="item.signals.backend_items?.[backend]?.webUrl ?? null"
                  color="error"
                />
              </div>
            </template>
          </dd>
        </div>
      </dl>
    </section>

    <section v-if="duplicateGuid" class="min-w-0 space-y-1">
      <h3
        v-if="'duplicate_guid' !== item.status"
        class="flex items-center gap-2 font-medium text-error"
      >
        <UIcon name="i-lucide-fingerprint" class="size-4 shrink-0 text-toned" />
        <span>Shared external ID</span>
      </h3>
      <p class="text-toned">These history records share the same external ID.</p>
      <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
        <NuxtLink
          :to="mediaHealthGuidLink(item, duplicateGuid.key, String(duplicateGuid.value))"
          target="_blank"
          class="min-w-0 wrap-anywhere font-mono text-xs text-primary hover:underline"
        >
          {{ duplicateGuid.key.replace(/^guid_/, '') }}://{{ duplicateGuid.value }}
        </NuxtLink>
        <div class="flex flex-wrap gap-1">
          <HistoryRecordLink
            v-for="stateId in duplicateGuidIds"
            :key="stateId"
            :state-id="stateId"
            :current-state-id="item.state_id"
            user="main"
          />
        </div>
      </div>
    </section>

    <section v-if="duplicateReference" class="min-w-0 space-y-1">
      <h3
        v-if="'duplicate_reference' !== item.status"
        class="flex items-center gap-2 font-medium text-warning"
      >
        <UIcon name="i-lucide-layers-3" class="size-4 shrink-0 text-toned" />
        <span>Shared file path</span>
      </h3>
      <p class="text-toned">These history records reference the same file path.</p>
      <div v-if="duplicateReference.path" class="flex min-w-0 items-start gap-1">
        <UTooltip
          :text="item.expand_duplicate_path ? 'Truncate duplicate path' : 'Wrap duplicate path'"
        >
          <UButton
            color="neutral"
            :variant="item.expand_duplicate_path ? 'soft' : 'outline'"
            size="xs"
            square
            icon="i-lucide-wrap-text"
            :aria-label="
              item.expand_duplicate_path ? 'Truncate duplicate path' : 'Wrap duplicate path'
            "
            :aria-pressed="item.expand_duplicate_path"
            class="shrink-0"
            @click="emit('toggle-duplicate-path')"
          />
        </UTooltip>
        <UTooltip :text="duplicateReference.path">
          <NuxtLink
            :to="makeSearchLink('path', duplicateReference.path)"
            class="min-w-0 flex-1 font-mono text-xs text-primary hover:underline"
            :class="item.expand_duplicate_path ? 'wrap-anywhere' : 'truncate'"
          >
            {{ duplicateReference.path }}
          </NuxtLink>
        </UTooltip>
        <UTooltip text="Copy duplicate path">
          <UButton
            color="neutral"
            variant="ghost"
            size="xs"
            square
            icon="i-lucide-copy"
            aria-label="Copy duplicate path"
            class="shrink-0"
            @click="copyText(duplicateReference.path, false)"
          />
        </UTooltip>
      </div>
      <div class="flex flex-wrap gap-1">
        <HistoryRecordLink
          v-for="stateId in duplicateReferenceIds"
          :key="stateId"
          :state-id="stateId"
          :current-state-id="item.state_id"
          user="main"
        />
      </div>
    </section>

    <section v-if="0 < metadataConflicts.length" class="min-w-0 space-y-2">
      <h3
        v-if="'metadata_disagreement' !== item.status"
        class="flex items-center gap-2 font-medium text-warning"
      >
        <UIcon name="i-lucide-list-x" class="size-4 shrink-0 text-toned" />
        <span>Metadata mismatch</span>
      </h3>
      <p class="text-toned">Backends report different values for this item's metadata.</p>
      <dl class="min-w-0 space-y-2">
        <div
          v-for="[field, values] in metadataConflicts"
          :key="field"
          class="grid min-w-0 gap-x-3 gap-y-1 sm:grid-cols-[6rem_minmax(0,1fr)]"
        >
          <dt class="font-medium capitalize text-toned">{{ field.replace(/_/g, ' ') }}</dt>
          <dd class="flex min-w-0 flex-wrap items-start gap-x-3 gap-y-2">
            <template v-for="([value, backends], index) in Object.entries(values)" :key="value">
              <span v-if="0 < index" class="self-center text-toned">vs</span>
              <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1">
                <span class="min-w-0 wrap-anywhere text-default">{{ value }}</span>
                <span v-if="0 < backends.length" class="text-xs text-toned">on</span>
                <BackendItemLink
                  v-for="backend in backends"
                  :key="backend"
                  :backend="backend"
                  :url="item.signals.backend_items?.[backend]?.webUrl ?? null"
                  color="warning"
                />
              </div>
            </template>
          </dd>
        </div>
      </dl>
    </section>

    <section v-if="0 < missingBackends.length" class="space-y-1">
      <p class="text-toned">No metadata for this item was reported by:</p>
      <div class="flex flex-wrap items-center gap-1">
        <BackendItemLink
          v-for="backend in missingBackends"
          :key="backend"
          :backend="backend"
          color="warning"
        />
      </div>
    </section>

    <section v-if="0 < pathSuffixes.length" class="min-w-0 space-y-2">
      <p class="text-toned">Backends report different folder or file names for this item.</p>
      <div class="flex min-w-0 flex-wrap items-start gap-x-3 gap-y-2">
        <template v-for="([suffix, backends], index) in pathSuffixes" :key="suffix">
          <span v-if="0 < index" class="self-center text-toned">vs</span>
          <div class="flex min-w-0 max-w-full flex-wrap items-center gap-1">
            <span class="min-w-0 wrap-anywhere font-mono text-xs text-default">{{ suffix }}</span>
            <span v-if="0 < backends.length" class="text-xs text-toned">on</span>
            <BackendItemLink
              v-for="backend in backends"
              :key="backend"
              :backend="backend"
              :url="item.signals.backend_items?.[backend]?.webUrl ?? null"
              color="warning"
            />
          </div>
        </template>
      </div>
    </section>

    <p v-if="'weak_match' === item.status" class="text-toned">
      No external IDs are available to match this item between backends.
    </p>

    <ul v-if="0 < fallbackReasons.length" class="list-disc space-y-1 pl-5 text-default">
      <li v-for="reason in fallbackReasons" :key="reason" class="wrap-anywhere">{{ reason }}</li>
    </ul>
  </div>
</template>
