/**
 * Types for the File Integrity page (integrity.vue)
 */

/**
 * Status of a file integrity check for a specific backend
 */
export interface IntegrityBackendStatus {
    /** Backend name (e.g., 'plex', 'jellyfin', etc.) */
    backend: string
    /** Status of the file (true = exists, false = missing) */
    status: boolean
    /** Optional error or status message */
    message?: string
}

/**
 * Main item displayed in the integrity list
 */
export interface IntegrityItem {
    /** Unique record ID */
    id: number
    /** Type of item (e.g., 'movie', 'episode') */
    type: string
    /** Title of the content */
    title: string
    /** Optional subtitle/content title */
    content_title?: string
    /** Path to the file */
    content_path?: string
    /** True if the item has been watched */
    watched: boolean
    /** Unix timestamp of last update */
    updated_at: number
    /** Backends that reported this item */
    reported_by: Array<string>
    /** Backends that did NOT report this item */
    not_reported_by: Array<string>
    /** File integrity status per backend */
    integrity?: Array<IntegrityBackendStatus>
    /** UI state: expanded title */
    expand_title?: boolean
    /** UI state: expanded path */
    expand_path?: boolean
    /** UI state: show raw data */
    showRawData?: boolean
}

/**
 * API response for /system/integrity
 */
export interface IntegrityApiResponse {
    /** List of integrity items */
    items: Array<IntegrityItem>
    /** True if the response was served from cache */
    fromCache?: boolean
    /** Error information, if any */
    error?: {
        code: string | number
        message: string
    }
}
