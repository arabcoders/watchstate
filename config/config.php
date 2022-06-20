<?php

declare(strict_types=1);

use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\State\PushCommand;
use App\Commands\System\PruneCommand;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Scheduler\Task;
use App\Libs\Servers\EmbyServer;
use App\Libs\Servers\JellyfinServer;
use App\Libs\Servers\PlexServer;
use Monolog\Logger;

return (function () {
    $config = [
        'name' => 'WatchState',
        'version' => '$(version_via_ci)',
        'tz' => env('WS_TZ', 'UTC'),
        'path' => fixPath(env('WS_DATA_PATH', fn() => env('IN_DOCKER') ? '/config' : realpath(__DIR__ . '/../var'))),
        'logs' => [
            'context' => (bool)env('WS_LOGS_CONTEXT', false),
            'prune' => [
                'after' => env('WS_LOGS_PRUNE_AFTER', '-3 DAYS'),
            ],
        ],
        'storage' => [
            'version' => 'v01',
        ],
        'export' => [
            // -- Trigger full export mode if changes exceed X number.
            'threshold' => env('WS_EXPORT_THRESHOLD', 1000),
        ],
    ];

    $logDateFormat = makeDate()->format('Ymd');

    $config['tmpDir'] = fixPath(env('WS_TMP_DIR', ag($config, 'path')));

    $config['storage'] += [
        'dsn' => 'sqlite:' . ag($config, 'path') . '/db/watchstate_' . ag($config, 'storage.version') . '.db',
        'username' => null,
        'password' => null,
        'options' => [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
        'exec' => [
            'PRAGMA journal_mode=MEMORY',
            'PRAGMA SYNCHRONOUS=OFF'
        ],
    ];

    $config['webhook'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/access.' . $logDateFormat . '.log',
        'debug' => (bool)env('WS_WEBHOOK_DEBUG', false),
        'tokenLength' => 16,
    ];

    $config['mapper'] = [
        'import' => [
            'type' => MemoryMapper::class,
            'opts' => [],
        ],
    ];

    $config['http'] = [
        'default' => [
            'options' => [
                'headers' => [
                    'User-Agent' => ag($config, 'name') . '/' . getAppVersion(),
                ],
                'timeout' => 300.0,
                'extra' => [
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ],
            ],
        ],
    ];

    $config['debug'] = [
        'profiler' => [
            'options' => [
                'save.handler' => 'file',
                'save.handler.file' => [
                    'filename' => ag($config, 'tmpDir') . '/profiler/run.' . makeDate()->format('Ymd_His') . '.json'
                ],
            ],
        ],
    ];

    $config['cache'] = [
        'enabled' => true !== (bool)env('WS_DISABLE_CACHE'),
        'prefix' => env('WS_CACHE_PREFIX', null),
        'url' => env('WS_CACHE_URL', 'redis://127.0.0.1:6379'),
        'path' => env('WS_CACHE_PATH', fn() => ag($config, 'tmpDir') . '/cache'),
    ];

    $config['logger'] = [
        'file' => [
            'type' => 'stream',
            'enabled' => (bool)env('WS_LOGGER_FILE_ENABLE', true),
            'level' => env('WS_LOGGER_FILE_LEVEL', Logger::ERROR),
            'filename' => ag($config, 'tmpDir') . '/logs/log.' . $logDateFormat . '.log',
        ],
        'stderr' => [
            'type' => 'stream',
            'enabled' => 'cli' !== PHP_SAPI,
            'level' => Logger::WARNING,
            'filename' => 'php://stderr',
        ],
        'console' => [
            'type' => 'console',
            'enabled' => 'cli' === PHP_SAPI,
            // -- controllable by -vvv flag -v for NOTICE -vv for INFO -vvv for DEBUG.
            'level' => Logger::WARNING,
        ],
        'syslog' => [
            'type' => 'syslog',
            'docker' => false,
            'facility' => env('WS_LOGGER_SYSLOG_FACILITY', LOG_USER),
            'enabled' => (bool)env('WS_LOGGER_SYSLOG_ENABLED', !env('IN_DOCKER')),
            'level' => env('WS_LOGGER_SYSLOG_LEVEL', Logger::ERROR),
            'name' => ag($config, 'name'),
        ],
    ];

    $config['supported'] = [
        'plex' => PlexServer::class,
        'emby' => EmbyServer::class,
        'jellyfin' => JellyfinServer::class,
    ];

    $config['servers'] = [];

    $config['php'] = [
        'ini' => [
            'disable_functions' => null,
            'display_errors' => 0,
            'error_log' => env('IN_DOCKER') ? '/proc/self/fd/2' : 'syslog',
            'syslog.ident' => 'php-fpm',
            'memory_limit' => '265M',
            'pcre.jit' => 1,
            'opcache.enable' => 1,
            'opcache.memory_consumption' => 128,
            'opcache.interned_strings_buffer' => 8,
            'opcache.max_accelerated_files' => 10000,
            'opcache.max_wasted_percentage' => 5,
            'expose_php' => 0,
            'date.timezone' => ag($config, 'tz', 'UTC'),
            'mbstring.http_input' => ag($config, 'charset', 'UTF-8'),
            'mbstring.http_output' => ag($config, 'charset', 'UTF-8'),
            'mbstring.internal_encoding' => ag($config, 'charset', 'UTF-8'),
        ],
        'fpm' => [
            'www' => [
                'pm' => 'dynamic',
                'pm.max_children' => 10,
                'pm.start_servers' => 1,
                'pm.min_spare_servers' => 1,
                'pm.max_spare_servers' => 3,
                'pm.max_requests' => 1000,
                'pm.status_path' => '/fpm_status',
                'ping.path' => '/fpm_ping',
                'catch_workers_output' => 'yes',
                'decorate_workers_output' => 'no',
            ],
        ],
    ];

    $config['tasks'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/task.' . $logDateFormat . '.log',
        'commands' => [
            ImportCommand::TASK_NAME => [
                Task::NAME => ImportCommand::TASK_NAME,
                Task::ENABLED => (bool)env('WS_CRON_IMPORT', false),
                Task::RUN_AT => (string)env('WS_CRON_IMPORT_AT', '0 */1 * * *'),
                Task::COMMAND => '@state:import',
                Task::ARGS => env('WS_CRON_IMPORT_ARGS', '-v'),
            ],
            ExportCommand::TASK_NAME => [
                Task::NAME => ExportCommand::TASK_NAME,
                Task::ENABLED => (bool)env('WS_CRON_EXPORT', false),
                Task::RUN_AT => (string)env('WS_CRON_EXPORT_AT', '30 */1 * * *'),
                Task::COMMAND => '@state:export',
                Task::ARGS => env('WS_CRON_EXPORT_ARGS', '-v'),
            ],
            PushCommand::TASK_NAME => [
                Task::NAME => PushCommand::TASK_NAME,
                Task::ENABLED => (bool)env('WS_CRON_PUSH', false),
                Task::RUN_AT => (string)env('WS_CRON_PUSH_AT', '*/10 * * * *'),
                Task::COMMAND => '@state:push',
                Task::ARGS => env('WS_CRON_PUSH_ARGS', '-v'),
            ],
            PruneCommand::TASK_NAME => [
                Task::NAME => PruneCommand::TASK_NAME,
                Task::ENABLED => 'disable' !== ag($config, 'logs.prune.after'),
                Task::RUN_AT => (string)env('WS_CRON_PRUNE_AT', '0 */12 * * *'),
                Task::COMMAND => '@system:prune',
                Task::ARGS => env('WS_CRON_PRUNE_ARGS', '-v'),
            ],
        ],
    ];

    return $config;
})();
