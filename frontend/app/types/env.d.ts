type EnvVar = {
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

export { EnvVar }
