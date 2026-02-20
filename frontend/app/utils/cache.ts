type CacheEngine = 'session' | 'local';

interface CacheItem<T = any> {
  value: T;
  ttl: number | null;
  time: number;
}

/**
 * Cache class
 *
 * @class
 * @param {string} engine - The engine to use for caching. Either 'session' or 'local'.
 * @throws {Error} If the engine is not supported.
 */
class Cache {
  private supportedEngines: CacheEngine[] = ['session', 'local'];
  private namespace: string = '';
  private readonly storage: Storage;

  constructor(engine: CacheEngine = 'session', namespace: string = '') {
    if (!this.supportedEngines.includes(engine)) {
      throw new Error(`Engine '${engine}' not supported.`);
    }

    this.setNameSpace(namespace);

    this.storage = engine === 'session' ? window.sessionStorage : window.localStorage;
  }

  /**
   * Set the namespace for the cache
   *
   * @param namespace {string}
   */
  setNameSpace(namespace: string): void {
    this.namespace = namespace ? `${namespace}:` : '';
  }

  /**
   * Set a value in the cache
   *
   * @param key {string}
   * @param value {*}
   * @param ttl {number|null} - Time to live in seconds. If null, the value will not expire.
   */
  set<T = any>(key: string, value: T, ttl: number | null = null): void {
    this.storage.setItem(
      this.namespace + key,
      JSON.stringify({
        value: value,
        ttl: ttl === null || ttl === undefined ? null : Math.max(0, ttl) * 1000,
        time: Date.now(),
      } as CacheItem<T>),
    );
  }

  /**
   * Get a value from the cache
   *
   * @param key {string}
   * @returns {any}
   */
  get<T = any>(key: string): T | null {
    const item = this.storage.getItem(this.namespace + key);
    if (null === item) {
      return null;
    }

    try {
      const { value, ttl, time } = JSON.parse(item) as CacheItem<T>;
      if (ttl !== null && Date.now() - time > ttl) {
        this.remove(key);
        return null;
      }
      return value;
    } catch (e: any) {
      console.error('Cache: Failed to parse item', e);
      return item as unknown as T;
    }
  }

  /**
   * Remove a value from the cache
   *
   * @param key {string}
   */
  remove(key: string): void {
    this.storage.removeItem(this.namespace + key);
  }

  /**
   * Clear the cache
   *
   * @param {function|null} filter - A filter function to remove only specific keys. Or null to clear all.
   */
  clear(filter: ((key: string) => boolean) | null = null): void {
    let keys = Object.keys(this.storage ?? {});

    if (this.namespace) {
      keys = keys.filter((k) => k.startsWith(this.namespace));
    }

    if (filter !== null) {
      keys = keys.filter(filter);
    }

    keys.forEach((k) => this.storage.removeItem(k));
  }

  /**
   * Check if a key is in the cache
   *
   * @param key {string}
   * @returns {boolean}
   */
  has(key: string): boolean {
    return this.get(key) !== null;
  }
}

const useSessionCache = (namespace: string = ''): Cache => new Cache('session', namespace);
const useLocalCache = (namespace: string = ''): Cache => new Cache('local', namespace);

export { useSessionCache, useLocalCache, Cache };
