import {useStorage} from '@vueuse/core';
import request from '~/utils/request';
import {useToast} from 'vue-toastification'
import {toRaw} from 'vue';
import {navigateTo} from '#app'
import {useDialog} from '~/composables/useDialog'

const toast = useToast();

const AG_SEPARATOR = '.';

const TOOLTIP_DATE_FORMAT = 'YYYY-MM-DD h:mm:ss A';

interface GuidLinks {
    [type: string]: {
        [source: string]: string;
    };
}

const guid_links: GuidLinks = {
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
 */
const getValue = <T>(obj: (() => T) | T): T => typeof obj === 'function' ? (obj as (() => T))() : obj

/**
 * Get value from object or function and return default value if it's undefined  or null
 */
const ag = (obj: Record<string, any>, path: string, defaultValue: any = null): any => {
    const keys = path.split(AG_SEPARATOR);
    let at = obj;

    for (const key of keys) {
        if (typeof at === 'object' && at !== null && key in at) {
            at = at[key];
        } else {
            return getValue(defaultValue);
        }
    }

    return getValue(at === null ? defaultValue : at);
};

/**
 * Set value in object by path
 */
const ag_set = (obj: Record<string, any>, path: string, value: any): Record<string, any> => {
    const keys = path.split(AG_SEPARATOR);
    let at = obj;

    while (keys.length > 0) {
        if (keys.length === 1) {
            if (typeof at === 'object' && at !== null) {
                at[keys.shift() as string] = value;
            } else {
                throw new Error(`Cannot set value at this path (${path}) because it's not an object.`);
            }
        } else {
            const key = keys.shift() as string;
            if (!at[key]) {
                at[key] = {};
            }
            at = at[key];
        }
    }

    return obj;
};

/**
 * Convert bytes to human-readable file size
 */
const humanFileSize = (
    bytes: number = 0,
    showUnit: boolean = true,
    decimals: number = 2,
    mod: number = 1000
): string => {
    const sz = 'BKMGTP';
    const factor = Math.floor((bytes.toString().length - 1) / 3);
    return `${(bytes / (mod ** factor)).toFixed(decimals)}${showUnit ? sz[factor] : ''}`;
};

/**
 * Wait for an element to be loaded in the DOM
 */
const awaitElement = (
    sel: string,
    callback: (sel: string, elm: Element) => void
): void => {
    let interval: ReturnType<typeof setInterval> | undefined;
    let $elm = document.querySelector(sel);

    if ($elm) {
        callback(sel, $elm);
        return;
    }

    interval = setInterval(() => {
        const $elm = document.querySelector(sel);
        if ($elm) {
            clearInterval(interval);
            callback(sel, $elm);
        }
    }, 200);
};

/**
 * Uppercase the first letter of a string
 */
const ucFirst = (str: string): string => {
    if (!str) {
        return str;
    }
    return String(str).charAt(0).toUpperCase() + String(str).slice(1);
};

/**
 * Display a notification
 */
const notification = (
    type: string,
    title: string,
    text: string,
    duration: number = 3000,
    opts: Record<string, any> = {}
): void => {
    let method = '';
    let options = {
        timeout: duration,
    };

    if (opts) {
        options = {...options, ...opts};
    }

    switch (type.toLowerCase()) {
        case 'info':
        default:
            method = 'info';
            break;
        case 'success':
            method = 'success';
            break;
        case 'warning':
            method = 'warning';
            break;
        case 'error':
            method = 'error';
            if (duration === 3000) {
                options.timeout = 10000;
            }
            break;
    }
    (toast as any)[method](text, options);
};

/**
 * Replace tags in text with values from context
 */
const r = (text: string, context: Record<string, any> = {}): string => {
    const tagLeft = '{';
    const tagRight = '}';

    if (!text.includes(tagLeft) || !text.includes(tagRight)) {
        return text;
    }

    const pattern = new RegExp(`${tagLeft}([\\w_.]+)${tagRight}`, 'g');
    const matches = text.match(pattern);

    if (!matches) {
        return text;
    }

    const replacements: Record<string, any> = {};

    matches.forEach(match => {
        replacements[match] = ag(context, match.slice(1, -1), '');
    });

    for (const key in replacements) {
        text = text.replace(new RegExp(key, 'g'), replacements[key]);
    }

    return text;
};

/**
 * Make GUID link
 */
const makeGUIDLink = (
    type: string,
    source: string,
    guid: string,
    data: Record<string, any>
): string => {
    if (source === 'youtube') {
        if (YT_CH.test(guid)) {
            source = 'youtube_channel';
        } else if (YT_PL.test(guid)) {
            source = 'youtube_playlist';
        } else {
            source = 'youtube_video';
        }
    }

    type = type.toLowerCase();

    if (type === 'show') {
        type = 'series';
    }

    const link = ag(guid_links, `${type}.${source}`, null);

    return link == null ? '' : r(link, {_guid: guid, ...toRaw(data)});
};

/**
 * Format duration
 */
const formatDuration = (milliseconds: number): string => {
    milliseconds = parseInt(milliseconds.toString());
    let seconds = Math.floor(milliseconds / 1000);
    let minutes = Math.floor(seconds / 60);
    let hours = Math.floor(minutes / 60);
    seconds %= 60;
    minutes %= 60;

    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
};

const copyText = (str: string | number, showNotification: boolean = true): void => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(String(str)).then(() => {
            if (!showNotification) {
                return;
            }
            notification('success', 'Success', 'Text copied to clipboard.');
        }).catch((error) => {
            console.error('Failed to copy: ', error);
            if (!showNotification) {
                return;
            }
            notification('error', 'Error', 'Failed to copy text to clipboard.');
        });
        return;
    }

    const el = document.createElement('textarea');
    el.value = String(str);
    document.body.appendChild(el);
    el.select();
    // noinspection JSDeprecatedSymbols
    document.execCommand('copy');
    document.body.removeChild(el);
    if (!showNotification) {
        return;
    }
    notification('success', 'Success', 'Text copied to clipboard.');
};

const makeConsoleCommand = (cmd: string, run: boolean = false): string => {
    const params = new URLSearchParams();
    if (run) {
        params.append('run', 'true');
    }
    params.append('cmd', btoa(cmd));
    return `/console?${params.toString()}`;
};

const stringToRegex = (str: string): RegExp => {
    const match1 = str.match(/\/(.+)\/.*/);
    const match2 = str.match(/\/.+\/(.*)/);
    if (!match1 || !match2) {
        throw new Error('Invalid regex string');
    }
    return new RegExp(String(match1[1]), match2[1]);
};

/**
 * Make history search link.
 */
const makeSearchLink = (type: string, query: string): string => {
    const params = new URLSearchParams();
    params.append('perpage', '50');
    params.append('page', '1');
    params.append('q', query);
    params.append('key', type);
    return `/history?${params.toString()}`;
};

/**
 * Dispatch event.
 */
const dEvent = (eventName: string, detail: Record<string, any> = {}): boolean =>
    window.dispatchEvent(new CustomEvent(eventName, {detail}));

/**
 * Make name
 */
const makeName = (item: Record<string, any>, asMovie: boolean = false): string | null => {
    if (!item) {
        return null;
    }
    const year = ag(item, 'year', '0000');
    const title = ag(item, 'title', '??');
    const type = ag(item, 'type', 'movie');

    if (['show', 'movie'].includes(type) || asMovie) {
        return r('{title} ({year})', {title, year});
    }

    return r('{title} ({year}) - {season}x{episode}', {
        title,
        year,
        season: ag(item, 'season', 0).toString().padStart(2, '0'),
        episode: ag(item, 'episode', 0).toString().padStart(3, '0'),
    });
};

/**
 * Make pagination
 */
interface PaginationItem {
    page: number;
    text: string;
    selected: boolean;
}

const makePagination = (
    current: number,
    last: number,
    delta: number = 5
): PaginationItem[] => {
    const pagination: PaginationItem[] = [];

    if (last < 2) {
        return pagination;
    }

    const strR = '-'.repeat(9 + `${last}`.length);

    const left = current - delta, right = current + delta + 1;

    for (let i = 1; i <= last; i++) {
        if (i === 1 || i === last || (i >= left && i < right)) {
            if (i === left && i > 2) {
                pagination.push({
                    page: 0, text: strR, selected: false,
                });
            }

            pagination.push({
                page: i, text: `Page #${i}`, selected: i === current
            });

            if (i === right - 1 && i < last - 1) {
                pagination.push({
                    page: 0, text: strR, selected: false,
                });
            }
        }
    }

    return pagination;
};

const makeSecret = (len: number = 8): string => {
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    let counter = 0;
    while (counter < len) {
        result += characters.charAt(Math.floor(Math.random() * characters.length));
        counter += 1;
    }
    return result;
};

/**
 * Explode string by delimiter.
 */
const explode = (
    delimiter: string,
    string: string,
    limit: number | undefined = undefined
): string[] => {
    if (delimiter === '') {
        return [string];
    }

    const parts = string.split(delimiter);

    if (limit === undefined || limit === 0) {
        return parts;
    }

    if (limit > 0) {
        return parts.slice(0, limit - 1).concat(parts.slice(limit - 1).join(delimiter));
    }

    if (limit < 0) {
        return parts.slice(0, limit);
    }

    return parts;
};

const basename = (path: string, ext: string = ''): string => {
    if (!path) {
        return '';
    }
    const segments = path.replace(/\\/g, '/').split('/');
    let base = segments.pop() || '';
    while (segments.length && base === '') {
        base = segments.pop() || '';
    }
    if (ext && base.endsWith(ext) && base !== ext) {
        base = base.substring(0, base.length - ext.length);
    }
    return base;
};

const parse_api_response = async (r: Response): Promise<any> => {
    try {
        return await r.json();
    } catch (e) {
        return {error: {code: r.status, message: r.statusText}};
    }
};

const goto_history_item = async (item: Record<string, any>): Promise<void> => {
    if (!item.item_id) {
        return;
    }

    const api_user = useStorage('api_user', 'main');

    const log_user = item?.user ?? api_user.value;

    if (log_user !== api_user.value) {
        const dialog = useDialog();
        const {status} = await dialog.confirmDialog({
            title: 'Switch User',
            message: `This item is related to '${item.user}' user. And you are currently using '${api_user.value}' Do you want to switch to view the item?`,
        })

        if (true !== status) {
            return;
        }

        api_user.value = log_user;
    }

    await navigateTo(`/history/${item.item_id}`);
};

/**
 * Queue event.
 */
const queue_event = async (
    event: string,
    event_data: Record<string, any> = {},
    delay: number = 0,
    opts: Record<string, any> = {}
): Promise<number> => {
    let reqData: Record<string, any> = {event};
    if (event_data) {
        reqData.event_data = event_data;
    }

    delay = parseInt(delay.toString());

    if (delay !== 0) {
        reqData.DELAY_BY = delay;
    }

    if (opts) {
        reqData = {...reqData, ...opts};
    }

    const resp = await request(`/system/events`, {
        method: 'POST', body: JSON.stringify(reqData)
    });

    return resp.status;
};

const enableOpacity = (): void => {
    const bg_enable = useStorage('bg_enable', true);
    const bg_opacity = useStorage('bg_opacity', 0.95);
    if (bg_enable.value && bg_opacity.value) {
        document.querySelector('body')?.setAttribute("style", `opacity: ${bg_opacity.value}`);
    }
};

const disableOpacity = (): void => {
    const bg_enable = useStorage('bg_enable', true);
    if (bg_enable.value) {
        document.querySelector('body')?.setAttribute("style", "opacity: 1");
    }
};

const makeAPIURL = (
    path: string,
    params: Record<string, any> = {},
    opts: { no_prefix?: boolean, no_token?: boolean } = {}
): string => {
    const token = useStorage('token', '');
    const api_user = useStorage('api_user', 'main');

    let no_prefix = false;
    let no_token = false;

    if (opts?.no_prefix) {
        no_prefix = opts.no_prefix;
    }
    if (opts?.no_token) {
        no_token = opts.no_token;
    }

    if (!path.startsWith('/')) {
        path = `/${path}`;
    }

    const req_params = new URLSearchParams(params || {});

    if (!no_token && token.value) {
        req_params.append('ws_token', token.value);
    }

    if (api_user.value !== 'main') {
        req_params.append('user', api_user.value);
    }

    let url = path;
    if (req_params.toString()) {
        url += `?${req_params.toString()}`;
    }
    return no_prefix ? url : '/v1/api' + url;
};

export {
    r,
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
    makePagination,
    TOOLTIP_DATE_FORMAT,
    makeSecret,
    explode,
    basename,
    parse_api_response,
    goto_history_item,
    queue_event,
    enableOpacity,
    disableOpacity,
    makeAPIURL,
};
