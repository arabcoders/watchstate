<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Mappers\Export\ExportMapper;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Scheduler\Task;
use App\Libs\Servers\EmbyServer;
use App\Libs\Servers\JellyfinServer;
use App\Libs\Servers\PlexServer;
use App\Libs\Storage\PDO\PDOAdapter;
use GuzzleHttp\RequestOptions;
use Monolog\Logger;

return (function () {
    $config = [
        'name' => 'WatchState',
        'version' => 'v0.0.2',
        'tz' => null,
        'path' => fixPath(
            env('WS_DATA_PATH', fn() => env('IN_DOCKER') ? '/config' : realpath(__DIR__ . DS . '..' . DS . 'var'))
        ),
    ];

    $config['storage'] = [
        'type' => PDOAdapter::class,
        'opts' => [
            'dsn' => 'sqlite:' . ag($config, 'path') . DS . 'db' . DS . 'watchstate.db',
            'username' => null,
            'password' => null,
            'options' => [],
            'exec' => [
                'sqlite' => [
                    'PRAGMA journal_mode=WAL'
                ],
            ],
        ],
    ];

    $config['webhook'] = [
        'enabled' => true,
        'debug' => false,
        'apikey' => null,
    ];

    $config['mapper'] = [
        'import' => [
            'type' => MemoryMapper::class,
            'opts' => [
                'lazyload' => true
            ],
        ],
        'export' => [
            'type' => ExportMapper::class,
            'opts' => [
                'lazyload' => true
            ],
        ],
    ];

    $config['request'] = [
        'default' => [
            'options' => [
                RequestOptions::FORCE_IP_RESOLVE => 'v4',
                RequestOptions::HEADERS => [
                    'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'WatchState/' . Config::get('version'),
                ],
            ]
        ],
        'export' => [
            'concurrency' => 75
        ],
    ];

    $config['debug'] = [
        'profiler' => [
            'options' => [
                'save.handler' => 'file',
                'save.handler.file' => [
                    'filename' => ag($config, 'path') . DS . 'logs' . DS . 'profiler_' . gmdate('Y_m_d_His') . '.json'
                ],
            ],
        ],
    ];

    $config['logger'] = [
        'stderr' => [
            'type' => 'stream',
            'enabled' => true,
            'level' => Logger::DEBUG,
            'filename' => 'php://stderr',
        ],
        'file' => [
            'type' => 'stream',
            'enabled' => false,
            'level' => Logger::INFO,
            'filename' => ag($config, 'path') . DS . 'logs' . DS . 'app.log',
        ],
        'syslog' => [
            'type' => 'syslog',
            'facility' => LOG_USER,
            'enabled' => false,
            'level' => Logger::INFO,
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
            Task::RUN_AT => (string)env('WS_CRON_EXPORT_AT', '1 */1 * * *'),
            Task::COMMAND => '@state:import',
            Task::ARGS => [
                '-vvrm' => null,
            ]
        ],
        'export' => [
            Task::NAME => 'export',
            Task::ENABLED => (bool)env('WS_CRON_EXPORT', true),
            Task::RUN_AT => (string)env('WS_CRON_EXPORT_AT', '30 */1 * * *'),
            Task::COMMAND => '@state:export',
            Task::ARGS => [
                '-vvrm' => null,
            ]
        ],
    ];

    return $config;
})();
