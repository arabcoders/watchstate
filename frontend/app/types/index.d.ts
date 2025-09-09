/**
 * Common error structure for API responses.
 */
export interface GenericError {
    error: {
        /** The error code. usually http status code */
        code: number
        /** The error message */
        message: string
    }
}

/**
 * Common response structure for API responses.
 */
export interface GenericResponse {
    info: {
        /** The response code. usually http status code */
        code: number
        /** The response message */
        message: string
    }
}

/**
 * Request options for the request utility function.
 * Extends the native RequestInit but allows specifying headers as a plain object.
 */
export interface RequestOptions extends Omit<RequestInit, 'headers'> {
    /** skip the `/v1/api` prefix */
    no_prefix?: boolean
    headers?: Record<string, string>
}

/**
 * Version response structure from /api/version endpoint.
 */
export interface VersionResponse {
    /** The version information */
    version: string,
    /** The git sha */
    sha: string,
    /** The build date */
    build: string,
    /** The git branch */
    branch: string,
    /** Whether running in a container */
    container: boolean
}

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

/**
 * Environment variable definition.
 */
export interface EnvVar {
    /** Environment variable key (e.g., 'WS_CRON_IMPORT') */
    key: string
    /** Current value of the variable */
    value?: string | number | boolean
    /** Description of what this variable does */
    description: string
    /** Data type expected for this variable */
    type: 'string' | 'int' | 'bool'
    /** Whether the value should be masked in the UI */
    mask: boolean
    /** Whether this variable is considered dangerous to modify */
    danger?: boolean
    /** Whether this variable is deprecated */
    deprecated?: boolean
}

/**
 * Represents an event object from the API.
 */
export interface EventsItem {
    /** Event ID */
    id: string
    /** Event name/type */
    event: string
    /** Event reference (optional) */
    reference?: string
    /** Event status code (0=pending, 1=running, 4=complete, etc.) */
    status: number
    /** Human-readable status name */
    status_name: string
    /** Creation timestamp */
    created_at: string
    /** Last update timestamp (optional) */
    updated_at?: string
    /** Event data payload (optional) */
    event_data?: Record<string, any>
    /** Event logs array (optional) */
    logs?: Array<string>
    /** Event options (optional) */
    options?: Record<string, any>
    /** Display toggle for event data (UI state) */
    _display?: boolean
    /** Delay in seconds (optional) */
    delay_by?: number
}

/**
 *  Represents a scheduled task item from the API.
 */
export interface TaskItem {
    /** Unique task name (used as key) */
    name: string
    /** Task description */
    description?: string
    /** Cron timer string */
    timer: string
    /** Command to run */
    command: string
    /** Arguments for the command */
    args?: string
    /** Whether the task is enabled */
    enabled: boolean
    /** Whether the task can be disabled */
    allow_disable: boolean
    /** Last run time (ISO string or null) */
    prev_run: string | null
    /** Next run time (ISO string or null) */
    next_run: string | null
    /** Whether the task is currently queued */
    queued?: boolean
}

/**
 * Represents a duplicate item from the /system/duplicate API.
 */
export interface DuplicateItem {
    /** Unique identifier for the item */
    id: number
    /** Type of media (e.g., 'movie', 'episode') */
    type: string
    /** Main title of the item */
    title: string
    /** Alternative content title if available */
    content_title?: string
    /** File path of the content */
    content_path?: string
    /** Full display title combining multiple sources */
    full_title?: string
    /** Whether the item has been watched/played */
    watched: boolean
    /** Unix timestamp of when the record was last updated */
    updated_at: number
    /** Array of backend names that have reported this item */
    reported_by: Array<string>
    /** Array of backend names that have NOT reported this item */
    not_reported_by: Array<string>
}

/**
 * Represents a backup file with metadata and download/restore functionality.
 */
export interface BackupItem {
    /** Original filename of the backup */
    filename: string
    /** File size in bytes */
    size: number
    /** Creation/modification date as ISO string */
    date: string
    /** Type of backup (e.g., 'automatic', 'manual') */
    type: string
}

/**
 * Represents a backends available for a specific user.
 */
export interface UserBackends {
    /** Username */
    user: string
    /** Array of backend names available for this user */
    backends: Array<string>
}

/**
 * /system/integrity API response item.
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
    integrity?: Array<{
        /** Backend name (e.g., 'plex', 'jellyfin', etc.) */
        backend: string
        /** Status of the file (true = exists, false = missing) */
        status: boolean
        /** Optional error or status message */
        message?: string
    }>
}

export interface GuidProvider {
    /** Provider name (e.g., 'tvdb', 'imdb') */
    guid: string
    validator: {
        /** Regex pattern string */
        pattern: string
        /** Example value */
        example: string
    }
}

export interface IgnoreItem {
    /** Unique rule identifier */
    rule: string
    /** Backend name */
    backend: string
    /** GUID provider */
    db: string
    /** GUID value */
    id: string
    /** Type of item (show, movie, episode) */
    type: string
    /** If true, rule is scoped */
    scoped: boolean
    /** If scoped, the id it is scoped to */
    scoped_to?: string | null
    /** Optional title for the rule */
    title?: string | null
    /** Creation date (ISO string) */
    created: string
}

/**
 * /system/parity API response item.
 */
export interface ParityItem {
    /** Unique record ID */
    id: string | number
    /** Type of item (e.g., 'movie', 'episode') */
    type: string
    /** Title of the content */
    title: string
    /** Optional subtitle/content title */
    content_title?: string
    /** Path to the content (maybe missing) */
    content_path?: string
    /** List of backend names that reported this item */
    reported_by: Array<string>
    /** List of backend names that did NOT report this item */
    not_reported_by: Array<string>
    /** Whether the item is marked as watched */
    watched: boolean
    /** Unix timestamp (seconds) when last updated */
    updated_at: number
}

/**
 * /changelog API response item.
 */
export interface ChangeSet {
    /** The git tag for this changeset */
    tag: string
    /** full tag SHA */
    full_sha: string
    /** Date of the tag */
    date: string
    /** Changelog messages */
    commits: Array<{
        /** Short commit SHA */
        sha: string
        /** Full commit SHA */
        full_sha: string
        /** Commit message */
        message: string
        /** Commit author */
        author: string
        /** Commit date */
        date: string
    }>
}


/**
 * /system/suppressor API response item.
 */
export interface SuppressionItem {
    id: string | null
    rule: string
    example: string
    type: 'contains' | 'regex'
}

/**
 * Represents a sub-user in the user mapping system.
 */
export interface SubUser {
    /** Unique identifier for the user */
    id: string
    /** Backend name this user belongs to */
    backend: string
    /** Normalized username */
    username: string
    /** Real/display name of the user */
    real_name: string
    /** Whether this user requires PIN authentication */
    protected?: boolean
    /** User-specific options (mainly for Plex PIN) */
    options?: {
        /** Plex user PIN (4 digits) */
        PLEX_USER_PIN?: string
        [key: string]: any
    }
}
