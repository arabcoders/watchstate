/**
 * Represents a single HTTP header key-value pair.
 */
export interface Header {
    /** Header key (e.g., 'Authorization') */
    key: string;
    /** Header value (e.g., 'Bearer ...') */
    value: string;
}

/**
 * Represents a request item for the URL checker form.
 */
export interface Item {
    /** The URL to check */
    url: string;
    /** HTTP method (GET, POST, etc.) */
    method: string;
    /** List of headers to send with the request */
    headers: Array<Header>;
}

/**
 * Represents a pre-defined template for the URL checker.
 */
export interface Template {
    /** Template ID */
    id: number;
    /** Template key/label */
    key: string;
    /** The request item to use for this template */
    override: Item;
}

/**
 * Represents the request data sent to the backend for checking a URL.
 */
export interface RequestData {
    /** The URL being checked */
    url: string;
    /** HTTP method */
    method: string;
    /** Headers as a key-value map */
    headers: Record<string, string>;
}

/**
 * Represents the response data received from the backend after checking a URL.
 */
export interface ResponseData {
    /** HTTP status code (e.g., 200, 404) */
    status: number | null;
    /** Response headers as a key-value map */
    headers: Record<string, string>;
    /** Response body as a string */
    body: string;
}

/**
 * Represents the full result of a URL check, including request and response.
 */
export interface URLCheckResponse {
    /** The request that was sent */
    request: RequestData;
    /** The response that was received */
    response: ResponseData;
}
