<?php
/**
 * Last update: 2024-05-05
 *
 * This file contains the environment variables that are supported by the application.
 * All keys MUST start with WS_ and be in UPPERCASE and use _ as a separator.
 * Avoid using complex datatypes, the value should be a simple scalar value.
 */

return (function () {
    // -- Do not forget to update the tasks list if you add a new task.
    $tasks = ['import', 'export', 'push', 'progress', 'backup', 'prune', 'indexes', 'requests'];
    $task_env = [
        'WS_CRON_{task}' => [
            'desc' => 'Enable the {task} task.',
            'type' => 'bool',
        ],
        'WS_CRON_{task}_AT' => [
            'desc' => 'The time to run the {task} task.',
            'type' => 'string',
        ],
        'WS_CRON_{task}_ARGS' => [
            'desc' => 'The arguments to pass to the {task} task.',
            'type' => 'string',
        ],
    ];

    $env = [];

    foreach ($tasks as $task) {
        foreach ($task_env as $key => $info) {
            $info['desc'] = r($info['desc'], ['task' => $task]);
            $env[r($key, ['task' => strtoupper($task)])] = $info;
        }
    }

    $env = array_replace_recursive($env, [
        'WS_DATA_PATH' => [
            'description' => 'Where to store main data. (config, db).',
            'type' => 'string',
        ],
        'WS_TMP_DIR' => [
            'description' => 'Where to store temp data. (logs, cache)',
            'type' => 'string',
        ],
        'WS_TZ' => [
            'description' => 'Set the Tool timezone.',
            'type' => 'string',
        ],
        'WS_LOGS_CONTEXT' => [
            'description' => 'Enable context in logs.',
            'type' => 'bool',
        ],
        'WS_LOGGER_FILE_ENABLE' => [
            'description' => 'Enable logging to app.log file',
            'type' => 'bool',
        ],
        'WS_LOGGER_FILE_LEVEL' => [
            'description' => 'Set the log level for the file logger. Default: ERROR',
            'type' => 'string',
        ],
        'WS_WEBHOOK_DUMP_REQUEST' => [
            'description' => 'Dump all requests to webhook endpoint to a json file.',
            'type' => 'bool',
        ],
        'WS_TRUST_PROXY' => [
            'description' => 'Trust the IP from the WS_TRUST_HEADER header.',
            'type' => 'bool',
        ],
        'WS_TRUST_HEADER' => [
            'description' => 'The header with the true user IP.',
            'type' => 'string',
        ],
        'WS_LIBRARY_SEGMENT' => [
            'description' => 'How many items to request per a request.',
            'type' => 'string',
        ],
        'WS_CACHE_URL' => [
            'description' => 'The URL to the cache server.',
            'type' => 'string',
            'mask' => true,
        ],
        'WS_CACHE_NULL' => [
            'description' => 'Enable the null cache. This is useful for testing. Or first time container startup.',
            'type' => 'bool',
        ],
        'WS_WEBUI_ENABLED' => [
            'description' => 'Enable the web UI.',
            'type' => 'bool',
        ],
        'WS_API_KEY' => [
            'description' => 'The API key to allow access to the API',
            'type' => 'string',
            'mask' => true,
        ],
        'WS_LOGS_PRUNE_AFTER' => [
            'description' => 'Prune logs after this many days.',
            'type' => 'int',
        ],
        'WS_EXPORT_THRESHOLD' => [
            'description' => 'Trigger full export mode if changes exceed this number.',
            'type' => 'int',
        ],
        'WS_EPISODES_DISABLE_GUID' => [
            'description' => 'Disable the GUID field in the episodes.',
            'type' => 'bool',
            'deprecated' => true,
        ],
        'WS_BACKENDS_FILE' => [
            'description' => 'The full path to the backends file.',
            'type' => 'string',
        ],
        'WS_WEBHOOK_LOG_FILE_FORMAT' => [
            'description' => 'The name format for the webhook log file. Anything inside {} will be replaced with data from the webhook payload.',
            'type' => 'string',
        ],
        'WS_CACHE_PREFIX' => [
            'description' => 'The prefix for the cache keys.',
            'type' => 'string',
        ],
        'WS_CACHE_PATH' => [
            'description' => 'The path to the cache directory. This is usually if the cache server is not available.',
            'type' => 'string',
        ],
        'WS_LOGGER_SYSLOG_FACILITY' => [
            'description' => 'The syslog facility to use.',
            'type' => 'string',
        ],
        'WS_LOGGER_SYSLOG_ENABLED' => [
            'description' => 'Enable logging to syslog.',
            'type' => 'bool',
        ],
        'WS_LOGGER_SYSLOG_LEVEL' => [
            'description' => 'Set the log level for the syslog logger. Default: ERROR',
            'type' => 'string',
        ],
        'WS_SECURE_API_ENDPOINTS' => [
            'description' => 'Disregard the open route policy, and require an API key for all routes.',
            'type' => 'bool',
        ],
    ]);

    ksort($env);

    return $env;
})();
