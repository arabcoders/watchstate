<?php
/**
 * Last update: 2024-05-10
 *
 * This file contains the environment variables that are supported by the application.
 * All keys MUST start with WS_ and be in UPPERCASE and use _ as a separator.
 * Avoid using complex datatypes, the value should be a simple scalar value.
 */

return (function () {
    $env = [
        [
            'key' => 'WS_DATA_PATH',
            'description' => 'Where to store main data. (config, db).',
            'type' => 'string',
        ],
        [
            'key' => 'WS_TMP_DIR',
            'description' => 'Where to store temp data. (logs, cache).',
            'type' => 'string',
        ],
        [
            'key' => 'WS_TZ',
            'description' => 'Set the Tool timezone.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGS_CONTEXT',
            'description' => 'Enable extra context information in logs and output.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_FILE_ENABLE',
            'description' => 'Enable logging to app.(YYYYMMDD).log file.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_FILE_LEVEL',
            'description' => 'Set the log level for the file logger. Default: ERROR.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_WEBHOOK_DUMP_REQUEST',
            'description' => 'Dump all requests to webhook endpoint to a json file.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_TRUST_PROXY',
            'description' => 'Trust the IP from the WS_TRUST_HEADER header.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_TRUST_HEADER',
            'description' => 'The header which contains the true user IP.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LIBRARY_SEGMENT',
            'description' => 'How many items to request per a request to backends.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_CACHE_URL',
            'description' => 'The URL to the cache server.',
            'type' => 'string',
            'mask' => true,
        ],
        [
            'key' => 'WS_CACHE_NULL',
            'description' => 'Enable the null cache driver. This is useful for testing or container container startup.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_WEBUI_ENABLED',
            'description' => 'Enable the WebUI.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_WEBUI_PATH',
            'description' => 'The path to where the WebUI is compiled.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_API_KEY',
            'description' => 'The API key to allow access to the API.',
            'type' => 'string',
            'mask' => true,
        ],
        [
            'key' => 'WS_LOGS_PRUNE_AFTER',
            'description' => 'Prune logs after this many days.',
            'type' => 'int',
        ],
        [
            'key' => 'WS_EXPORT_THRESHOLD',
            'description' => 'Trigger full export mode if changes exceed this number.',
            'type' => 'int',
        ],
        [
            'key' => 'WS_EPISODES_DISABLE_GUID',
            'description' => 'DO NOT parse episodes GUID.',
            'type' => 'bool',
            'deprecated' => true,
        ],
        [
            'key' => 'WS_BACKENDS_FILE',
            'description' => 'The full path to the backends file.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_WEBHOOK_LOG_FILE_FORMAT',
            'description' => 'The name format for the webhook log file. Anything inside {} will be replaced with data from the webhook payload.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_CACHE_PREFIX',
            'description' => 'The prefix for the cache keys. Default \'\'.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_CACHE_PATH',
            'description' => 'Where to store cache data. This is usually used if the cache server is not available and/or experiencing issues.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGGER_SYSLOG_FACILITY',
            'description' => 'The syslog facility to use.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGGER_SYSLOG_ENABLED',
            'description' => 'Enable logging to syslog.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_SYSLOG_LEVEL',
            'description' => 'Set the log level for the syslog logger. Default: ERROR',
            'type' => 'string',
        ],
        [
            'key' => 'WS_SECURE_API_ENDPOINTS',
            'description' => 'Close all open routes and enforce API key authentication on all endpoints.',
            'type' => 'bool',
        ],
    ];

    // -- Do not forget to update the tasks list if you add a new task.
    $tasks = ['import', 'export', 'push', 'progress', 'backup', 'prune', 'indexes', 'requests'];
    $task_env = [
        [
            'key' => 'WS_CRON_{task}',
            'description' => 'Enable the {task} task.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_CRON_{task}_AT',
            'description' => 'The time to run the {task} task.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_CRON_{task}_ARGS',
            'description' => 'The arguments to pass to the {task} task.',
            'type' => 'string',
        ],
    ];

    foreach ($tasks as $task) {
        foreach ($task_env as $info) {
            $info['key'] = r($info['key'], ['task' => strtoupper($task)]);
            $info['description'] = r($info['description'], ['task' => $task]);
            $env[] = $info;
        }
    }

    // -- sort based on the array name key
    $sorter = array_column($env, 'key');
    array_multisort($sorter, SORT_ASC, $env);

    return $env;
})();
