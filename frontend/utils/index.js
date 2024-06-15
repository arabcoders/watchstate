import {useNotification} from '@kyvg/vue3-notification'

const {notify} = useNotification();

const AG_SEPARATOR = '.'

const guid_links = {
    'episode': {
        'imdb': 'https://www.imdb.com/title/{_guid}',
        'tmdb': 'https://www.themoviedb.org/tv/{parent.guid_tmdb}/season/{season}/episode/{episode}',
        'tvdb': 'https://thetvdb.com/dereferrer/episode/{_guid}',
        'tvmaze': 'https://www.tvmaze.com/episodes/{_guid}',
        'anidb': 'https://anidb.net/episode/{_guid}',
        'youtube_video': 'https://www.youtube.com/watch?v={_guid}',
    }, 'series': {
        'imdb': 'https://www.imdb.com/title/{_guid}',
        'tmdb': 'https://www.themoviedb.org/tv/{_guid}',
        'tvdb': 'https://thetvdb.com/dereferrer/series/{_guid}',
        'tvmaze': 'https://www.tvmaze.com/shows/{_guid}/-',
        'anidb': 'https://anidb.net/anime/{_guid}',
        'youtube_channel': 'https://www.youtube.com/channel/{_guid}',
        'youtube_playlist': 'https://www.youtube.com/playlist?list={_guid}',
    }, 'movie': {
        'imdb': 'https://www.imdb.com/title/{_guid}',
        'tmdb': 'https://www.themoviedb.org/movie/{_guid}',
        'tvdb': 'https://thetvdb.com/dereferrer/movie/{_guid}',
        'anidb': 'https://anidb.net/anime/{_guid}',
        'youtube_video': 'https://www.youtube.com/watch?v={_guid}',
    },
}

const YT_CH = new RegExp('(UC|HC)[a-zA-Z0-9\\-_]{22}')
const YT_PL = new RegExp('PL[^\\[\\]]{32}|PL[^\\[\\]]{16}|(UU|FL|LP|RD)[^\\[\\]]{22}')

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

/**
 * Replace tags in text with values from context
 *
 * @param {string} text The text with tags
 * @param {object} context The context with values
 *
 * @returns {string} The text with replaced tags
 */
const r = (text, context = {}) => {
    const tagLeft = '{';
    const tagRight = '}';

    if (!text.includes(tagLeft) || !text.includes(tagRight)) {
        return text
    }

    const pattern = new RegExp(`${tagLeft}([\\w_.]+)${tagRight}`, 'g');
    const matches = text.match(pattern);

    if (!matches) {
        return text
    }

    let replacements = {};

    matches.forEach(match => replacements[match] = ag(context, match.slice(1, -1), ''));

    for (let key in replacements) {
        text = text.replace(new RegExp(key, 'g'), replacements[key]);
    }

    return text
}

/**
 * Make GUID link
 *
 * @param {string} type
 * @param {string} source
 * @param {string} guid
 * @param {object} data
 *
 * @returns {string}
 */
const makeGUIDLink = (type, source, guid, data) => {
    if ('youtube' === source) {
        if (YT_CH.test(guid)) {
            source = 'youtube_channel'
        } else if (YT_PL.test(guid)) {
            source = 'youtube_playlist'
        } else {
            source = 'youtube_video'
        }
    }

    type = type.toLowerCase();

    if ('show' === type) {
        type = 'series'
    }

    const link = ag(guid_links, `${type}.${source}`, null)

    return null == link ? '' : r(link, {_guid: guid, ...toRaw(data)})
}

/**
 * Format duration
 *
 * @param {number} milliseconds
 *
 * @returns {string} The formatted duration.
 */
const formatDuration = (milliseconds) => {
    milliseconds = parseInt(milliseconds)
    let seconds = Math.floor(milliseconds / 1000);
    let minutes = Math.floor(seconds / 60);
    let hours = Math.floor(minutes / 60);
    seconds %= 60;
    minutes %= 60;

    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

const copyText = (str) => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(str).then(() => {
            notification('success', 'Success', 'Report has been copied to clipboard.')
        }).catch((error) => {
            console.error('Failed to copy: ', error)
            notification('error', 'Error', 'Failed to copy to clipboard.')
        });
        return
    }

    const el = document.createElement('textarea')
    el.value = str
    document.body.appendChild(el)
    el.select()
    document.execCommand('copy')
    document.body.removeChild(el)

    notification('success', 'Success', 'Text copied to clipboard.')
}

const makeConsoleCommand = (cmd) => {
    const params = new URLSearchParams();
    // -- base64 encode the command to prevent XSS
    params.append('cmd', btoa(cmd));
    return `/console?${params.toString()}`
}

const stringToRegex = (str) => new RegExp(str.match(/\/(.+)\/.*/)[1], str.match(/\/.+\/(.*)/)[1])


/**
 * Make history search link.
 *
 * @param {string} type
 * @param {string} query
 *
 * @returns {string}
 */
const makeSearchLink = (type, query) => {
    const params = new URLSearchParams();
    params.append('perpage', 50);
    params.append('page', 1);
    params.append('q', query);
    params.append('key', type);
    return `/history?${params.toString()}`
}

/**
 * Dispatch event.
 *
 * @param eventName
 * @param detail
 * @returns {void}
 */
const dEvent = (eventName, detail = {}) => {
    console.debug('Dispatching event', eventName, detail);
    window.dispatchEvent(new CustomEvent(eventName, {detail}))
}

/**
 * Make name
 *
 * @param item {Object}
 * @param asMovie {boolean}
 *
 * @returns {string|null} The name of the item.
 */
const makeName = (item, asMovie = false) => {
    if (!item) {
        return null;
    }
    const year = ag(item, 'year', '0000');
    const title = ag(item, 'title', '??');
    const type = ag(item, 'type', 'movie');

    if (['show', 'movie'].includes(type) || asMovie) {
        return r('{title} ({year})', {title, year})
    }

    return r('{title} ({year}) - {season}x{episode}', {
        title,
        year,
        season: ag(item, 'season', 0).toString().padStart(2, '0'),
        episode: ag(item, 'episode', 0).toString().padStart(3, '0'),
    })
}

export {
    ag_set,
    ag,
    humanFileSize,
    awaitElement,
    ucFirst,
    notification,
    makeGUIDLink,
    formatDuration,
    copyText,
    stringToRegex,
    makeConsoleCommand,
    makeSearchLink,
    dEvent,
    makeName,
}
