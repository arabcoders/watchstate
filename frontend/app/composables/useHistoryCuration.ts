import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import type { LocationQuery } from 'vue-router';
import type {
  HistoryExportChoice,
  HistoryExportFilters,
  HistoryFilterQuery,
  HistoryListItem,
} from '~/types';
import { notification, parse_api_response, request } from '~/utils';

export const useHistoryCuration = (
  user: Ref<string>,
  blocked: Ref<boolean>,
  pageFilter: Ref<string>,
) => {
  const readQuery = (params: LocationQuery): HistoryFilterQuery => {
    const result: HistoryFilterQuery = {};
    for (const [rawKey, value] of Object.entries(params)) {
      if (['page', 'perpage', 'filter', 'key', 'q', 'value', 'view'].includes(rawKey)) {
        continue;
      }
      const key = rawKey.endsWith('[]') ? rawKey.slice(0, -2) : rawKey;
      if (
        ['genres', 'library', 'sort'].includes(key) &&
        (Array.isArray(value) || rawKey.endsWith('[]'))
      ) {
        result[key] = (Array.isArray(value) ? value : [value]).filter(
          (entry): entry is string => 'string' === typeof entry && !!entry,
        );
      } else if (Array.isArray(value)) {
        result[key] =
          value.find((entry): entry is string => 'string' === typeof entry && !!entry) ?? '';
      } else if ('string' === typeof value && value) {
        result[key] = value;
      }
    }
    if ('string' === typeof params.key && 'string' === typeof params.q && params.q) {
      result[params.key] = params.q;
    }
    for (const key of ['metadata', 'extra']) {
      if (
        '1' === result[key] &&
        'string' === typeof params.key &&
        'string' === typeof params.value
      ) {
        result[key] = `${params.key}://${params.value}`;
      }
    }
    return result;
  };

  const buildFilters = (query: HistoryFilterQuery): HistoryExportFilters => {
    const filters: HistoryExportFilters = {};
    for (const [key, value] of Object.entries(query)) {
      if (['metadata', 'extra'].includes(key) && 'string' === typeof value) {
        const index = value.indexOf('://');
        if (1 > index || !value.slice(index + 3)) {
          throw new Error(`Invalid search format for '${key}'.`);
        }
        filters[key] = '1';
        filters.key = value.slice(0, index);
        filters.value = value.slice(index + 3);
      } else {
        filters[key] = Array.isArray(value) ? [...value] : value;
      }
    }
    return filters;
  };

  const selectedRecords = shallowRef(new Map<number, HistoryListItem>());
  const applied = shallowRef<{
    user: string;
    key: string;
    filters: HistoryExportFilters;
    total: number;
  } | null>(null);
  const exporting = ref(false);
  let scopeKey = '';
  let controller = new AbortController();

  const clearSelection = (): void => {
    selectedRecords.value = new Map();
  };

  const beginQuery = (filters: HistoryExportFilters): void => {
    const key = JSON.stringify(
      Object.entries(filters)
        .filter(([name]) => 'sort' !== name)
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([name, value]) => [name, Array.isArray(value) ? [...value].sort() : value]),
    );

    applied.value = null;
    if (key !== scopeKey) {
      controller.abort();
      controller = new AbortController();
      exporting.value = false;
      clearSelection();
      scopeKey = key;
    }
  };

  const applyQuery = (
    filters: HistoryExportFilters,
    total: number,
    rows: Array<HistoryListItem>,
  ): void => {
    beginQuery(filters);
    applied.value = {
      user: user.value,
      key: scopeKey,
      filters: structuredClone(filters),
      total,
    };

    const selected = new Map(selectedRecords.value);
    for (const row of rows) {
      if (selected.has(row.id)) {
        selected.set(row.id, row);
      }
    }
    selectedRecords.value = selected;
  };

  const selectRecords = (rows: Array<HistoryListItem>, checked: boolean): void => {
    if (blocked.value || null === applied.value || applied.value.user !== user.value) {
      return;
    }

    const selected = new Map(selectedRecords.value);
    for (const row of rows) {
      if (checked) {
        selected.set(row.id, row);
      } else {
        selected.delete(row.id);
      }
    }
    selectedRecords.value = selected;
  };

  const matchingCount = computed(() => applied.value?.total ?? 0);
  const selection = computed(() => Array.from(selectedRecords.value.values()));
  const selectedIds = computed(() => Array.from(selectedRecords.value.keys()));
  const ready = computed(
    () =>
      !blocked.value &&
      null !== applied.value &&
      applied.value.user === user.value &&
      applied.value.key === scopeKey,
  );
  const matchingEnabled = computed(
    () => ready.value && !pageFilter.value && 0 < matchingCount.value,
  );
  const selectedEnabled = computed(
    () => ready.value && 0 < matchingCount.value && 0 < selectedIds.value.length,
  );

  const exportHistory = async (choice: HistoryExportChoice): Promise<boolean> => {
    if (
      exporting.value ||
      null === applied.value ||
      ('matching' === choice.scope ? !matchingEnabled.value : !selectedEnabled.value)
    ) {
      return false;
    }

    const active = controller;
    const selectedUser = user.value;
    const filters = structuredClone(applied.value.filters);
    const body = {
      filters,
      group: choice.group,
      ...('selected' === choice.scope ? { ids: [...selectedIds.value] } : {}),
    };
    exporting.value = true;

    try {
      const response = await request(`/history/export/${choice.format}`, {
        method: 'POST',
        headers: { 'X-User': selectedUser },
        body: JSON.stringify(body),
        signal: active.signal,
      });
      if (!response.ok) {
        const json = await parse_api_response<Record<string, unknown>>(response);
        const reason =
          'error' in json &&
          'object' === typeof json.error &&
          null !== json.error &&
          'message' in json.error &&
          'string' === typeof json.error.message
            ? json.error.message.trim()
            : '';

        throw new Error(
          `HTTP ${response.status}: ${reason || response.statusText.trim() || 'The server returned no error details.'}`,
        );
      }

      const blob = await response.blob();
      if (active.signal.aborted || selectedUser !== user.value) {
        return false;
      }

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      try {
        link.href = url;
        link.download = `history-${Date.now()}.${'markdown' === choice.format ? 'md' : 'json'}`;
        document.body.appendChild(link);
        link.click();
      } finally {
        link.remove();
        URL.revokeObjectURL(url);
      }
      return true;
    } catch (cause: unknown) {
      if (!active.signal.aborted) {
        const reason = (cause instanceof Error ? cause.message : String(cause)).trim();
        notification(
          'error',
          'History export',
          `Couldn't download the History export. ${reason || 'No error details were provided.'}`,
        );
      }
      return false;
    } finally {
      if (!active.signal.aborted) {
        exporting.value = false;
      }
    }
  };

  watch(
    user,
    () => {
      controller.abort();
      controller = new AbortController();
      scopeKey = '';
      applied.value = null;
      exporting.value = false;
      clearSelection();
    },
    { flush: 'sync' },
  );

  onScopeDispose(() => controller.abort());

  return {
    readQuery,
    buildFilters,
    matchingCount,
    selection,
    selectedIds,
    matchingEnabled,
    selectedEnabled,
    exporting,
    beginQuery,
    applyQuery,
    selectRecords,
    clearSelection,
    exportHistory,
  };
};
