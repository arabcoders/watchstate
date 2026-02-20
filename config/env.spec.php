<?php

/**
 * Last update: 2025-07-13
 *
 * This file contains the environment variables that are supported by the application.
 * All keys MUST start with WS_ and be in UPPERCASE and use _ as a separator.
 * Avoid using complex datatypes, the value should be a simple scalar value.
 */

use App\Libs\Config;
use App\Libs\Exceptions\ValidationException;
use Cron\CronExpression;

return (function () {
    $env = [
        [
            'key' => 'WS_DATA_PATH',
            'config' => 'path',
            'description' => 'Where to store main data. (config, db).',
            'type' => 'string',
        ],
        [
            'key' => 'WS_TMP_DIR',
            'config' => 'tmpDir',
            'description' => 'Where to store temp data. (logs, cache).',
            'type' => 'string',
        ],
        [
            'key' => 'WS_TZ',
            'config' => 'tz',
            'description' => 'Set the Tool timezone.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (is_numeric($value) || empty($value)) {
                    throw new ValidationException('Invalid timezone. Empty value.');
                }

                try {
                    return new DateTimeZone($value)->getName();
                } catch (Throwable) {
                    throw new ValidationException("Invalid timezone '{$value}'.");
                }
            },
        ],
        [
            'key' => 'WS_DB_MODE',
            'config' => null,
            'description' => 'DB journal mode. Memory mode can give a big performance boost at the cost of potential data loss on crashes.',
            'type' => 'string',
            'choices' => ['MEMORY', 'WAL'],
            'validate' => function (mixed $value, array $spec = []): string {
                if (is_numeric($value) || empty($value)) {
                    throw new ValidationException('Invalid db mode. Empty value.');
                }

                $val = strtoupper($value);

                if (!in_array($val, $spec['choices'], true)) {
                    throw new ValidationException('Invalid db mode. Must be: ' . implode(', ', $spec['choices']));
                }

                return $val;
            },
        ],
        [
            'key' => 'WS_LOGS_CONTEXT',
            'config' => 'logs.context',
            'description' => 'Enable extra context information in logs and output.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_FILE_ENABLE',
            'config' => 'logger.file.enabled',
            'description' => 'Enable logging to app.(YYYYMMDD).log file.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_FILE_LEVEL',
            'config' => 'logger.file.level',
            'description' => 'Set the log level for the file logger. Default: ERROR.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_WEBHOOK_LOG_ENABLED',
            'config' => 'webhook.log.enabled',
            'description' => 'Enable Webhook logging to file. Default: true.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_WEBHOOK_LOG_LEVEL',
            'config' => 'webhook.log.level',
            'description' => 'Set the log level for the webhook logger. Default: INFO.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_WEBHOOK_DUMP_REQUEST',
            'config' => 'webhook.dumpRequest',
            'description' => 'Dump all requests to webhook endpoint to a json file.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_TRUST_PROXY',
            'config' => 'trust.proxy',
            'description' => 'Trust the IP from the WS_TRUST_HEADER header.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_TRUST_LOCAL',
            'config' => 'trust.local',
            'description' => 'Bypass the WebUI authentication layer for local IP addresses.',
            'type' => 'bool',
            'danger' => true,
        ],
        [
            'key' => 'WS_TRUST_HEADER',
            'config' => 'trust.header',
            'description' => 'The header which contains the true user IP.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LIBRARY_SEGMENT',
            'config' => 'library.segment',
            'description' => 'How many items to request per a request to backends.',
            'type' => 'int',
        ],
        [
            'key' => 'WS_CACHE_URL',
            'config' => 'cache.url',
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
            'config' => 'webui.path',
            'description' => 'The path to where the WebUI is compiled.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_API_KEY',
            'config' => 'api.key',
            'description' => 'The API key to allow access to the API.',
            'type' => 'string',
            'mask' => true,
            'protected' => true,
        ],
        [
            'key' => 'WS_LOGS_PRUNE_AFTER',
            'config' => 'logs.prune.after',
            'description' => 'Prune logs after this many days.',
            'type' => 'int',
        ],
        [
            'key' => 'WS_EXPORT_THRESHOLD',
            'config' => 'export.threshold',
            'description' => 'Trigger full export mode if changes exceed this number.',
            'type' => 'int',
        ],
        [
            'key' => 'WS_BACKENDS_FILE',
            'config' => 'backends_file',
            'description' => 'The full path to the backends file.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_WEBHOOK_LOG_FILE_FORMAT',
            'config' => 'webhook.log.file_format',
            'description' => 'The name format for the webhook log file. Anything inside {} will be replaced with data from the webhook payload.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_CACHE_PREFIX',
            'config' => 'cache.prefix',
            'description' => 'The prefix for the cache keys. Default \'\'.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_CACHE_PATH',
            'config' => 'cache.path',
            'description' => 'Where to store cache data. This is usually used if the cache server is not available and/or experiencing issues.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGGER_SYSLOG_FACILITY',
            'config' => 'logger.syslog.facility',
            'description' => 'The syslog facility to use.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGGER_SYSLOG_ENABLED',
            'config' => 'logger.syslog.enabled',
            'description' => 'Enable logging to syslog.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_SYSLOG_LEVEL',
            'config' => 'logger.syslog.level',
            'description' => 'Set the log level for the syslog logger. Default: ERROR',
            'type' => 'string',
        ],
        [
            'key' => 'WS_SECURE_API_ENDPOINTS',
            'config' => 'api.secure',
            'description' => 'Disable the open policy for webhook endpoint and require apikey.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_API_LOG_INTERNAL',
            'config' => 'api.logInternal',
            'description' => 'Log internal requests to the API.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_DEBUG',
            'config' => 'debug.enabled',
            'description' => 'Expose debug information in the API when an error occurs.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_CONSOLE_ENABLE_ALL',
            'config' => 'console.enable.all',
            'description' => 'All executing all commands in the console. They must be prefixed with $',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_PROFILER_COLLECTOR',
            'config' => 'profiler.collector',
            'description' => 'The XHProf data collector URL to send the profiler data to.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_PROFILER_SAVE',
            'config' => 'profiler.save',
            'description' => 'Save the profiler data to disk.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_PROFILER_PATH',
            'config' => 'profiler.path',
            'description' => 'The path to save the profiler data.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_PROGRESS_THRESHOLD',
            'config' => 'progress.threshold',
            'description' => 'Allow watch progress sync for played items. Expects seconds. Minimum 180. 0 to disable.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (!is_numeric($value) && empty($value)) {
                    throw new ValidationException('Invalid progress threshold. Empty value.');
                }

                if (false === is_numeric($value)) {
                    throw new ValidationException('Invalid progress threshold. Must be a number.');
                }

                $cmp = (int) $value;

                if (0 !== $cmp && $cmp < 180) {
                    throw new ValidationException('Invalid progress threshold. Must be at least 180 seconds.');
                }

                return (string) $value;
            },
        ],
        [
            'key' => 'WS_LOGGER_REMOTE_ENABLE',
            'config' => 'logger.remote.enabled',
            'description' => 'Enable logging to remote logger.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_LOGGER_REMOTE_LEVEL',
            'config' => 'logger.remote.level',
            'description' => 'Set the log level for the remote logger. Default: ERROR.',
            'type' => 'string',
        ],
        [
            'key' => 'WS_LOGGER_REMOTE_URL',
            'config' => 'logger.remote.url',
            'description' => 'The URL to the remote logger.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (!is_numeric($value) && empty($value)) {
                    throw new ValidationException('Invalid remote logger URL. Empty value.');
                }

                if (false === is_valid_url($value)) {
                    throw new ValidationException('Invalid remote logger URL. Must be a valid URL.');
                }
                return (string) $value;
            },
            'mask' => true,
        ],
        [
            'key' => 'WS_HTTP_SYNC_REQUESTS',
            'config' => 'http.default.sync_requests',
            'description' => 'Whether to send backend requests in parallel or sequentially.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_SYSTEM_USER',
            'config' => 'system.user',
            'description' => 'The login user name.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (!is_numeric($value) && empty($value)) {
                    throw new ValidationException('Invalid username. Empty value.');
                }

                if (false === is_valid_name($value)) {
                    throw new ValidationException(
                        'Invalid username. Username can only contains [lower case a-z, 0-9 and _].',
                    );
                }
                return (string) $value;
            },
            'mask' => true,
            'protected' => true,
        ],
        [
            'key' => 'WS_SYSTEM_PASSWORD',
            'config' => 'system.password',
            'description' => 'The login password. The given plaintext password will be converted to hash.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (empty($value)) {
                    throw new ValidationException('Invalid password. Empty value.');
                }

                $prefix = Config::get('password.prefix', 'ws_hash@:');

                if (true === str_starts_with($value, $prefix)) {
                    return (string) $value;
                }

                try {
                    return $prefix
                    . password_hash(
                        $value,
                        Config::get('password.algo'),
                        Config::get('password.options', []),
                    );
                } catch (ValueError $e) {
                    throw new ValidationException('Invalid password. Password hashing failed.', $e->getCode(), $e);
                }
            },
            'mask' => true,
            'protected' => true,
        ],
        [
            'key' => 'WS_SYSTEM_SECRET',
            'config' => 'system.secret',
            'description' => 'The secret key which is used to sign successful auth requests.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (empty($value)) {
                    throw new ValidationException('Invalid secret. Empty value.');
                }

                if (false === is_string($value)) {
                    throw new ValidationException('Invalid secret. Must be a string.');
                }

                if (strlen($value) < 32) {
                    throw new ValidationException('Invalid secret. Must be at least 32 characters long.');
                }

                return $value;
            },
            'mask' => true,
            'protected' => true,
        ],
        [
            'key' => 'WS_GUID_DISABLE_EPISODE',
            'config' => 'guid.disable.episode',
            'description' => 'Enable this option to disable matching episodes by GUIDs and rely on relative GUIDs to match.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_PROGRESS_MINIMUM',
            'config' => 'progress.minimum',
            'description' => 'Time in seconds to consider progress update as valid.',
            'type' => 'string',
            'validate' => function (mixed $value, array $spec = []): string {
                if (!is_numeric($value) && empty($value)) {
                    throw new ValidationException('Invalid minimum progress. Empty value.');
                }

                if (false === is_numeric($value)) {
                    throw new ValidationException('Invalid minimum progress. Must be a number.');
                }

                $cmp = (int) $value;

                if ($cmp < 60) {
                    throw new ValidationException('Invalid minimum progress. Must be at least 60 seconds.');
                }

                return (string) $value;
            },
        ],
        [
            'key' => 'WS_CLIENTS_JELLYFIN_FIX_PLAYED',
            'config' => 'clients.jellyfin.fix_played',
            'description' => 'Enable partial fix for Jellyfin marking items as played.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_CLIENTS_PLEX_DISABLE_DEDUP',
            'config' => 'clients.plex.disable_dedup',
            'description' => 'Disable de-duplication of plex users.',
            'type' => 'bool',
        ],
    ];

    $validateCronExpression = function (string $value, array $spec = []): string {
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
    $tasks = ['import', 'export', 'backup', 'prune', 'indexes', 'validate', 'dispatch'];
    $task_env = [
        [
            'key' => 'WS_CRON_{TASK}',
            'config' => 'tasks.list.{task}.enabled',
            'description' => 'Enable the {TASK} task.',
            'type' => 'bool',
        ],
        [
            'key' => 'WS_CRON_{TASK}_AT',
            'config' => 'tasks.list.{task}.timer',
            'description' => 'The time to run the {TASK} task.',
            'type' => 'string',
            'validate' => $validateCronExpression(...),
        ],
        [
            'key' => 'WS_CRON_{TASK}_ARGS',
            'config' => 'tasks.list.{task}.args',
            'description' => 'The arguments to pass to the {TASK} task.',
            'type' => 'string',
        ],
    ];

    foreach ($tasks as $task) {
        foreach ($task_env as $info) {
            $info['key'] = r($info['key'], ['TASK' => strtoupper($task)]);
            $info['description'] = r($info['description'], ['TASK' => $task]);
            $info['config'] = r($info['config'], ['task' => $task]);
            $env[] = $info;
        }
    }

    // -- sort based on the array name key
    $sorter = array_column($env, 'key');
    array_multisort($sorter, SORT_ASC, $env);

    return $env;
})();
