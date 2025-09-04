/**
 * Represents a scheduled or queued task in WatchState.
 */
export interface Task {
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
