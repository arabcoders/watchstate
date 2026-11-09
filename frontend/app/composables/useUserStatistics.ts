import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import type { Ref } from 'vue';
import type { UserStatisticsResponse, UserStatisticsRunResponse } from '~/types';
import { notification, parse_api_response, request } from '~/utils';

export const useUserStatistics = (user: Ref<string>) => {
  const state = shallowRef<UserStatisticsResponse | null>(null);
  const isLoading = ref(false);
  const isQueueing = ref(false);
  const exporting = ref<'json' | 'markdown' | null>(null);
  const error = ref('');
  let controller = new AbortController();
  let loadId = 0;

  const loadReport = async (): Promise<void> => {
    const active = controller;
    const id = ++loadId;
    isLoading.value = true;
    error.value = '';

    try {
      const response = await request('/state/user-statistics', {
        headers: { 'X-User': user.value },
        signal: active.signal,
      });
      const json = await parse_api_response<UserStatisticsResponse>(response);
      if ('error' in json) {
        throw new Error(`${json.error.code}: ${json.error.message}`);
      }
      if (false === response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      if (active.signal.aborted || id !== loadId) {
        return;
      }

      state.value = json;
    } catch (cause: unknown) {
      if (active.signal.aborted || id !== loadId) {
        return;
      }
      error.value = cause instanceof Error ? cause.message : String(cause);
      notification('error', 'User Statistics', error.value);
    } finally {
      if (false === active.signal.aborted && id === loadId) {
        isLoading.value = false;
      }
    }
  };

  const queueReport = async (): Promise<void> => {
    if (isQueueing.value || state.value?.queued) {
      return;
    }

    const active = controller;
    isQueueing.value = true;

    try {
      const response = await request('/state/user-statistics/run', {
        method: 'POST',
        headers: { 'X-User': user.value },
        signal: active.signal,
      });
      const json = await parse_api_response<UserStatisticsRunResponse>(response);
      if ('error' in json) {
        throw new Error(`${json.error.code}: ${json.error.message}`);
      }
      if (false === response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      if (active.signal.aborted) {
        return;
      }

      state.value = {
        report: state.value?.report ?? null,
        stale: state.value?.stale ?? false,
        queued: json.queued || json.running,
        queued_event: json.event_id,
      };
      notification(json.queued ? 'success' : 'info', 'User Statistics', json.message);
      await loadReport();
    } catch (cause: unknown) {
      if (false === active.signal.aborted) {
        notification(
          'error',
          'User Statistics',
          cause instanceof Error ? cause.message : String(cause),
        );
      }
    } finally {
      if (false === active.signal.aborted) {
        isQueueing.value = false;
      }
    }
  };

  const exportReport = async (format: 'json' | 'markdown'): Promise<void> => {
    if (null !== exporting.value || !state.value?.report) {
      return;
    }

    const active = controller;
    const selectedUser = user.value;
    const generatedAt = state.value.report.generated_at;
    exporting.value = format;

    try {
      const response = await request(`/state/user-statistics/export/${format}`, {
        headers: { 'X-User': selectedUser },
        signal: active.signal,
      });
      if (false === response.ok) {
        const json = await parse_api_response<UserStatisticsResponse>(response);
        throw new Error(
          'error' in json
            ? `${json.error.code}: ${json.error.message}`
            : `HTTP ${response.status}: ${response.statusText}`,
        );
      }

      const blob = await response.blob();
      if (active.signal.aborted) {
        return;
      }

      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `user-statistics-${generatedAt}.${'markdown' === format ? 'md' : 'json'}`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (cause: unknown) {
      if (false === active.signal.aborted) {
        notification(
          'error',
          'Export failed',
          cause instanceof Error ? cause.message : String(cause),
        );
      }
    } finally {
      if (false === active.signal.aborted) {
        exporting.value = null;
      }
    }
  };

  watch(
    user,
    () => {
      controller.abort();
      controller = new AbortController();
      state.value = null;
      isQueueing.value = false;
      exporting.value = null;
      void loadReport();
    },
    { immediate: true, flush: 'sync' },
  );

  onScopeDispose(() => controller.abort());

  return {
    state,
    report: computed(() => state.value?.report ?? null),
    isLoading,
    isQueueing,
    exporting,
    error,
    loadReport,
    queueReport,
    exportReport,
  };
};
