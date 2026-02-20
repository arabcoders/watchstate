import { ref, onBeforeUnmount } from 'vue';
import { request, parse_api_response } from '~/utils';
import type { EventsStats } from '~/types';

/**
 * Reusable composable to fetch and auto-reload event statistics.
 */
export default function useEventsStats(only: Array<keyof EventsStats> = []) {
  const onlyParam = only.length > 0 ? `?only=${only.join(',')}` : '';
  const stats = ref<EventsStats>({ pending: 0, running: 0, completed: 0, failed: 0, cancelled: 0 });
  const loading = ref<boolean>(true);
  const intervalRef = ref<ReturnType<typeof setInterval> | null>(null);
  const frequency = 15000;

  const load = async (): Promise<void> => {
    try {
      const response = await request(`/system/events/stats${onlyParam}`);
      if (!response.ok) {
        return;
      }
      const json = await parse_api_response<EventsStats>(response);
      if ('error' in json) {
        return;
      }
      stats.value = json;
    } catch {
      // ignore
    } finally {
      loading.value = false;
    }
  };

  const start = (): void => {
    if (intervalRef.value !== null) {
      return;
    }
    // initial load
    void load();
    intervalRef.value = setInterval(() => void load(), frequency);
  };

  const stop = (): void => {
    if (intervalRef.value === null) {
      return;
    }
    clearInterval(intervalRef.value);
    intervalRef.value = null;
  };

  onBeforeUnmount(() => stop());

  return {
    stats,
    loading,
    load,
    start,
    stop,
  };
}
