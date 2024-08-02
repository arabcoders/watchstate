/**
 * Cache class
 *
 * @class
 * @param {string} engine - The engine to use for caching. Either 'session' or 'local'.
 * @throws {Error} If the engine is not supported.
 */
class Cache {
    supportedEngines = ['session', 'local']

    constructor(engine = 'session') {
        if (!this.supportedEngines.includes(engine)) {
            throw new Error(`Engine '${engine}' not supported.`)
        }

        if ('session' === engine) {
            this.storage = window.sessionStorage
        } else {
            this.storage = window.localStorage
        }
    }

    /**
     * Set a value in the cache
     *
     * @param key {string}
     * @param value {*}
     * @param ttl {number|null} - Time to live in milliseconds. If null, the value will not expire.
     */
    set(key, value, ttl = null) {
        this.storage.setItem(key, JSON.stringify({value, ttl, time: Date.now()}))
    }

    /**
     * Get a value from the cache
     *
     * @param key {string}
     * @returns {any}
     */
    get(key) {
        const item = this.storage.getItem(key)
        if (null === item) {
            return null
        }

        try {
            const {value, ttl, time} = JSON.parse(item)
            if (null !== ttl && Date.now() - time > ttl) {
                this.remove(key)
                return null
            }
            return value
        } catch (e) {
            return item
        }
    }

    /**
     * Remove a value from the cache
     *
     * @param key {string}
     */
    remove(key) {
        this.storage.removeItem(key)
    }

    /**
     * Clear the cache
     *
     * @param {function|null} filter - A filter function to remove only specific keys. Or null to clear all.
     */
    clear(filter = null) {
        if (null !== filter) {
            Object.keys(this.storage ?? {}).filter(filter).forEach(k => this.storage.removeItem(k))
        } else {
            this.storage.clear()
        }
    }

    /**
     * Check if a key is in the cache
     *
     * @param key {string}
     * @returns {boolean}
     */
    has(key) {
        return null !== this.get(key)
    }
}

const useSessionCache = () => new Cache('session')
const useLocalCache = () => new Cache('local')

export {useSessionCache, useLocalCache, Cache}
