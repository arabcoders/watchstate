<?php

declare(strict_types=1);

use App\Libs\Mappers\Export\ExportMapper;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Scheduler\Task;
use App\Libs\Servers\EmbyServer;
use App\Libs\Servers\JellyfinServer;
use App\Libs\Servers\PlexServer;
use App\Libs\Storage\PDO\PDOAdapter;
use Monolog\Logger;

return (function () {
    $config = [
        'name' => 'WatchState',
        'version' => 'v0.0.10-alpha',
        'tz' => null,
        'path' => fixPath(
            env('WS_DATA_PATH', fn() => env('IN_DOCKER') ? '/config' : realpath(__DIR__ . '/../var'))
        ),
    ];

    $config['storage'] = [
        'type' => PDOAdapter::class,
        'opts' => [
            'dsn' => env(
                'WS_STORAGE_PDO_DSN',
                fn() => 'sqlite:' . ag($config, 'path') . '/db/watchstate.db'
            ),
            'username' => null,
            'password' => null,
            'exec' => [
                'sqlite' => [
                    'PRAGMA journal_mode=MEMORY',
                    'PRAGMA SYNCHRONOUS=OFF'
                ],
            ],
            'singleTransaction' => env('WS_STORAGE_PDO_ST', false),
        ],
    ];

    $config['webhook'] = [
        'enabled' => true,
        'debug' => false,
        'apikey' => null,
    ];

    $config['mapper'] = [
        'import' => [
            'type' => env('WS_MAPPER_IMPORT', MemoryMapper::class),
            'opts' => [],
        ],
        'export' => [
            'type' => env('WS_MAPPER_EXPORT', ExportMapper::class),
            'opts' => [],
        ],
    ];

    $config['http'] = [
        'default' => [
            'options' => [
                'headers' => [
                    'User-Agent' => 'WatchState/' . ag($config, 'version'),
                ],
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
                    'filename' => ag($config, 'path') . '/logs/profiler_' . gmdate('Y_m_d_His') . '.json'
                ],
            ],
        ],
    ];

    $config['logger'] = [
        'stderr' => [
            'type' => 'stream',
            'enabled' => env('WS_LOGGER_STDERR_ENABLED', true),
            'level' => env('WS_LOGGER_STDERR_LEVEL', Logger::NOTICE),
            'filename' => 'php://stderr',
        ],
        'file' => [
            'type' => 'stream',
            'enabled' => env('WS_LOGGER_FILE_ENABLE', false),
            'level' => env('WS_LOGGER_FILE_LEVEL', Logger::ERROR),
            'filename' => env('WS_LOGGER_FILE', fn() => ag($config, 'path') . '/logs/app.log'),
        ],
        'syslog' => [
            'type' => 'syslog',
            'facility' => env('WS_LOGGER_SYSLOG_FACILITY', LOG_USER),
            'enabled' => env('WS_LOGGER_SYSLOG_ENABLED', false),
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
            'post_max_size' => '650M',
            'upload_max_filesize' => '300M',
            'memory_limit' => '265M',
            'pcre.jit' => 1,
            'gd.jpeg_ignore_warning' => 1,
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
        'import' => [
            Task::NAME => 'import',
            Task::ENABLED => (bool)env('WS_CRON_IMPORT', true),
            Task::RUN_AT => (string)env('WS_CRON_IMPORT_AT', '0 */1 * * *'),
            Task::COMMAND => '@state:import',
            Task::ARGS => [
                '-vvr' => null,
            ]
        ],
        'export' => [
            Task::NAME => 'export',
            Task::ENABLED => (bool)env('WS_CRON_EXPORT', true),
            Task::RUN_AT => (string)env('WS_CRON_EXPORT_AT', '30 */1 * * *'),
            Task::COMMAND => '@state:export',
            Task::ARGS => [
                '-vvr' => null,
            ]
        ],
    ];

    return $config;
})();
