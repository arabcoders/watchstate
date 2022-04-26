<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Mappers\Export\ExportMapper;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use Monolog\Logger;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

return (function (): array {
    return [
        LoggerInterface::class => [
            'class' => fn() => new Logger('logger')
        ],

        HttpClientInterface::class => [
            'class' => function (): HttpClientInterface {
                return new CurlHttpClient(
                    defaultOptions:     Config::get('http.default.options', []),
                    maxHostConnections: Config::get('http.default.maxHostConnections', 25),
                    maxPendingPushes:   Config::get('http.default.maxPendingPushes', 50),
                );
            }
        ],

        StateInterface::class => [
            'class' => fn() => new StateEntity([])
        ],

        CacheInterface::class => [
            'class' => fn() => new Psr16Cache(new FilesystemAdapter(directory: Config::get('cache.config.directory')))
        ],

        UriInterface::class => [
            'class' => fn() => new Uri(''),
            'shared' => false,
        ],

        PDO::class => [
            'class' => function (): PDO {
                $pdo = new PDO(dsn: Config::get('storage.dsn'), options: [
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                foreach (Config::get('storage.exec', []) as $cmd) {
                    $pdo->exec($cmd);
                }

                return $pdo;
            },
        ],

        StorageInterface::class => [
            'class' => function (LoggerInterface $logger, PDO $pdo): StorageInterface {
                $adapter = new PDOAdapter($logger, $pdo);

                if (true !== $adapter->isMigrated()) {
                    $adapter->migrations(StorageInterface::MIGRATE_UP);
                }

                return $adapter;
            },
            'args' => [
                LoggerInterface::class,
                PDO::class,
            ],
        ],

        MemoryMapper::class => [
            'class' => function (LoggerInterface $logger, StorageInterface $storage): ImportInterface {
                return (new MemoryMapper($logger, $storage))->setUp(Config::get('mapper.import.opts', []));
            },
            'args' => [
                LoggerInterface::class,
                StorageInterface::class,
            ],
        ],

        DirectMapper::class => [
            'class' => function (LoggerInterface $logger, StorageInterface $storage): ImportInterface {
                return (new DirectMapper($logger, $storage))->setUp(Config::get('mapper.import.opts', []));
            },
            'args' => [
                LoggerInterface::class,
                StorageInterface::class,
            ],
        ],

        ImportInterface::class => [
            'class' => function (ImportInterface $mapper): ImportInterface {
                return $mapper;
            },
            'args' => [
                MemoryMapper::class
            ],
        ],

        ExportMapper::class => [
            'class' => function (StorageInterface $storage): ExportInterface {
                return (new ExportMapper($storage))->setUp(Config::get('mapper.export.opts', []));
            },
            'args' => [
                StorageInterface::class,
            ],
        ],

        ExportInterface::class => [
            'class' => function (ExportInterface $mapper): ExportInterface {
                return $mapper;
            },
            'args' => [
                ExportMapper::class
            ],
        ],
    ];
})();
