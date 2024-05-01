const ag = (obj, path, defaultValue = null, separator = '.') => {
    const keys = path.split(separator);
    let at = obj;

    for (let key of keys) {
        if (typeof at === 'object' && at !== null && key in at) {
            at = at[key];
        } else {
            return defaultValue;
        }
    }

    return at;
}

const ag_set = (obj, path, value, separator = '.') => {
    const keys = path.split(separator);
    let at = obj;

    while (keys.length > 0) {
        if (keys.length === 1) {
            if (typeof at === 'object' && at !== null) {
                at[keys.shift()] = value;
            } else {
                throw new Error(`Cannot set value at this path (${path}) because it's not an object.`);
            }
        } else {
            const key = keys.shift();
            if (!at[key]) {
                at[key] = {};
            }
            at = at[key];
        }
    }

    return obj;
}

export {ag_set, ag}
