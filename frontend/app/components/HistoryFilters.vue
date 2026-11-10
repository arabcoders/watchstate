<template>
  <UCard class="ws-card shadow-sm" :ui="{ header: 'p-4', body: 'px-4 pb-4 pt-0' }">
    <template #header
      ><h2 class="text-sm font-semibold text-highlighted">Filter History</h2></template
    >
    <form class="space-y-4" @submit.prevent="apply">
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <UFormField label="Title">
          <UInput
            v-model="basic.title"
            type="search"
            placeholder="Any title"
            class="w-full"
            :disabled="loading"
          />
        </UFormField>
        <UFormField label="Type">
          <USelect v-model="basic.type" :items="typeItems" class="w-full" :disabled="loading" />
        </UFormField>
        <UFormField
          label="Watch status"
          :help="advancedStatus ? 'Watch status is set in Advanced.' : undefined"
        >
          <USelect
            v-model="basic.status"
            :items="statusItems"
            class="w-full"
            :disabled="loading || advancedStatus"
          />
        </UFormField>
        <UFormField label="Genres">
          <USelectMenu
            v-model="genreSelection"
            :items="genreItems"
            multiple
            placeholder="Any genre"
            class="w-full"
            :disabled="loading"
          />
        </UFormField>
        <UFormField label="Libraries">
          <USelectMenu
            v-model="librarySelection"
            :items="libraryItems"
            multiple
            value-key="value"
            placeholder="Any library"
            class="w-full"
            :disabled="loading"
          />
        </UFormField>
        <UFormField label="Release year">
          <div class="flex gap-2">
            <UInput
              v-model="basic.year_min"
              type="number"
              min="1"
              max="9999"
              placeholder="From"
              aria-label="First release year"
              class="w-full"
              :disabled="loading"
            />
            <UInput
              v-model="basic.year_max"
              type="number"
              min="1"
              max="9999"
              placeholder="To"
              aria-label="Last release year"
              class="w-full"
              :disabled="loading"
            />
          </div>
        </UFormField>
      </div>

      <details :open="advancedOpen" class="rounded-md border border-default p-3">
        <summary class="cursor-pointer text-sm font-medium text-default">Advanced</summary>
        <div class="mt-3 space-y-3">
          <div v-for="(entry, index) in advanced" :key="entry.id" class="space-y-2">
            <div class="grid gap-2 sm:grid-cols-[12rem_minmax(0,1fr)_auto]">
              <USelect
                v-model="entry.field"
                :items="availableFields(entry.field)"
                placeholder="Select field"
                :disabled="loading"
                aria-label="Advanced field"
              />
              <UInput
                v-model="entry.value"
                type="search"
                placeholder="Search..."
                :disabled="loading || !entry.field"
                aria-label="Advanced value"
              />
              <UButton
                color="neutral"
                variant="outline"
                icon="i-lucide-trash-2"
                aria-label="Remove filter"
                :disabled="loading"
                @click="advanced.splice(index, 1)"
              />
            </div>
            <p v-if="helpText(entry.field)" class="text-sm text-toned">
              {{ helpText(entry.field) }}
            </p>
          </div>
          <UButton
            color="neutral"
            variant="outline"
            size="sm"
            icon="i-lucide-plus"
            :disabled="loading || !canAdd"
            @click="addAdvanced"
            >Add Filter</UButton
          >
        </div>
      </details>

      <p v-if="validationError" class="text-sm text-error" role="alert">{{ validationError }}</p>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <UButton color="neutral" variant="outline" size="sm" :disabled="loading" @click="reset"
          >Reset</UButton
        >
        <UButton
          type="submit"
          size="sm"
          icon="i-lucide-search"
          :loading="loading"
          :disabled="loading || !!validationError"
          >Apply filters</UButton
        >
      </div>
    </form>
  </UCard>
</template>

<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue';
import { UButton, UCard, UFormField, UInput, USelect, USelectMenu } from '#components';
import type {
  HistoryFilterQuery,
  HistoryFiltersProps,
  HistorySearchableField,
  HistorySearchFilter,
} from '~/types';

const props = defineProps<HistoryFiltersProps>();
const emit = defineEmits<{
  apply: [query: HistoryFilterQuery];
  'draft-state': [state: { valid: boolean; dirty: boolean }];
}>();

const basic = ref({ title: '', type: 'all', status: 'all', year_min: '', year_max: '' });
const genres = ref<string | Array<string>>([]);
const libraries = ref<string | Array<string>>([]);
const advanced = ref<Array<HistorySearchFilter>>([]);
const passthrough = shallowRef<HistoryFilterQuery>({});
const advancedOpen = ref(false);
let nextId = 0;
const basicKeys = ['title', 'type', 'status', 'genres', 'library', 'year_min', 'year_max'];
const passthroughKeys = ['sort', 'with_duplicates'];
const typeItems = [
  { label: 'All types', value: 'all' },
  { label: 'Movies', value: 'movie' },
  { label: 'Episodes', value: 'episode' },
];
const statusItems = [
  { label: 'All statuses', value: 'all' },
  { label: 'Watched', value: 'watched' },
  { label: 'In progress', value: 'in_progress' },
  { label: 'Not started', value: 'not_started' },
];
const fallbackFields: Array<HistorySearchableField> = [
  { key: 'id', description: 'Local history ID.', type: 'int' },
  { key: 'watched', description: 'Watched status.', type: ['0', '1'] },
  {
    key: 'in_progress',
    display: 'In progress',
    description: 'Started but not finished.',
    type: ['1'],
  },
  { key: 'via', display: 'Backend', description: 'Backend name.', type: 'string' },
  { key: 'year', description: 'Exact release year.', type: 'int' },
  { key: 'season', description: 'Season number.', type: 'int' },
  { key: 'episode', description: 'Episode number.', type: 'int' },
  { key: 'parent', display: 'Series GUID', type: 'provider://id' },
  { key: 'guids', display: 'Content GUID', type: 'provider://id' },
  { key: 'metadata', description: 'Search backend metadata.', type: 'backend.field://value' },
  { key: 'extra', description: 'Search additional backend fields.', type: 'backend.field://value' },
  {
    key: 'rguid',
    description: 'Relative GUID.',
    type: 'guid://parentID/seasonNumber[/episodeNumber]',
  },
  { key: 'path', description: 'File path.', type: 'string' },
  { key: 'subtitle', display: 'Subtitle', type: 'string' },
];
const fields = computed(() =>
  [...fallbackFields, ...props.searchable].filter(
    (field, index, all) =>
      !basicKeys.includes(field.key) &&
      !passthroughKeys.includes(field.key) &&
      index === all.findLastIndex((entry) => entry.key === field.key),
  ),
);
const genreSelection = computed<Array<string>>({
  get: () => ('string' === typeof genres.value ? [genres.value] : genres.value),
  set: (value) => {
    genres.value = value;
  },
});
const librarySelection = computed<Array<string>>({
  get: () => ('string' === typeof libraries.value ? [libraries.value] : libraries.value),
  set: (value) => {
    libraries.value = value;
  },
});
const genreItems = computed(() =>
  [...new Set([...props.options.genres, ...genreSelection.value])].sort(),
);
const libraryItems = computed(() => [
  ...props.options.libraries,
  ...librarySelection.value
    .filter((value) => !props.options.libraries.some((item) => item.value === value))
    .map((value) => ({ value, label: value })),
]);
const advancedStatus = computed(() =>
  advanced.value.some(
    (entry) => ['watched', 'in_progress'].includes(entry.field) && !!entry.value.trim(),
  ),
);

const availableFields = (current: string): Array<{ label: string; value: string }> => {
  const used = advanced.value
    .filter((entry) => entry.field !== current)
    .map((entry) => entry.field);
  return fields.value
    .filter(
      (field) =>
        !used.includes(field.key) &&
        (!['watched', 'in_progress'].includes(field.key) ||
          'all' === basic.value.status ||
          field.key === current) &&
        (!['metadata', 'extra'].includes(field.key) ||
          field.key === current ||
          !used.some((key) => ['metadata', 'extra'].includes(key))),
    )
    .map((field) => ({ label: field.display ?? field.key, value: field.key }));
};
const canAdd = computed(
  () =>
    advanced.value.every((entry) => entry.field && entry.value.trim()) &&
    0 < availableFields('').length,
);
const addAdvanced = (): void => {
  advanced.value.push({ id: nextId++, field: '', value: '' });
};
const helpText = (key: string): string => {
  const field = fields.value.find((entry) => key === entry.key);
  if (!field) {
    return '';
  }
  const expected = Array.isArray(field.type) ? field.type.join(' or ') : field.type;
  return `${field.description ?? ''}${expected ? ` Expected value: ${expected}` : ''}`.trim();
};

const draftQuery = computed<HistoryFilterQuery>(() => {
  const query: HistoryFilterQuery = { ...passthrough.value };
  for (const [key, raw] of Object.entries(basic.value)) {
    const value = String(raw).trim();
    if (value && 'all' !== value) {
      query[key] = value;
    }
  }
  if (0 < genres.value.length) {
    query.genres = genres.value;
  }
  if (0 < libraries.value.length) {
    query.library = libraries.value;
  }
  for (const entry of advanced.value) {
    if (entry.field && entry.value.trim()) {
      query[entry.field] = entry.value.trim();
    }
  }
  return query;
});
const validationError = computed(() => {
  if (advancedStatus.value && 'all' !== basic.value.status) {
    return 'Use Watch status or an Advanced status filter, not both.';
  }
  for (const year of [basic.value.year_min, basic.value.year_max]) {
    if (
      '' !== String(year) &&
      (!/^\d+$/.test(String(year)) || 1 > Number(year) || 9999 < Number(year))
    ) {
      return 'Enter release years between 1 & 9999.';
    }
  }
  if (
    basic.value.year_min &&
    basic.value.year_max &&
    Number(basic.value.year_min) > Number(basic.value.year_max)
  ) {
    return 'The first year must not exceed the last year.';
  }
  for (const entry of advanced.value) {
    if (!entry.field || !entry.value.trim()) {
      return 'Each advanced filter needs a field & value.';
    }
    if (['metadata', 'extra'].includes(entry.field) && !/^.+?:\/\/.+$/.test(entry.value.trim())) {
      return `Use backend.field://value for ${entry.field}.`;
    }
  }
  return '';
});

const restoreQuery = (query: HistoryFilterQuery): void => {
  basic.value = {
    title: String(query.title ?? ''),
    type: String(query.type ?? 'all'),
    status: String(query.status ?? 'all'),
    year_min: String(query.year_min ?? ''),
    year_max: String(query.year_max ?? ''),
  };
  genres.value = query.genres ?? [];
  libraries.value = query.library ?? [];
  passthrough.value = Object.fromEntries(
    Object.entries(query).filter(([key]) => passthroughKeys.includes(key)),
  );
  advanced.value = Object.entries(query)
    .filter(([key]) => !basicKeys.includes(key) && !passthroughKeys.includes(key))
    .map(([field, value]) => ({ id: nextId++, field, value: String(value) }));
  advancedOpen.value = 0 < advanced.value.length;
};
const apply = (): void => {
  if (!validationError.value && !props.loading) {
    emit('apply', draftQuery.value);
  }
};
const reset = (): void => {
  restoreQuery({});
  emit('apply', {});
};

watch(
  () => JSON.stringify(props.query),
  () => restoreQuery(props.query),
  { immediate: true },
);
watch(
  [draftQuery, validationError],
  () => {
    const draft = JSON.stringify(
      Object.entries(draftQuery.value).sort(([left], [right]) => left.localeCompare(right)),
    );
    const current = JSON.stringify(
      Object.entries(props.query).sort(([left], [right]) => left.localeCompare(right)),
    );
    emit('draft-state', { valid: !validationError.value, dirty: draft !== current });
  },
  { immediate: true, deep: true },
);
</script>
