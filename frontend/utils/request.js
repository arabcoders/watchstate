import {useStorage} from "@vueuse/core";

const api_path = useStorage('api_path', '/v1/api')
const api_url = useStorage('api_url', '')
const api_token = useStorage('api_token', '')

/**
 * Request content from the API. This function will automatically add the API token to the request headers.
 * And prefix the URL with the API URL and path.
 *
 * @param url {string}
 * @param options {RequestInit}
 *
 * @returns {Promise<Response>}
 */
export default async function request(url, options = {}) {
    if (!api_token.value) {
        throw new Error('API token is not set');
    }
    options = options || {};
    options.method = options.method || 'GET';
    options.headers = options.headers || {};
    if (options.headers['Authorization'] === undefined) {
        options.headers['Authorization'] = 'Bearer ' + api_token.value;
    }
    if (options.headers['Content-Type'] === undefined) {
        options.headers['Content-Type'] = 'application/json';
    }
    if (options.headers['Accept'] === undefined) {
        options.headers['Accept'] = 'application/json';
    }
    return fetch(`${api_url.value}${api_path.value}${url}`, options);
}

