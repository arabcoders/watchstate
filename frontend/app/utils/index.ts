import { useStorage } from '@vueuse/core';
import { useToast } from 'vue-toastification';
import { toRaw } from 'vue';
import { navigateTo } from '#app';
import { useDialog } from '~/composables/useDialog';
import type { GenericError, JsonObject, JsonValue, PaginationItem, RequestOptions } from '~/types';

type ToastMethod = 'info' | 'success' | 'warning' | 'error';
type NotificationType = ToastMethod | string;
type ToastOptionValue = JsonValue | ((...args: Array<unknown>) => void);

type ToastOptions = {
  timeout?: number;
  [key: string]: ToastOptionValue | undefined;
};

const toast = useToast();

const AG_SEPARATOR = '.';

const TOOLTIP_DATE_FORMAT = 'YYYY-MM-DD h:mm:ss A';

const guid_links = {
  episode: {
    imdb: 'https://www.imdb.com/title/{_guid}',
    tmdb: 'https://www.themoviedb.org/tv/{parent.guid_tmdb}/season/{season}/episode/{episode}',
    tvdb: 'https://thetvdb.com/dereferrer/episode/{_guid}',
    tvmaze: 'https://www.tvmaze.com/episodes/{_guid}',
    anidb: 'https://anidb.net/episode/{_guid}',
    youtube_video: 'https://www.youtube.com/watch?v={_guid}',
  },
  series: {
    imdb: 'https://www.imdb.com/title/{_guid}',
    tmdb: 'https://www.themoviedb.org/tv/{_guid}',
    tvdb: 'https://thetvdb.com/dereferrer/series/{_guid}',
    tvmaze: 'https://www.tvmaze.com/shows/{_guid}/-',
    anidb: 'https://anidb.net/anime/{_guid}',
    youtube_channel: 'https://www.youtube.com/channel/{_guid}',
    youtube_playlist: 'https://www.youtube.com/playlist?list={_guid}',
  },
  movie: {
    imdb: 'https://www.imdb.com/title/{_guid}',
    tmdb: 'https://www.themoviedb.org/movie/{_guid}',
    tvdb: 'https://thetvdb.com/dereferrer/movie/{_guid}',
    anidb: 'https://anidb.net/anime/{_guid}',
    youtube_video: 'https://www.youtube.com/watch?v={_guid}',
  },
};

const YT_CH = new RegExp('(UC|HC)[a-zA-Z0-9\\-_]{22}');
const YT_PL = new RegExp('PL[^\\[\\]]{32}|PL[^\\[\\]]{16}|(UU|FL|LP|RD)[^\\[\\]]{22}');

/**
 * Get value from object or function
 */
const getValue = <T>(obj: (() => T) | T): T =>
  typeof obj === 'function' ? (obj as () => T)() : obj;

/**
 * Get value from object or function and return default value if it's undefined  or null
 */
const ag = <T = JsonValue>(obj: JsonObject, path: string, defaultValue: T = null as T): T => {
  const keys = path.split(AG_SEPARATOR);
  let at: JsonValue = obj;

  for (const key of keys) {
    if (typeof at === 'object' && at !== null && !Array.isArray(at) && key in at) {
      const current: JsonValue | undefined = (at as JsonObject)[key];
      if (current === undefined) {
        return getValue(defaultValue);
      }
      at = current;
    } else {
      return getValue(defaultValue);
    }
  }

  return getValue(at === null ? defaultValue : (at as T));
};

/**
 * Request content from the API. This function will automatically add the API token to the request headers.
 * And prefix the URL with the API URL and path.
 *
 * @param {string} url
 * @param {RequestOptions} options
 *
 * @returns {Promise<Response>}
 */
const request = async (url: string, options: RequestOptions = {}): Promise<Response> => {
  const token = useStorage('token', '');
  const api_user = useStorage('api_user', 'main');

  options = options || {};
  options.method = options.method || 'GET';
  options.headers = options.headers || {};

  const no_prefix = options?.no_prefix || false;
  if (options?.no_prefix) {
    delete options.no_prefix;
  }

  if (options.headers['Authorization'] === undefined && token.value) {
    options.headers['Authorization'] = 'Token ' + token.value;
  }

  if (options.headers['Content-Type'] === undefined) {
    options.headers['Content-Type'] = 'application/json';
  }

  if (options.headers['Accept'] === undefined) {
    options.headers['Accept'] = 'application/json';
  }

  if (options.headers['X-User'] === undefined) {
    options.headers['X-User'] = api_user.value;
  }

  return fetch(no_prefix ? url : `/v1/api${url}`, options);
};

/**
 * Convert bytes to human-readable file size
 */
const humanFileSize = (
  bytes: number = 0,
  showUnit: boolean = true,
  decimals: number = 2,
  mod: number = 1000,
): string => {
  const sz = 'BKMGTP';
  const factor = Math.floor((bytes.toString().length - 1) / 3);
  return `${(bytes / mod ** factor).toFixed(decimals)}${showUnit ? sz[factor] : ''}`;
};

/**
 * Wait for an element to be loaded in the DOM
 */
const awaitElement = (sel: string, callback: (sel: string, elm: Element) => void): void => {
  const $elm = document.querySelector(sel);

  if ($elm) {
    callback(sel, $elm);
    return;
  }

  const interval = setInterval(() => {
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
  type: NotificationType,
  title: string,
  text: string,
  duration: number = 3000,
  opts: ToastOptions = {},
): void => {
  let method: ToastMethod = 'info';
  let options: ToastOptions = {
    timeout: duration,
  };

  if (opts) {
    options = { ...options, ...opts };
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
  toast[method](text || title, options);
};

/**
 * Replace tags in text with values from context
 */
const r = (text: string, context: JsonObject = {}): string => {
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

  const replacements: Record<string, string> = {};

  matches.forEach((match) => {
    replacements[match] = String(ag(context, match.slice(1, -1), ''));
  });

  for (const key in replacements) {
    const replacement = replacements[key] ?? '';
    text = text.replace(new RegExp(key, 'g'), replacement);
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
  data: JsonObject = {},
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

  return link == null ? '' : r(link, { _guid: guid, ...toRaw(data) } as JsonObject);
};

/**
 * Format duration
 */
const formatDuration = (milliseconds: number): string => {
  milliseconds = parseInt(milliseconds.toString());
  let seconds = Math.floor(milliseconds / 1000);
  let minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  seconds %= 60;
  minutes %= 60;

  return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
};

const copyText = (str: string | number, showNotification: boolean = true): void => {
  if (navigator.clipboard) {
    navigator.clipboard
      .writeText(String(str))
      .then(() => {
        if (!showNotification) {
          return;
        }
        notification('success', 'Success', 'Text copied to clipboard.');
      })
      .catch((error) => {
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
const dEvent = (eventName: string, detail: JsonObject = {}): boolean => {
  if (!window) {
    return false;
  }
  return window.dispatchEvent(new CustomEvent(eventName, { detail }));
};

/**
 * Make name
 */
const makeName = (item: JsonObject, asMovie: boolean = false): string | null => {
  if (!item) {
    return null;
  }
  const year = ag(item, 'year', '0000');
  const title = ag(item, 'title', '??');
  const type = ag(item, 'type', 'movie');

  if (['show', 'movie'].includes(type) || asMovie) {
    return r('{title} ({year})', { title, year });
  }

  return r('{title} ({year}) - {season}x{episode}', {
    title,
    year,
    season: ag(item, 'season', 0).toString().padStart(2, '0'),
    episode: ag(item, 'episode', 0).toString().padStart(3, '0'),
  });
};

const makePagination = (current: number, last: number, delta: number = 5): PaginationItem[] => {
  const pagination: PaginationItem[] = [];

  if (last < 2) {
    return pagination;
  }

  const strR = '-'.repeat(9 + `${last}`.length);

  const left = current - delta,
    right = current + delta + 1;

  for (let i = 1; i <= last; i++) {
    if (i === 1 || i === last || (i >= left && i < right)) {
      if (i === left && i > 2) {
        pagination.push({
          page: 0,
          text: strR,
          selected: false,
        });
      }

      pagination.push({
        page: i,
        text: `Page #${i}`,
        selected: i === current,
      });

      if (i === right - 1 && i < last - 1) {
        pagination.push({
          page: 0,
          text: strR,
          selected: false,
        });
      }
    }
  }

  return pagination;
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

/**
 * Parse API response with generic type support
 * @template T The expected response type for successful requests
 * @param r The Response object to parse
 * @returns Promise resolving to either the typed response or an error object
 */
const parse_api_response = async <T = JsonObject>(r: Response): Promise<T | GenericError> => {
  try {
    return (await r.json()) as T;
  } catch {
    return { error: { code: r.status, message: r.statusText } } as GenericError;
  }
};

type HistoryLogItem = {
  item_id?: string | number | null;
  user?: string | null;
};

const goto_history_item = async (item: HistoryLogItem): Promise<void> => {
  if (!item.item_id) {
    return;
  }

  const api_user = useStorage('api_user', 'main');

  const log_user = item?.user ?? api_user.value;

  if (log_user !== api_user.value) {
    const dialog = useDialog();
    const { status } = await dialog.confirmDialog({
      title: 'Switch User',
      message: `This item is related to '${item.user}' user. And you are currently using '${api_user.value}' Do you want to switch to view the item?`,
    });

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
  event_data: JsonObject = {},
  delay: number = 0,
  opts: JsonObject = {},
): Promise<number> => {
  let reqData: JsonObject = { event };
  if (event_data) {
    reqData.event_data = event_data;
  }

  delay = parseInt(delay.toString());

  if (0 !== delay) {
    reqData.DELAY_BY = delay;
  }

  if (opts) {
    reqData = { ...reqData, ...opts };
  }

  return (await request(`/system/events`, { method: 'POST', body: JSON.stringify(reqData) }))
    .status;
};

const enableOpacity = (): void => {
  const bg_enable = useStorage('bg_enable', true);
  const bg_opacity = useStorage('bg_opacity', 0.95);
  if (bg_enable.value && bg_opacity.value) {
    document.querySelector('body')?.setAttribute('style', `opacity: ${bg_opacity.value}`);
  }
};

const disableOpacity = (): void => {
  const bg_enable = useStorage('bg_enable', true);
  if (bg_enable.value) {
    document.querySelector('body')?.setAttribute('style', 'opacity: 1');
  }
};

/**
 * Waits for the test function to return a truthy value.
 *
 * @param test - The function to test
 * @param timeout_ms - The maximum time to wait in milliseconds.
 * @param frequency - The frequency to check the test function in milliseconds.
 *
 * @returns The result of the test function.
 */
const awaiter = async <T>(
  test: () => T,
  timeout_ms: number = 20 * 1000,
  frequency: number = 200,
): Promise<T | false> => {
  const isNotTruthy = (val: unknown): boolean => {
    if (val === undefined || val === false || val === null) {
      return true;
    }

    if (typeof (val as { length?: number }).length === 'number') {
      return (val as { length: number }).length === 0;
    }

    return false;
  };
  const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
  const endTime: number = Date.now() + timeout_ms;

  let result = test();

  while (isNotTruthy(result)) {
    if (Date.now() > endTime) {
      return false;
    }
    await sleep(frequency);
    result = test();
  }

  return result;
};

const makeEventName = (id: string | number): string => String(id).replace(/-/g, '').slice(0, 12);

const getEventStatusClass = (status: number): string => {
  switch (status) {
    case 0:
      return 'is-light has-text-dark';
    case 1:
      return 'is-warning';
    case 2:
      return 'is-success';
    case 3:
      return 'is-danger';
    case 4:
      return 'is-danger is-light';
    default:
      return 'is-light has-text-dark';
  }
};

export {
  r,
  ag,
  request,
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
  basename,
  parse_api_response,
  goto_history_item,
  queue_event,
  enableOpacity,
  disableOpacity,
  awaiter,
  makeEventName,
  getEventStatusClass,
};
