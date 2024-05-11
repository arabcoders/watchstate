import {useNotification} from '@kyvg/vue3-notification'

const {notify} = useNotification();

const AG_SEPARATOR = '.'

/**
 * Get value from object or function
 *
 * @param {Function|*} obj
 * @returns {*}
 */
const getValue = (obj) => 'function' === typeof obj ? obj() : obj;

/**
 * Get value from object or function and return default value if it's undefined  or null
 *
 * @param {Object|Array} obj The object to get the value from.
 * @param {string} path The path to the value.
 * @param {*} defaultValue The default value to return if the path is not found.
 *
 * @returns {*} The value at the path or the default value.
 */
const ag = (obj, path, defaultValue = null) => {
    const keys = path.split(AG_SEPARATOR)
    let at = obj

    for (let key of keys) {
        if (typeof at === 'object' && at !== null && key in at) {
            at = at[key]
        } else {
            return getValue(defaultValue)
        }
    }

    return getValue(at)
}

/**
 * Set value in object by path
 *
 * @param {Object} obj The object to set the value in.
 * @param {string} path The path to the value.
 * @param {*} value The value to set.
 *
 * @returns {Object} The object with the value set.
 */
const ag_set = (obj, path, value) => {
    const keys = path.split(AG_SEPARATOR)
    let at = obj

    while (keys.length > 0) {
        if (keys.length === 1) {
            if (typeof at === 'object' && at !== null) {
                at[keys.shift()] = value
            } else {
                throw new Error(`Cannot set value at this path (${path}) because it's not an object.`)
            }
        } else {
            const key = keys.shift();
            if (!at[key]) {
                at[key] = {}
            }
            at = at[key]
        }
    }

    return obj
}

/**
 * Convert bytes to human-readable file size
 *
 * @param {number} bytes The number of bytes.
 * @param {boolean} showUnit Whether to show the unit.
 * @param {number} decimals The number of decimals.
 * @param {number} mod The mod.
 *
 * @returns {string} The human-readable file size.
 */
const humanFileSize = (bytes = 0, showUnit = true, decimals = 2, mod = 1000) => {
    const sz = 'BKMGTP'
    const factor = Math.floor((bytes.toString().length - 1) / 3)
    return `${(bytes / (mod ** factor)).toFixed(decimals)}${showUnit ? sz[factor] : ''}`
}

/**
 * Wait for an element to be loaded in the DOM
 *
 * @param {string} sel The selector of the element.
 * @param {Function} callback The callback function.
 *
 * @returns {void}
 */
const awaitElement = (sel, callback) => {
    let interval = undefined
    let $elm = document.querySelector(sel)

    if ($elm) {
        callback(sel, $elm)
        return
    }

    interval = setInterval(() => {
        let $elm = document.querySelector(sel)
        if ($elm) {
            clearInterval(interval)
            callback(sel, $elm)
        }
    }, 200)
}

/**
 * Uppercase the first letter of a string
 *
 * @param {string} str The string to uppercase.
 *
 * @returns {string} The string with the first letter uppercased.
 */
const ucFirst = (str) => {
    if (typeof str !== 'string') {
        return str
    }
    return str.charAt(0).toUpperCase() + str.slice(1);
}

/**
 * Display a notification
 *
 * @param {string} type The type of the notification.
 * @param {string} title The title of the notification.
 * @param {string} text The text of the notification.
 * @param {number} duration The duration of the notification.
 *
 * @returns {void}
 */
const notification = (type, title, text, duration = 3000) => {
    let classes = ''

    switch (type.toLowerCase()) {
        case 'info':
        default:
            classes = 'has-background-info has-text-white'
            break
        case 'success':
            classes = 'has-background-success has-text-white'
            break
        case 'warning':
            classes = 'has-background-warning has-text-white'
            break
        case 'error':
            classes = 'has-background-danger has-text-white'
            if (duration === 3000) {
                duration = 10000
            }
            break
    }

    return notify({title, text, type: classes, duration})
}

export {ag_set, ag, humanFileSize, awaitElement, ucFirst, notification}
