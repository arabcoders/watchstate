import { onBeforeUnmount, ref } from 'vue';
import { parse_api_response, request } from '~/utils';
import type { SystemStats } from '~/types';

export default function useSystemStats() {
  const stats = ref<SystemStats>({
    events: { pending: 0 },
    transport: { pending: 0 },
  });
  const loading = ref<boolean>(true);
  const intervalRef = ref<ReturnType<typeof setInterval> | null>(null);
  const frequency = 60000;

  const load = async (): Promise<void> => {
    try {
      const response = await request('/system/stats');
      if (!response.ok) {
        return;
      }

      const json = await parse_api_response<SystemStats>(response);
      if ('error' in json) {
        return;
      }

      stats.value = json;
    } catch {
      // Ignore background polling failures.
    } finally {
      loading.value = false;
    }
  };

  const start = (): void => {
    if (intervalRef.value !== null) {
      return;
    }

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

  return { stats, loading, load, start, stop };
}
