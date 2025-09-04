/**
 * Represents an event object from the API.
 */
export interface EventViewEvent {
    /** Event ID */
    id: string;
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
 * Represents the props for the EventView component.
 */
export interface EventViewProps {
    /** Event ID to display */
    id: string
}

/**
 * Represents the emits for the EventView component.
 */
export interface EventViewEmits {
    /** Emitted when overlay should be closed */
    closeOverlay: []
    /** Emitted when event is deleted */
    deleted: [event: EventViewEvent]
    /** Emitted when delete action is requested */
    delete: [event: EventViewEvent]
}

/**
 * Represents pagination information from the API.
 */
export interface EventsPaging {
    /** Current page number */
    page: number
    /** Items per page */
    perpage: number
    /** Total number of items */
    total: number
}

/**
 * Represents a status object from the API.
 */
export interface EventStatus {
    /** Status code */
    code: number
    /** Status name */
    name: string
}

/**
 * Represents the API response for events list.
 */
export interface EventsApiResponse {
    /** Array of event items */
    items: Array<EventViewEvent>
    /** Array of available statuses */
    statuses: Array<EventStatus>
    /** Pagination information */
    paging: EventsPaging
    /** Error information (if request fails) */
    error?: {
        code: string | number
        message: string
    }
}
