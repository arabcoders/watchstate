/**
 * Represents an event object from the API.
 */
export interface EventsItem {
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
