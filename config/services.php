<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface;
use App\Libs\QueueRequests;
use App\Libs\Storage\PDO\PDOAdapter;
use App\Libs\Storage\StorageInterface;
use Monolog\Logger;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Output\OutputInterface;

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

        QueueRequests::class => [
            'class' => fn() => new QueueRequests()
        ],

        CacheInterface::class => [
            'class' => function () {
                if (!extension_loaded('redis')) {
                    return new Psr16Cache(
                        new FilesystemAdapter(
                            namespace: getAppVersion(),
                            directory: Config::get('cache.path')
                        )
                    );
                }

                try {
                    $uri = new Uri(Config::get('cache.url'));
                    $params = [];

                    if (!empty($uri->getQuery())) {
                        parse_str($uri->getQuery(), $params);
                    }

                    $redis = new Redis();

                    $redis->connect($uri->getHost(), $uri->getPort() ?? 6379);

                    if (null !== ag($params, 'password')) {
                        $redis->auth(ag($params, 'password'));
                    }

                    if (null !== ag($params, 'db')) {
                        $redis->select((int)ag($params, 'db'));
                    }

                    $backend = new RedisAdapter(
                        redis:     $redis,
                        namespace: getAppVersion()
                    );
                } catch (Throwable) {
                    $backend = new FilesystemAdapter(
                        namespace: getAppVersion(),
                        directory: Config::get('cache.path')
                    );
                }

                return new Psr16Cache($backend);
            }
        ],

        UriInterface::class => [
            'class' => fn() => new Uri(''),
            'shared' => false,
        ],

        OutputInterface::class => [
            'class' => fn(): OutputInterface => new ConsoleOutput()
        ],

        PDO::class => [
            'class' => function (): PDO {
                $pdo = new PDO(dsn: Config::get('storage.dsn'), options: Config::get('storage.options', []));

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
                    $adapter->migrateData(
                        Config::get('storage.version'),
                        Container::get(LoggerInterface::class)
                    );
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
                return (new MemoryMapper(logger: $logger, storage: $storage))
                    ->setOptions(options: Config::get('mapper.import.opts', []));
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
    ];
})();
