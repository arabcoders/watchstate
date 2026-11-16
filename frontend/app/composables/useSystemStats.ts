import { onBeforeUnmount, ref } from 'vue';
import { parse_api_response, request } from '~/utils';
import type { SystemStats } from '~/types';

export default function useSystemStats() {
  const stats = ref<SystemStats>({
    events: { pending: 0 },
    transport: { pending: 0 },
  });
  const loading = ref<boolean>(true);
  const error = ref('');
  const lastUpdated = ref<number | null>(null);
  const intervalRef = ref<ReturnType<typeof setInterval> | null>(null);
  const frequency = 60000;

  const load = async (): Promise<void> => {
    loading.value = true;
    try {
      const response = await request('/system/stats');
      const json = await parse_api_response<SystemStats>(response);
      if ('error' in json) {
        throw new Error(json.error.message);
      }
      if (false === response.ok) {
        throw new Error(`Unable to load pending work (HTTP ${response.status}).`);
      }

      stats.value = json;
      lastUpdated.value = Date.now();
      error.value = '';
    } catch (cause: unknown) {
      error.value = cause instanceof Error ? cause.message : 'Unable to load pending work.';
    } finally {
      loading.value = false;
    }
  };

  const start = (): void => {
    if (null !== intervalRef.value) {
      return;
    }

    void load();
    intervalRef.value = setInterval(() => void load(), frequency);
  };

  const stop = (): void => {
    if (null === intervalRef.value) {
      return;
    }

    clearInterval(intervalRef.value);
    intervalRef.value = null;
  };

  onBeforeUnmount(() => stop());

  return { stats, loading, error, lastUpdated, load, start, stop };
}
