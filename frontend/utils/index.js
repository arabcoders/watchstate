const ag = (obj, path, defaultValue = null, separator = '.') => {
    const keys = path.split(separator)
    let at = obj

    for (let key of keys) {
        if (typeof at === 'object' && at !== null && key in at) {
            at = at[key]
        } else {
            return defaultValue
        }
    }

    return at
}

const ag_set = (obj, path, value, separator = '.') => {
    const keys = path.split(separator)
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
const humanFileSize = (bytes = 0, showUnit = true, decimals = 2, mod = 1000) => {
    const sz = 'BKMGTP'
    const factor = Math.floor((bytes.toString().length - 1) / 3)
    return `${(bytes / (mod ** factor)).toFixed(decimals)}${showUnit ? sz[factor] : ''}`
}

const awaitElement = (sel, callback) => {
    let interval = undefined;

    let $elm = document.querySelector(sel)

    if ($elm) {
        return callback(sel, $elm)
    }

    interval = setInterval(() => {
        let $elm = document.querySelector(sel);
        if ($elm) {
            clearInterval(interval);
            callback(sel, $elm);
        }
    }, 200)
}


const ucFirst = (str) => str.charAt(0).toUpperCase() + str.slice(1);

export {ag_set, ag, humanFileSize, awaitElement, ucFirst}
