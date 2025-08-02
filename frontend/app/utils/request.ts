import {useStorage} from "@vueuse/core"

type PlainHeaders = Record<string, string>

export interface RequestOptions extends Omit<RequestInit, 'headers'> {
    /** skip the `/v1/api` prefix */
    no_prefix?: boolean
    headers?: PlainHeaders // constrain to a plain record
}

const token = useStorage('token', '')
const api_user = useStorage('api_user', 'main')

/**
 * Request content from the API. This function will automatically add the API token to the request headers.
 * And prefix the URL with the API URL and path.
 *
 * @param url {string}
 * @param options {RequestOptions}
 *
 * @returns {Promise<Response>}
 */
export default async function request(url: string, options: RequestOptions = {}): Promise<Response> {
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
}

