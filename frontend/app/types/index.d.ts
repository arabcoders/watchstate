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
 * Make pagination
 */
export interface PaginationItem {
    page: number;
    text: string;
    selected: boolean;
}

/**
 * Generic pagination structure used across multiple API endpoints.
 */
export interface PaginationInfo {
    /** Current page number */
    current_page: number
    /** Items per page */
    perpage: number
    /** Total number of items */
    total: number
    /** Total number of pages */
    last_page?: number
    /** Previous page URL */
    prev_url?: string | null
    /** Next page URL */
    next_url?: string | null
    /** First page URL */
    first_url?: string
    /** Last page URL */
    last_url?: string
}

/**
 * Generic API response wrapper with pagination.
 */
export interface PaginatedResponse<T> {
    /** Array of items */
    items: Array<T>
    /** Pagination information */
    paging: PaginationInfo
}

/**
 * Generic searchable field definition used in multiple endpoints.
 */
export interface SearchableField {
    /** Unique field identifier */
    field: string
    /** Human-readable field name */
    name: string
    /** Field description */
    description: string
    /** Field data type */
    type: 'text' | 'number' | 'boolean' | 'date' | 'json'
}

/**
 * Generic command structure used for utility commands.
 */
export interface UtilityCommand {
    /** Command ID number for display */
    id: number
    /** Human-readable command title */
    title: string
    /** Command template with placeholders */
    command: string
    /** Optional state key to check if command is available */
    state_key?: string
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
 * Log file item structure from /api/logs endpoint.
 */
export interface LogItem {
    /** The log file name */
    filename: string
    /** The log type (e.g., 'app', 'task', 'access', 'webhook') */
    type: string
    /** The log date in YYYYMMDD format */
    date: string
    /** The file size in bytes */
    size: number
    /** The last modified timestamp */
    modified: string
}

/**
 * Individual log entry structure from log file content.
 */
export interface LogEntry {
    /** Unique identifier for the log entry */
    id: string
    /** Associated item ID if available */
    item_id: string | null
    /** User associated with the log entry */
    user: string | null
    /** Backend associated with the log entry */
    backend: string | null
    /** Timestamp of the log entry */
    date: string | null
    /** The log message text */
    text: string
}

/**
 * Log file content response from /api/log/[filename] endpoint.
 */
export interface LogResponse {
    /** The log file name */
    filename: string
    /** Current offset position */
    offset: number
    /** Next offset for pagination, null if no more data */
    next: number | null
    /** Maximum number of lines in the file */
    max: number
    /** Array of log entries */
    lines: Array<LogEntry>
}

/**
 * Backend options interface based on servers.spec.php
 * All properties are optional and strictly typed - no dynamic properties allowed
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
        /** Whether to verify the backend host SSL certificate */
        verify_host?: boolean
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
    plex_user_name?: string
    /** Prevent backend from marking items as unplayed */
    DISABLE_MARK_UNPLAYED?: boolean
}

/**
 * Represents a single backend item response.
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
        /** Whether import is enabled */
        enabled: boolean,
        /** Last successful import timestamp (ISO string) */
        lastSync?: string
    }
    /** Export options */
    export: {
        /** Whether export is enabled */
        enabled: boolean
        /** Last successful export timestamp (ISO string) */
        lastSync?: string
    }
    /** Backend URLs for webhooks and other operations */
    urls?: {
        /** Webhook URL for this backend */
        webhook: string
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
 * Extended backend user interface for edit pages with additional Plex properties.
 */
export interface BackendEditUser extends BackendUser {
    /** Whether the user is a guest (for Plex) */
    guest?: boolean
    /** User UUID (for Plex) */
    uuid?: string
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
    /** If this option is present, a select option should show */
    choice?: Array<string> | null,
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
 * Base media item interface with common properties shared across different item types.
 */
export interface BaseMediaItem {
    /** Unique record ID */
    id: string | number
    /** Type of item (e.g., 'movie', 'episode', 'show') */
    type: string
    /** Title of the content */
    title: string
    /** Optional subtitle/content title */
    content_title?: string
    /** Path to the content file (maybe missing) */
    content_path?: string
    /** Whether the item has been watched */
    watched: boolean
    /** Unix timestamp when last updated */
    updated_at: number
}

/**
 * Extended media item with backend reporting information.
 */
export interface MediaItemWithBackends extends BaseMediaItem {
    /** Backends that reported this item */
    reported_by: Array<string>
    /** Backends that did NOT report this item */
    not_reported_by: Array<string>
}

/**
 * /system/integrity API response item.
 */
export interface IntegrityItem extends MediaItemWithBackends {
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
export type ParityItem = MediaItemWithBackends & {}

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

/**
 * FFProbe stream information.
 */
export interface FFProbeStream {
    /** Stream index in the file */
    index: number
    /** Type of stream (video, audio, subtitle) */
    codec_type: 'video' | 'audio' | 'subtitle'
    /** Codec name (e.g., 'h264', 'aac', 'subrip') */
    codec_name: string
    /** Stream tags containing metadata */
    tags?: {
        /** Stream title */
        title?: string
        /** Language code */
        language?: string
        [key: string]: any
    }
    /** Stream disposition flags */
    disposition?: {
        /** Whether this is the default stream */
        default?: number
        [key: string]: any
    }

    [key: string]: any
}

/**
 * Media file information with ffprobe data and subtitles.
 */
export interface MediaFile {
    /** Full path to the media file */
    path: string
    /** Array of backend names that have this file */
    source: Array<string>
    /** FFProbe data for the file */
    ffprobe: {
        /** Array of streams in the media file */
        streams: Array<FFProbeStream>
        /** Format information */
        format: {
            /** Duration in seconds */
            duration?: string
            /** Size in bytes */
            size?: string
            /** Bitrate */
            bit_rate?: string
            [key: string]: any
        }
    }
    /** Array of external subtitle file paths */
    subtitles: Array<string>
}

/**
 * History item that can be played.
 */
export interface PlayableItem {
    /** Item ID */
    id: string | number
    /** Item type (movie, episode) */
    type: string
    /** Item title */
    title: string
    /** Content title (episode title, etc.) */
    content_title?: string
    /** Whether the item has been watched */
    watched: boolean
    /** Array of available media files */
    files?: Array<MediaFile>
    /** Hardware capabilities */
    hardware?: {
        /** Available codecs */
        codecs: Array<{
            /** Codec identifier (e.g., 'libx264', 'h264_vaapi') */
            codec: string
            /** Human-readable codec name */
            name: string
            /** Whether this codec uses hardware acceleration */
            hwaccel: boolean
        }>
        /** Available VAAPI devices */
        devices?: Array<string>
    }
}

/**
 * Search item structure from /api/backend/{backend}/search endpoint.
 */
export interface SearchItem {
    /** Unique item ID */
    id: string | number
    /** Item type ('movie', 'episode', 'show') */
    type: 'movie' | 'episode' | 'show'
    /** Item title */
    title: string
    /** Optional content title (episode title, etc.) */
    content_title?: string
    /** Path to the content file */
    content_path?: string
    /** Whether the item has been watched */
    watched: boolean
    /** Unix timestamp when last updated */
    updated_at?: number
    /** Alternative timestamp field */
    updated?: number
    /** Backend that provided this item */
    via: string
    /** Web URL to view this item in the backend */
    webUrl?: string
    /** Release year */
    year?: number
    /** Additional metadata from backend */
    metadata?: Record<string, any>
}

/**
 * History item structure from /api/history endpoint.
 */
export interface HistoryItem {
    /** Unique history item ID */
    id: number
    /** Item type (movie, episode) */
    type: string
    /** Last updated timestamp */
    updated: number
    /** Whether the item has been watched */
    watched: boolean
    /** Backend that reported this item */
    via: string
    /** Item title */
    title: string
    /** Release year */
    year?: number
    /** Season number (for episodes) */
    season?: number
    /** Episode number (for episodes) */
    episode?: number
    /** Parent/show title (for episodes) */
    parent?: string
    /** Global unique identifiers */
    guids: Record<string, string>
    /** Metadata from different backends */
    metadata: {
        [backend: string]: {
            id: string
            type: "episode" | "movie" | "show"
            watched: "1" | "0"
            via: string
            title: string
            guids: Record<string, string>
            added_at: number
            extra: {
                genres: Array<string>
                title: string
                date: string
                favorite: number
                overview: string
            },
            "library": "22717"
            "show": "259409"
            "season": "4"
            "episode": "10"
            "parent": Record<string, string>
            "year": number
            "multi": boolean
            "path": string
            "played_at": number
            "progress": number
            "webUrl": string
        }
    }
    /** Additional data */
    extra: Record<string, any>
    /** Created timestamp */
    created_at: number
    /** Updated timestamp */
    updated_at: number
    /** Full title computed by frontend */
    full_title?: string
    /** Content title (episode title, etc.) */
    content_title?: string
    /** Content overview/description */
    content_overview?: string
    /** Content genres */
    content_genres?: Array<string>
    /** Content path */
    content_path?: string
    /** Whether content file exists on disk */
    content_exists?: boolean
    /** Relative GUIDs for episodes */
    rguids?: Record<string, string>
    /** Backends that reported this item */
    reported_by: Array<string>
    /** Backends that did NOT report this item */
    not_reported_by: Array<string>
    /** Web URL for this item */
    webUrl?: string
    /** Whether this item has tainted data */
    isTainted?: boolean
    /** Event type/name associated with this history item */
    event?: string
    /** Progress information (in seconds or other format) */
    progress?: number | string
    /** Available media files */
    files?: Array<MediaFile>
    /** IDs of records referencing the same content path */
    duplicate_reference_ids?: Array<number>
}

/**
 * Represents a help guide choice item on the help index page.
 */
export interface HelpChoice {
    /** Sequential number for the guide */
    number: number
    /** Title of the help guide */
    title: string
    /** Description text explaining what the guide covers */
    text: string
    /** Optional URL path to the guide (undefined if not available yet) */
    url?: string
}

/**
 * Represents a custom GUID definition.
 */
export interface CustomGUID {
    /** Unique GUID identifier/name */
    id?: string
    /** GUID name (prefixed with guid_) */
    name: string
    /** GUID type (currently only 'string' supported) */
    type: string
    /** Description of what this GUID represents */
    description: string
    /** Validation configuration */
    validator: {
        /** Regex pattern for validation */
        pattern: string
        /** Example value for display */
        example: string
        /** Test cases for validation */
        tests: {
            /** Values that should match the pattern */
            valid: Array<string>
            /** Values that should NOT match the pattern */
            invalid: Array<string>
        }
    }
}

/**
 * Represents a client GUID link mapping.
 */
export interface CustomLink {
    /** Unique link identifier */
    id?: string
    /** Client type (e.g., 'plex', 'jellyfin', 'emby') */
    type: string
    /** GUID mapping configuration */
    map: {
        /** Source client GUID */
        from: string
        /** Target WatchState/custom GUID */
        to: string
    }
    /** Optional text replacement (for Plex legacy agents) */
    replace?: {
        /** Text to search for */
        from: string
        /** Text to replace with */
        to: string
    }
    /** Client-specific options */
    options?: {
        /** Whether this is a Plex legacy agent */
        legacy?: boolean
    }
}

/**
 * Extended backend interface for the backends index page with sync info and URLs.
 */
export interface BackendWithSync extends Backend {
    /** Backend URLs for webhooks and other operations */
    urls?: {
        /** Webhook URL for this backend */
        webhook: string
    }
}

/**
 * Represents a user item from the /backend/{backend}/users API endpoint.
 */
export interface BackendUserItem {
    /** Unique user identifier */
    id: string | number
    /** User display name */
    name: string
    /** Whether the user has admin privileges */
    admin: boolean
    /** Whether the user is a guest (optional for some backends) */
    guest?: boolean
    /** Whether the user is hidden (optional for some backends) */
    hidden?: boolean
    /** Whether the user is restricted (optional for some backends) */
    restricted?: boolean
    /** Whether the user is disabled (optional for some backends) */
    disabled?: boolean
    /** Last updated timestamp (ISO string, or special values like 'external_user' or 'never') */
    updatedAt?: string
}

/**
 * Library item structure from /api/backend/{backend}/library endpoint.
 */
export interface LibraryItem {
    /** Unique library ID */
    id: string | number
    /** Library title/name */
    title: string
    /** Library type (e.g., 'Movie', 'TV Shows', etc.) */
    type: string
    /** Whether this library type is supported by WatchState */
    supported: boolean
    /** Whether this library is ignored during sync */
    ignored: boolean
    /** Backend agent used for this library */
    agent?: string
    /** Scanner type used for this library */
    scanner?: string
    /** Web URL for viewing the library */
    webUrl?: string
}

/**
 * Mismatched item structure from /api/backend/{backend}/mismatched endpoint.
 */
export interface MismatchedItem {
    /** Item title */
    title: string
    /** Item type (e.g., 'Movie', 'Series', etc.) */
    type: string
    /** Library name where the item is found */
    library: string
    /** Release year */
    year?: number
    /** File system path to the item */
    path?: string
    /** Web URL for viewing the item */
    webUrl?: string
    /** Match percentage (0-100) indicating confidence level */
    percent: number
    /** UI state: Whether to show full item details */
    showItem?: boolean
}

export interface UnmatchedItem {
    /** Item title */
    title: string
    /** Item type (e.g., 'Movie', 'Series', etc.) */
    type?: string
    /** Library name where the item is found */
    library?: string
    /** Release year */
    year?: number
    /** File system path to the item */
    path?: string
    /** Web URL for viewing the item */
    webUrl?: string
    /** Alternative URL for the item */
    url?: string
}

/**
 * Represents an active session item from backend sessions endpoint.
 */
export interface SessionItem {
    /** The unique session ID */
    id: string
    /** Backend name that reported this session */
    backend: string
    /** User name */
    user_name: string
    /** Session state (e.g., 'playing', 'paused') */
    session_state: string
    /** Title of the content being played */
    item_title: string
    /** Content ID in the backend */
    item_id: string | number
    /** Current playback position/offset in milliseconds */
    item_offset_at: number
    /** When the session was last updated */
    updated_at: string
}

/**
 * Represents a stale item from the backend stale endpoint.
 * These are items that exist in the local database but no longer exist in the remote backend library.
 */
export type StaleItem = MediaItemWithBackends & {}

/**
 * UI state extension for expandable/collapsible content items.
 */
export interface ExpandableUIState {
    /** UI: Whether to expand the title field */
    expand_title?: boolean
    /** UI: Whether to expand the path field */
    expand_path?: boolean
    /** UI: Whether to show raw data */
    showRawData?: boolean
}

/**
 * Common UI state for items with loading and error states.
 */
export interface UILoadingState {
    /** Whether the item is currently loading */
    isLoading?: boolean
    /** Whether the item is expanded */
    isExpanded?: boolean
    /** Error message if any */
    errorMessage?: string
    /** Whether to show additional details */
    showDetails?: boolean
}

/**
 * Generic form validation error structure.
 */
export interface FormValidationErrors<T> {
    /** Field-specific error messages */
    fields: Partial<Record<keyof T, string>>
    /** General form error message */
    general?: string
}

/**
 * Generic form state wrapper for any data type.
 */
export interface FormState<T> {
    /** Form data */
    data: T
    /** Validation errors */
    errors: FormValidationErrors<T>
    /** Whether the form has been submitted */
    isSubmitted: boolean
    /** Whether the form has unsaved changes */
    isDirty: boolean
    /** Whether the form is currently submitting */
    isSubmitting: boolean
}

/**
 * Counts information for stale item's comparison.
 */
export interface StaleCounts {
    /** Number of items in remote backend library */
    remote: number
    /** Number of items in local database for this library */
    local: number
    /** Number of stale items (local items not in remote) */
    stale: number
}

/**
 * Backend information for stale item's response.
 */
export interface StaleBackendInfo {
    /** Backend name */
    name: string
    /** Library information */
    library?: LibraryItem
}

/**
 * API response structure for backend stale endpoint.
 */
export interface StaleResponse {
    /** Array of stale items */
    items: Array<StaleItem>
    /** Backend and library information */
    backend: StaleBackendInfo
    /** Count statistics */
    counts: StaleCounts
}

/**
 * Backend spec option definition from /api/backends/spec endpoint.
 */
export interface BackendSpecOption {
    /** The option key (without 'options.' prefix) */
    key: string
    /** Description of what this option does */
    description: string
    /** Data type expected for this option */
    type?: string
    /** Whether this option is deprecated */
    deprecated?: boolean
}

/**
 * Plex OAuth authentication data from /api/backends/plex/generate endpoint.
 */
export interface PlexOAuthData {
    /** OAuth authentication ID */
    id: string
    /** OAuth code for authentication */
    code: string
    /** Plex client identifier */
    'X-Plex-Client-Identifier': string
    /** Plex product name */
    'X-Plex-Product': string
}

/**
 * Plex OAuth token response from /api/backends/plex/check endpoint.
 */
export interface PlexOAuthTokenResponse {
    /** The authentication token received from Plex */
    authToken?: string
}

/**
 * Input item for file diff visualization component.
 * Represents a backend and its associated file path.
 */
export interface FileDiffInput {
    /** Backend name (e.g., 'plex', 'jellyfin') */
    backend: string
    /** File path for this backend */
    file: string
}

/**
 * Represents a path segment with highlighting information.
 */
export interface FileDiffPathSegment {
    /** The path segment text */
    segment: string
    /** Whether this segment is different from the reference */
    isDifferent: boolean
}

/**
 * Represents a line in the GitHub-style diff view.
 */
export interface FileDiffLine {
    /** Line number in the reference file (if applicable) */
    lineNumber?: number
    /** Type of diff line */
    type: 'context' | 'reference' | 'addition' | 'deletion' | 'modification'
    /** The text content of this line */
    content: string
    /** Backend name for this line (for additions/deletions/modifications) */
    backend?: string
    /** Additional CSS classes for styling */
    cssClass?: string
    /** Path segments with difference highlighting (for path diff lines) */
    pathSegments?: Array<FileDiffPathSegment>
}

/**
 * Represents a chunk/hunk of differences in GitHub-style diff.
 */
export interface FileDiffChunk {
    /** Reference file line number where this chunk starts */
    referenceStart: number
    /** Number of lines in reference for this chunk */
    referenceLines: number
    /** Array of diff lines in this chunk */
    lines: Array<FileDiffLine>
    /** Header text for this chunk (e.g., "@@ -1,3 +1,4 @@") */
    header: string
}

/**
 * Result of GitHub-style file diff computation.
 */
export interface FileDiffResult {
    /** The reference file path chosen as baseline */
    referencePath: string
    /** Backend name that provides the reference */
    referenceBackend: string
    /** Array of diff chunks showing changes */
    chunks: Array<FileDiffChunk>
    /** Whether any differences were found */
    hasDifferences: boolean
    /** Summary statistics */
    stats: {
        /** Total lines added */
        additions: number
        /** Total lines deleted */
        deletions: number
        /** Total lines modified */
        modifications: number
    }
    /** Reference path segments showing common vs different parts */
    referenceSegments: Array<FileDiffPathSegment>
}
