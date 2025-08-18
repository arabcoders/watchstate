/**
 * Cache class
 *
 * @class
 * @param {string} engine - The engine to use for caching. Either 'session' or 'local'.
 * @throws {Error} If the engine is not supported.
 */
class Cache {
    supportedEngines = ['session', 'local']
    namespace = ''

    constructor(engine = 'session', namespace = '') {
        if (!this.supportedEngines.includes(engine)) {
            throw new Error(`Engine '${engine}' not supported.`)
        }

        if (this.namespace) {
            this.setNameSpace(namespace)
        }

        if ('session' === engine) {
            this.storage = window.sessionStorage
        } else {
            this.storage = window.localStorage
        }
    }

    /**
     * Set the namespace for the cache
     *
     * @param namespace {string}
     */
    setNameSpace(namespace) {
        this.namespace = namespace ? `${namespace}:` : ''
    }

    /**
     * Set a value in the cache
     *
     * @param key {string}
     * @param value {*}
     * @param ttl {number|null} - Time to live in milliseconds. If null, the value will not expire.
     */
    set(key, value, ttl = null) {
        this.storage.setItem(this.namespace + key, JSON.stringify({value, ttl, time: Date.now()}))
    }

    /**
     * Get a value from the cache
     *
     * @param key {string}
     * @returns {any}
     */
    get(key) {
        const item = this.storage.getItem(this.namespace + key)
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
        this.storage.removeItem(this.namespace + key)
    }

    /**
     * Clear the cache
     *
     * @param {function|null} filter - A filter function to remove only specific keys. Or null to clear all.
     */
    clear(filter = null) {
        let keys = Object.keys(this.storage ?? {})

        if (this.namespace) {
            keys = keys.filter(k => k.startsWith(this.namespace))
        }

        if (null !== filter) {
            keys = keys.filter(filter)
        }

        keys.forEach(k => this.storage.removeItem(k))
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

const useSessionCache = (namespace = '') => new Cache('session', namespace)
const useLocalCache = (namespace = '') => new Cache('local', namespace)

export {useSessionCache, useLocalCache, Cache}
