<?php

declare(strict_types=1);

use App\Commands\Config\PruneCommand;
use App\Commands\State\CacheCommand;
use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\State\PushCommand;
use App\Libs\Mappers\Export\ExportMapper;
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
            'prune' => [
                'after' => env('WS_LOGS_PRUNE_AFTER', '-3 DAYS'),
            ],
        ],
        'storage' => [
            'version' => 'v0',
        ],
    ];

    $config['tmpDir'] = fixPath(env('WS_TMP_DIR', $config['path']));

    $config['storage'] += [
        'dsn' => 'sqlite:' . ag($config, 'path') . '/db/watchstate_' . $config['storage']['version'] . '.db',
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
        'debug' => (bool)env('WS_WEBHOOK_DEBUG', false),
        'tokenLength' => (int)env('WS_WEBHOOK_TOKEN_LENGTH', 16),
    ];

    $config['mapper'] = [
        'import' => [
            'type' => MemoryMapper::class,
            'opts' => [],
        ],
        'export' => [
            'type' => ExportMapper::class,
            'opts' => [],
        ],
    ];

    $config['http'] = [
        'default' => [
            'options' => [
                'headers' => [
                    'User-Agent' => 'WatchState/' . ag($config, 'version'),
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
        'import' => (bool)env('WS_DEBUG_IMPORT', false),
        'profiler' => [
            'options' => [
                'save.handler' => 'file',
                'save.handler.file' => [
                    'filename' => ag($config, 'tmpDir') . '/profiler/profiler_' . gmdate('Y_m_d_His') . '.json'
                ],
            ],
        ],
    ];

    $config['logger'] = [
        'file' => [
            'type' => 'stream',
            'enabled' => env('WS_LOGGER_FILE_ENABLE', true),
            'level' => env('WS_LOGGER_FILE_LEVEL', Logger::ERROR),
            'filename' => env('WS_LOGGER_FILE', fn() => ag($config, 'tmpDir') . '/logs/app.log'),
        ],
        'stderr' => [
            'type' => 'stream',
            'enabled' => env('WS_LOGGER_STDERR_ENABLED', true),
            'level' => env('WS_LOGGER_STDERR_LEVEL', Logger::NOTICE),
            'filename' => 'php://stderr',
        ],
        'syslog' => [
            'type' => 'syslog',
            'docker' => false,
            'facility' => env('WS_LOGGER_SYSLOG_FACILITY', LOG_USER),
            'enabled' => env('WS_LOGGER_SYSLOG_ENABLED', !env('IN_DOCKER')),
            'level' => env('WS_LOGGER_SYSLOG_LEVEL', Logger::ERROR),
            'name' => env('WS_LOGGER_SYSLOG_NAME', ag($config, 'name')),
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
        ImportCommand::TASK_NAME => [
            Task::NAME => ImportCommand::TASK_NAME,
            Task::ENABLED => (bool)env('WS_CRON_IMPORT', false),
            Task::RUN_AT => (string)env('WS_CRON_IMPORT_AT', '0 */1 * * *'),
            Task::COMMAND => '@state:import',
            Task::ARGS => [
                env('WS_CRON_IMPORT_DEBUG_LEVEL', '-v') => null,
            ]
        ],
        ExportCommand::TASK_NAME => [
            Task::NAME => ExportCommand::TASK_NAME,
            Task::ENABLED => (bool)env('WS_CRON_EXPORT', false),
            Task::RUN_AT => (string)env('WS_CRON_EXPORT_AT', '30 */1 * * *'),
            Task::COMMAND => '@state:export',
            Task::ARGS => [
                env('WS_CRON_EXPORT_DEBUG_LEVEL', '-v') => null,
            ]
        ],
        PushCommand::TASK_NAME => [
            Task::NAME => PushCommand::TASK_NAME,
            Task::ENABLED => (bool)env('WS_CRON_PUSH', false),
            Task::RUN_AT => (string)env('WS_CRON_PUSH_AT', '*/10 * * * *'),
            Task::COMMAND => '@state:push',
            Task::ARGS => [
                env('WS_CRON_PUSH_DEBUG_LEVEL', '-v') => null,
            ]
        ],
        CacheCommand::TASK_NAME => [
            Task::NAME => CacheCommand::TASK_NAME,
            Task::ENABLED => (bool)env('WS_CRON_CACHE', true),
            Task::RUN_AT => (string)env('WS_CRON_CACHE_AT', '0 */6 * * *'),
            Task::COMMAND => '@state:cache',
            Task::ARGS => [
                env('WS_CRON_CACHE_DEBUG_LEVEL', '-v') => null,
            ]
        ],
        PruneCommand::TASK_NAME => [
            Task::NAME => PruneCommand::TASK_NAME,
            Task::ENABLED => true,
            Task::RUN_AT => '0 */12 * * *',
            Task::COMMAND => '@config:prune',
            Task::ARGS => [
                '-v' => null,
            ]
        ],
    ];

    return $config;
})();
