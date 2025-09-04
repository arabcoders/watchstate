/**
 * Backend options interface based on servers.spec.php
 */
export interface BackendOptions {
    /** List of library IDs to ignore when syncing */
    ignore?: string
    /** How many items to get per request when syncing (min 300) */
    LIBRARY_SEGMENT?: number
    /** Plex admin token to use to manage limited users */
    ADMIN_TOKEN?: string
    /** Whether to dump the webhook payload into json file */
    DUMP_PAYLOAD?: boolean
    /** Whether to enable debug tracing when operations are running */
    DEBUG_TRACE?: boolean
    /** Whether to import metadata only when syncing */
    IMPORT_METADATA_ONLY?: boolean
    /** Enable dry-run changes will not be committed in supported context */
    DRY_RUN?: boolean
    /** The max range a single record/episode can cover (default is 5) */
    MAX_EPISODE_RANGE?: number
    /** HTTP client timeout configuration */
    client?: {
        /** The http timeout per request to the backend */
        timeout?: number
        /** The http version to use when making requests to the backend */
        http_version?: number
    }
    /** Whether the token has limited access */
    is_limited_token?: boolean
    /** Plex user PIN */
    PLEX_USER_PIN?: number
    /** Mark the plex user as home user */
    plex_external_user?: boolean
    /** Mark the plex user as invited guest */
    plex_guest_user?: boolean
    /** Plex admin user PIN */
    ADMIN_PLEX_USER_PIN?: number
    /** Plex.tv user UUID */
    plex_user_uuid?: string
    /** The original backend name of which this backend created from */
    ALT_NAME?: string
    /** The original user ID of which this user is sub user of */
    ALT_ID?: number
    /** The plex username */
    plex_user_name?: number
    /** Whether to use old progress endpoint for plex */
    use_old_progress_endpoint?: boolean
}

/**
 * Represents a backend being added via the BackendAdd component.
 */
export interface Backend {
    /** Backend name (unique, lower case, a-z, 0-9, _) */
    name: string
    /** Backend type (e.g., 'plex', 'jellyfin', etc.) */
    type: string
    /** Backend URL (maybe empty for some types) */
    url: string
    /** API key or token */
    token: string
    /** Backend UUID/identifier */
    uuid: string
    /** User ID associated with this backend */
    user: string
    /** Import options */
    import: {
        enabled: boolean
    }
    /** Export options */
    export: {
        enabled: boolean
    }
    /** Webhook options */
    webhook: {
        match: {
            user: boolean
            uuid: boolean
        }
    }
    /** Additional backend options with proper typing */
    options: BackendOptions
}

/**
 * Represents a user for the user dropdown.
 */
export interface BackendUser {
    /** User ID */
    id: string
    /** User name */
    name: string
    /** Optional token error */
    token_error?: string
    /** Optional token */
    token?: string
}

/**
 * Represents a server for the server dropdown.
 */
export interface BackendServer {
    /** Server UUID */
    uuid: string
    /** Server URI */
    uri: string
    /** Server name */
    name: string
    /** Server identifier */
    identifier: string
}
