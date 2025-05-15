import { useStorage } from "@vueuse/core";

const token = useStorage('token', '')
const api_user = useStorage('api_user', 'main')

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
    options = options || {};
    options.method = options.method || 'GET';
    options.headers = options.headers || {};

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

    return fetch(`/v1/api${url}`, options);
}

