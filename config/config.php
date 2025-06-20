<?php

declare(strict_types=1);

use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Plex\PlexClient;
use App\Commands\Events\DispatchCommand;
use App\Commands\State\BackupCommand;
use App\Commands\State\ExportCommand;
use App\Commands\State\ImportCommand;
use App\Commands\State\ValidateCommand;
use App\Commands\System\IndexCommand;
use App\Commands\System\PruneCommand;
use App\Libs\Mappers\Import\DirectMapper;
use Cron\CronExpression;
use Monolog\Level;

return (function () {
    $inContainer = inContainer();
    $progressTimeCheck = fn(int $v, int $d): int => 0 === $v || $v >= 180 ? $v : $d;

    $config = [
        'name' => 'WatchState',
        // -- Handled by the build system.
        'version' => 'dev-master',
        'version_sha' => 'unknown',
        'version_build' => 'unknown',
        'version_branch' => 'unknown',
        // -- End handled by the build system.
        'tz' => env('WS_TZ', env('TZ', 'UTC')),
        'path' => fixPath(env('WS_DATA_PATH', fn() => $inContainer ? '/config' : __DIR__ . '/../var')),
        'logs' => [
            'context' => (bool)env('WS_LOGS_CONTEXT', false),
            'prune' => [
                'after' => env('WS_LOGS_PRUNE_AFTER', '-3 DAYS'),
            ],
        ],
        'api' => [
            'prefix' => '/v1/api',
            'key' => env('WS_API_KEY', null),
            'secure' => (bool)env('WS_SECURE_API_ENDPOINTS', false),
            'pattern_match' => [
                'backend' => '[a-zA-Z0-9_\-]+',
                'ubackend' => '[a-zA-Z0-9_\-\@]+',
            ],
            'logInternal' => (bool)env('WS_API_LOG_INTERNAL', false),
            'response' => [
                'encode' => JSON_INVALID_UTF8_IGNORE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Application-Version' => fn() => getAppVersion(),
                    'Access-Control-Allow-Origin' => '*',
                ],
            ],
        ],
        'webui' => [
            'path' => fixPath(env('WS_WEBUI_PATH', __DIR__ . '/../public/exported')),
        ],
        'database' => [
            'version' => 'v01',
        ],
        'library' => [
            // -- this is used to segment backends requests into pages.
            'segment' => (int)env('WS_LIBRARY_SEGMENT', 1_000),
        ],
        'export' => [
            // -- Trigger full export mode if changes exceed X number.
            'threshold' => (int)env('WS_EXPORT_THRESHOLD', 1_000),
            // -- Extra margin for marking item not found for backend in export mode. Default 3 days.
            'not_found' => (int)env('WS_EXPORT_NOT_FOUND', 259_200),
        ],
        'ignore' => [],
        'trust' => [
            'proxy' => (bool)env('WS_TRUST_PROXY', false),
            'header' => (string)env('WS_TRUST_HEADER', 'X-Forwarded-For'),
            'local' => (bool)env('WS_TRUST_LOCAL', false),
            'local_net' => [
                '192.168.0.0/16', // RFC-1918 A-block.
                '127.0.0.1/32', // localhost IPv4
                '10.0.0.0/8', // RFC-1918 C-block.
                '::1/128', // localhost IPv6
                '172.16.0.0/12' // RFC-1918 B-block.
            ],
        ],
        'sync' => [
            'progress' => (bool)env('WS_SYNC_PROGRESS', true),
        ],
        'progress' => [
            // -- Allows to sync watch progress for played items.
            'threshold' => $progressTimeCheck((int)env('WS_PROGRESS_THRESHOLD', 0), 60 * 10),
            // -- Minimum time to consider item as progress sync-able.
            'minThreshold' => 180,
        ],
    ];

    $config['guid'] = [
        'version' => '0.0',
        'file' => fixPath(env('WS_GUID_FILE', ag($config, 'path') . '/config/guid.yaml')),
    ];

    $config['backends_file'] = fixPath(env('WS_BACKENDS_FILE', ag($config, 'path') . '/config/servers.yaml'));
    $config['mapper_file'] = fixPath(env('WS_MAPPER_FILE', ag($config, 'path') . '/config/mapper.yaml'));

    date_default_timezone_set(ag($config, 'tz', 'UTC'));
    $logDateFormat = makeDate()->format('Ymd');

    $config['tmpDir'] = fixPath(env('WS_TMP_DIR', ag($config, 'path')));

    $dbFile = ag($config, 'path') . '/db/watchstate_' . ag($config, 'database.version') . '.db';

    $config['api']['logfile'] = ag($config, 'tmpDir') . '/logs/access.' . $logDateFormat . '.log';

    $config['database'] += [
        'file' => $dbFile,
        'dsn' => 'sqlite:' . $dbFile,
        'username' => null,
        'password' => null,
        'options' => [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
        'exec' => [
            'PRAGMA journal_mode=WAL',
            'PRAGMA busy_timeout=5000',
            'PRAGMA SYNCHRONOUS=NORMAL',
            //'PRAGMA SYNCHRONOUS=OFF'
            //'PRAGMA journal_mode=MEMORY',
        ],
    ];

    $config['webhook'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/webhook.' . $logDateFormat . '.log',
        'dumpRequest' => (bool)env('WS_WEBHOOK_DUMP_REQUEST', false),
        'tokenLength' => (int)env('WS_WEBHOOK_TOKEN_LENGTH', 16),
        'file_format' => (string)env('WS_WEBHOOK_LOG_FILE_FORMAT', 'webhook.{backend}.{event}.{id}.json'),
    ];

    $config['mapper'] = [
        'import' => [
            'type' => DirectMapper::class,
            'opts' => [],
        ],
    ];

    $config['http'] = [
        'default' => [
            'maxRetries' => (int)env('WS_HTTP_MAX_RETRIES', 3),
            'sync_requests' => (bool)env('WS_HTTP_SYNC_REQUESTS', false),
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
        'enabled' => (bool)env('WS_DEBUG', false),
    ];

    $config['profiler'] = [
        'save' => (bool)env('WS_PROFILER_SAVE', true),
        'path' => env('WS_PROFILER_PATH', fn() => ag($config, 'tmpDir') . '/profiler'),
        'collector' => env('WS_PROFILER_COLLECTOR', null),
    ];

    $config['cache'] = [
        'prefix' => env('WS_CACHE_PREFIX', null),
        'url' => env('WS_CACHE_URL', 'redis://127.0.0.1:6379'),
        'path' => env('WS_CACHE_PATH', fn() => ag($config, 'tmpDir') . '/cache'),
    ];

    $config['logger'] = [
        'file' => [
            'type' => 'stream',
            'enabled' => (bool)env('WS_LOGGER_FILE_ENABLE', true),
            'level' => env('WS_LOGGER_FILE_LEVEL', Level::Warning),
            'filename' => ag($config, 'tmpDir') . '/logs/app.' . $logDateFormat . '.log',
        ],
        'stderr' => [
            'type' => 'stream',
            'enabled' => 'cli' !== PHP_SAPI,
            'level' => Level::Warning,
            'filename' => 'php://stderr',
        ],
        'console' => [
            'type' => 'console',
            'enabled' => 'cli' === PHP_SAPI,
            // -- controllable by -vvv flag -v for NOTICE -vv for INFO -vvv for DEBUG.
            'level' => Level::Warning,
        ],
        'syslog' => [
            'type' => 'syslog',
            'docker' => false,
            'facility' => env('WS_LOGGER_SYSLOG_FACILITY', LOG_USER),
            'enabled' => (bool)env('WS_LOGGER_SYSLOG_ENABLED', !$inContainer),
            'level' => env('WS_LOGGER_SYSLOG_LEVEL', Level::Error),
            'name' => ag($config, 'name'),
        ],
        'remote' => [
            'type' => 'remote',
            'enabled' => (bool)env('WS_LOGGER_REMOTE_ENABLE', false),
            'level' => env('WS_LOGGER_REMOTE_LEVEL', Level::Error),
            'url' => env('WS_LOGGER_REMOTE_URL', null),
        ],
    ];

    $config['supported'] = [
        strtolower(PlexClient::CLIENT_NAME) => PlexClient::class,
        strtolower(EmbyClient::CLIENT_NAME) => EmbyClient::class,
        strtolower(JellyfinClient::CLIENT_NAME) => JellyfinClient::class,
    ];

    $config['servers'] = [];
    $config['console'] = [
        'enable' => [
            'all' => (bool)env('WS_CONSOLE_ENABLE_ALL', false),
        ],
    ];

    $config['php'] = [
        'ini' => [
            'disable_functions' => null,
            'display_errors' => 0,
            'error_log' => $inContainer ? '/proc/self/fd/2' : 'syslog',
            'syslog.ident' => $inContainer ? 'frankenphp' : 'php-fpm',
            'memory_limit' => '265M',
            'post_max_size' => '100M',
            'upload_max_filesize' => '100M',
            'zend.exception_ignore_args' => $inContainer ? 1 : 0,
            'pcre.jit' => 1,
            'opcache.enable' => 1,
            'opcache.memory_consumption' => 128,
            'opcache.interned_strings_buffer' => 16,
            'opcache.max_accelerated_files' => 10000,
            'opcache.max_wasted_percentage' => 5,
            'opcache.validate_timestamps' => $inContainer ? 0 : 1,
            'expose_php' => 0,
            'date.timezone' => ag($config, 'tz', 'UTC'),
            'zend.assertions' => -1,
            'short_open_tag' => 0,
            'opcache.jit' => 'disabled',
            'opcache.jit_buffer_size' => 0,
            // @TODO: keep jit disabled for now, as it is not stable yet,. and we haven't tested it with frankenphp
            //'opcache.jit' => $inContainer ? 'tracing' : 'disabled',
            //'opcache.jit_buffer_size' => $inContainer ? '128M' : 0,
        ],
        'fpm' => [
            'global' => [
                'daemonize' => 'no',
                'error_log' => '/proc/self/fd/2',
                'log_limit' => '8192',
            ],
            'www' => [
                'clear_env' => 'no',
                'pm' => 'dynamic',
                'pm.max_children' => 10,
                'pm.start_servers' => 1,
                'pm.min_spare_servers' => 1,
                'pm.max_spare_servers' => 3,
                'pm.max_requests' => 1000,
                'pm.status_path' => '/status',
                'ping.path' => '/ping',
                'catch_workers_output' => 'yes',
                'decorate_workers_output' => 'no',
            ],
        ],
    ];

    $checkTaskTimer = function (string $timer, string $default): string {
        try {
            $isValid = new CronExpression($timer)->getNextRunDate()->getTimestamp() >= 0;
            return $isValid ? $timer : $default;
        } catch (Throwable) {
            return $default;
        }
    };

    $config['tasks'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/task.' . $logDateFormat . '.log',
        'list' => [
            ImportCommand::TASK_NAME => [
                'command' => ImportCommand::ROUTE,
                'name' => ImportCommand::TASK_NAME,
                'info' => 'Import data from backends.',
                'enabled' => (bool)env('WS_CRON_IMPORT', false),
                'timer' => $checkTaskTimer((string)env('WS_CRON_IMPORT_AT', '0 */1 * * *'), '0 */1 * * *'),
                'args' => env('WS_CRON_IMPORT_ARGS', '-v'),
            ],
            ExportCommand::TASK_NAME => [
                'command' => ExportCommand::ROUTE,
                'name' => ExportCommand::TASK_NAME,
                'info' => 'Export data to backends.',
                'enabled' => (bool)env('WS_CRON_EXPORT', false),
                'timer' => $checkTaskTimer((string)env('WS_CRON_EXPORT_AT', '30 */1 * * *'), '30 */1 * * *'),
                'args' => env('WS_CRON_EXPORT_ARGS', '-v'),
            ],
            BackupCommand::TASK_NAME => [
                'command' => BackupCommand::ROUTE,
                'name' => BackupCommand::TASK_NAME,
                'info' => 'Backup backends play states.',
                'enabled' => (bool)env('WS_CRON_BACKUP', true),
                'timer' => $checkTaskTimer((string)env('WS_CRON_BACKUP_AT', '0 6 */3 * *'), '0 6 */3 * *'),
                'args' => env('WS_CRON_BACKUP_ARGS', '-v'),
            ],
            PruneCommand::TASK_NAME => [
                'command' => PruneCommand::ROUTE,
                'name' => PruneCommand::TASK_NAME,
                'info' => 'Delete old logs and backups.',
                'enabled' => (bool)env('WS_CRON_PRUNE', true),
                'timer' => $checkTaskTimer((string)env('WS_CRON_PRUNE_AT', '0 */12 * * *'), '0 */12 * * *'),
                'args' => env('WS_CRON_PRUNE_ARGS', '-v'),
            ],
            IndexCommand::TASK_NAME => [
                'command' => IndexCommand::ROUTE,
                'name' => IndexCommand::TASK_NAME,
                'info' => 'Check database for optimal indexes.',
                'enabled' => (bool)env('WS_CRON_INDEXES', true),
                'timer' => $checkTaskTimer((string)env('WS_CRON_INDEXES_AT', '0 3 * * 3'), '0 3 * * 3'),
                'args' => env('WS_CRON_INDEXES_ARGS', '-v'),
            ],
            ValidateCommand::TASK_NAME => [
                'command' => ValidateCommand::ROUTE,
                'name' => ValidateCommand::TASK_NAME,
                'info' => 'Validate stored backends reference id against the backends.',
                'enabled' => (bool)env('WS_CRON_VALIDATE', true),
                'timer' => $checkTaskTimer((string)env('WS_CRON_VALIDATE_AT', '0 4 */14 * *'), '0 4 */14 * *'),
                'args' => env('WS_CRON_VALIDATE_ARGS', '-v'),
            ],
            DispatchCommand::TASK_NAME => [
                'command' => DispatchCommand::ROUTE,
                'name' => DispatchCommand::TASK_NAME,
                'info' => 'Dispatch queued events to their respective listeners.',
                'enabled' => true,
                'timer' => '* * * * *',
                'args' => '-v',
                'hide' => true,
            ],
        ],
    ];

    $config['events'] = [
        'logfile' => ag($config, 'tmpDir') . '/logs/events.' . $logDateFormat . '.log',
        'listeners' => [
            'cache' => new DateInterval(env('WS_EVENTS_LISTENERS_CACHE', 'PT1M')),
            'file' => env('APP_EVENTS_FILE', function () use ($config): string|null {
                $file = ag($config, 'path') . '/config/events.php';
                return file_exists($file) ? $file : null;
            }),
            'locations' => [
                __DIR__ . '/../src/API/',
                __DIR__ . '/../src/Backends/',
                __DIR__ . '/../src/Commands/',
                __DIR__ . '/../src/Listeners/',
            ]
        ],
    ];

    $config['password'] = [
        'prefix' => 'ws_hash@:',
        'algo' => PASSWORD_BCRYPT,
        'options' => ['cost' => 12],
    ];

    $config['system'] = [
        'user' => env('WS_SYSTEM_USER', null),
        'secret' => env('WS_SYSTEM_SECRET', null),
        'password' => env('WS_SYSTEM_PASSWORD', null),
    ];

    return $config;
})();
