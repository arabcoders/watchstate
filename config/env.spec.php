<?php
/**
 * Last update: 2024-05-10
 *
 * This file contains the environment variables that are supported by the application.
 * All keys MUST start with WS_ and be in UPPERCASE and use _ as a separator.
 * Avoid using complex datatypes, the value should be a simple scalar value.
 */

use App\Libs\Exceptions\ValidationException;
use Cron\CronExpression;

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
            'type' => 'int',
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
        [
            'key' => 'WS_API_LOG_INTERNAL',
            'description' => 'Log internal requests to the API.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_DEBUG',
            'description' => 'Expose debug information in the API when an error occurs.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_API_AUTO',
            'description' => 'PUBLICLY EXPOSE the api token for automated WebUI configuration. This should NEVER be enabled if WatchState is exposed to the internet.',
            'danger' => true,
            'type' => 'bool',
        ],
        [
            'key' => 'WS_CONSOLE_ENABLE_ALL',
            'description' => 'All executing all commands in the console. They must be prefixed with $',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_SYNC_PROGRESS',
            'description' => 'Enable watch progress sync.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_PROFILER_COLLECTOR',
            'description' => 'The XHProf data collector URL to send the profiler data to.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_PROFILER_SAVE',
            'description' => 'Save the profiler data to disk.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_PROFILER_PATH',
            'description' => 'The path to save the profiler data.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_PROGRESS_THRESHOLD',
            'description' => 'Allow watch progress sync for played items. Expects seconds. Minimum 180. 0 to disable.',
            'type' => 'string',
            'validate' => function (mixed $value): string {
                if (!is_numeric($value) && empty($value)) {
                    throw new ValidationException('Invalid progress threshold. Empty value.');
                }

                if (false === is_numeric($value)) {
                    throw new ValidationException('Invalid progress threshold. Must be a number.');
                }

                $cmp = (int)$value;

                if (0 !== $cmp && $cmp < 180) {
                    throw new ValidationException('Invalid progress threshold. Must be at least 180 seconds.');
                }

                return $value;
            },
        ],
        [
            'key' => 'WS_LOGGER_REMOTE_ENABLE',
            'description' => 'Enable logging to remote logger.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_REMOTE_LEVEL',
            'description' => 'Set the log level for the remote logger. Default: ERROR.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGGER_REMOTE_URL',
            'description' => 'The URL to the remote logger.',
            'type' => 'string',
            'validate' => function (mixed $value): string {
                if (!is_numeric($value) && empty($value)) {
                    throw new ValidationException('Invalid remote logger URL. Empty value.');
                }

                if (false === isValidURL($value)) {
                    throw new ValidationException('Invalid remote logger URL. Must be a valid URL.');
                }
                return $value;
            },
            'mask' => true,
        ],
        [
            'key' => 'WS_HTTP_SYNC_REQUESTS',
            'description' => 'Whether to send backend requests in parallel or sequentially.',
            'type' => 'bool',
        ],
    ];

    $validateCronExpression = function (string $value): string {
        if (empty($value)) {
            throw new ValidationException('Invalid cron expression. Empty value.');
        }

        try {
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }

            $status = new CronExpression($value)->getNextRunDate()->getTimestamp() >= 0;

            if (!$status) {
                throw new ValidationException('Invalid cron expression. The next run date is in the past.');
            }
        } catch (Throwable $e) {
            throw new ValidationException(r('Invalid cron expression. {error}', ['error' => $e->getMessage()]));
        }

        return $value;
    };

    // -- Do not forget to update the tasks list if you add a new task.
    $tasks = ['import', 'export', 'backup', 'prune', 'indexes'];
    $task_env = [
        [
            'key' => 'WS_CRON_{TASK}',
            'description' => 'Enable the {TASK} task.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_CRON_{TASK}_AT',
            'description' => 'The time to run the {TASK} task.',
            'type' => 'string',
            'validate' => $validateCronExpression(...),
        ],
        [
            'key' => 'WS_CRON_{TASK}_ARGS',
            'description' => 'The arguments to pass to the {TASK} task.',
            'type' => 'string',
        ],
    ];

    foreach ($tasks as $task) {
        foreach ($task_env as $info) {
            $info['key'] = r($info['key'], ['TASK' => strtoupper($task)]);
            $info['description'] = r($info['description'], ['TASK' => $task]);
            $env[] = $info;
        }
    }

    // -- sort based on the array name key
    $sorter = array_column($env, 'key');
    array_multisort($sorter, SORT_ASC, $env);

    return $env;
})();
