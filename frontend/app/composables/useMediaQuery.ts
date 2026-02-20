import { ref, onMounted, onBeforeUnmount, shallowReadonly, type Ref } from 'vue';

export interface MediaQueryOptions {
  /**
   * A custom media query string.
   * Example: "(max-width: 640px) or (hover: none)"
   * If provided, takes precedence over maxWidth.
   */
  query?: string;

  /**
   * Max width in px considered true.
   * Ignored if `query` is provided. Default: 768.
   */
  maxWidth?: number;
}

/**
 * Reactive state of a CSS media query.
 */
export function useMediaQuery(options: MediaQueryOptions = {}): Readonly<Ref<boolean>> {
  const query = options.query ?? `(max-width: ${options.maxWidth ?? 1024}px)`;
  const matches = ref(false);

  let mql: MediaQueryList | null = null;
  let onChange: ((ev: MediaQueryListEvent) => void) | null = null;

  const setup = () => {
    if ('undefined' === typeof window || !window.matchMedia) {
      return;
    }
    mql = window.matchMedia(query);
    matches.value = mql.matches;

    onChange = (ev) => {
      matches.value = ev.matches;
    };
    if ('addEventListener' in mql) {
      mql.addEventListener('change', onChange as EventListener);
      return;
    }

    // @ts-expect-error legacy Safari
    mql.addListener(onChange);
  };

  const teardown = () => {
    if (!mql || !onChange) return;
    if ('removeEventListener' in mql) {
      mql.removeEventListener('change', onChange as EventListener);
    } else {
      // @ts-expect-error legacy Safari
      mql.removeListener(onChange);
    }
    mql = null;
    onChange = null;
  };

  onMounted(setup);
  onBeforeUnmount(teardown);

  return shallowReadonly(matches);
}
