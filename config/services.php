<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use Monolog\Logger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

return (function (): array {
    return [
        iLogger::class => [
            'class' => fn() => new Logger(name: 'logger', processors: [new LogMessageProcessor()])
        ],

        HttpClientInterface::class => [
            'class' => function (iLogger $logger): HttpClientInterface {
                $instance = new HttpClient(
                    new CurlHttpClient(
                        defaultOptions: Config::get('http.default.options', []),
                        maxHostConnections: Config::get('http.default.maxHostConnections', 25),
                        maxPendingPushes: Config::get('http.default.maxPendingPushes', 50),
                    )
                );
                $instance->setLogger($logger);
                return $instance;
            },
            'args' => [
                iLogger::class,
            ],
        ],

        LogSuppressor::class => [
            'class' => function (): LogSuppressor {
                $suppress = [];

                $suppressFile = Config::get('path') . '/config/suppress.yaml';
                if (file_exists($suppressFile) && filesize($suppressFile) > 5) {
                    $suppress = Yaml::parseFile($suppressFile);
                }

                return new LogSuppressor($suppress);
            },
        ],

        StateInterface::class => [
            'class' => fn() => new StateEntity([])
        ],

        QueueRequests::class => [
            'class' => fn() => new QueueRequests()
        ],

        Redis::class => [
            'class' => function (): Redis {
                $cacheUrl = Config::get('cache.url');

                if (empty($cacheUrl)) {
                    throw new RuntimeException('No cache server was set.');
                }

                if (!extension_loaded('redis')) {
                    throw new RuntimeException('Redis extension is not loaded.');
                }

                $uri = new Uri($cacheUrl);
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

                return $redis;
            }
        ],

        CacheInterface::class => [
            'class' => function () {
                if (true === (bool)env('WS_CACHE_NULL', false)) {
                    return new Psr16Cache(new NullAdapter());
                }

                $ns = getAppVersion();

                if (null !== ($prefix = Config::get('cache.prefix')) && true === isValidName($prefix)) {
                    $ns .= '.' . $prefix;
                }

                try {
                    $cacheUrl = Config::get('cache.url');

                    if (empty($cacheUrl)) {
                        throw new RuntimeException('No cache server was set.');
                    }

                    if (!extension_loaded('redis')) {
                        throw new RuntimeException('Redis extension is not loaded.');
                    }

                    $uri = new Uri($cacheUrl);
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

                    $backend = new RedisAdapter(redis: $redis, namespace: $ns);
                } catch (Throwable) {
                    // -- in case of error, fallback to file system cache.
                    $backend = new FilesystemAdapter(namespace: $ns, directory: Config::get('cache.path'));
                }

                return new Psr16Cache($backend);
            }
        ],

        UriInterface::class => [
            'class' => fn() => new Uri(''),
            'shared' => false,
        ],

        InputInterface::class => [
            'class' => fn(): InputInterface => new ArgvInput()
        ],

        OutputInterface::class => [
            'class' => fn(): OutputInterface => new ConsoleOutput()
        ],

        PDO::class => [
            'class' => function (): PDO {
                $dbFile = Config::get('database.file');
                $changePerm = !file_exists($dbFile);

                $pdo = new PDO(dsn: Config::get('database.dsn'), options: Config::get('database.options', []));

                if ($changePerm && inContainer() && 777 !== (int)(decoct(fileperms($dbFile) & 0777))) {
                    @chmod($dbFile, 0777);
                }

                foreach (Config::get('database.exec', []) as $cmd) {
                    $pdo->exec($cmd);
                }

                return $pdo;
            },
        ],

        iDB::class => [
            'class' => function (iLogger $logger, PDO $pdo): iDB {
                $adapter = new PDOAdapter($logger, $pdo);

                if (true !== $adapter->isMigrated()) {
                    $adapter->migrations(iDB::MIGRATE_UP);
                    $adapter->ensureIndex();
                    $adapter->migrateData(
                        Config::get('database.version'),
                        Container::get(iLogger::class)
                    );
                }

                return $adapter;
            },
            'args' => [
                iLogger::class,
                PDO::class,
            ],
        ],

        DBLayer::class => [
            'class' => fn(PDO $pdo): DBLayer => new DBLayer($pdo),
            'args' => [
                PDO::class,
            ],
        ],

        MemoryMapper::class => [
            'class' => function (iLogger $logger, iDB $db, CacheInterface $cache): iImport {
                return (new MemoryMapper(logger: $logger, db: $db, cache: $cache))
                    ->setOptions(options: Config::get('mapper.import.opts', []));
            },
            'args' => [
                iLogger::class,
                iDB::class,
                CacheInterface::class
            ],
        ],

        DirectMapper::class => [
            'class' => function (iLogger $logger, iDB $db, CacheInterface $cache): iImport {
                return (new DirectMapper(logger: $logger, db: $db, cache: $cache))
                    ->setOptions(options: Config::get('mapper.import.opts', []));
            },
            'args' => [
                iLogger::class,
                iDB::class,
                CacheInterface::class
            ],
        ],

        iImport::class => [
            'class' => function (iImport $mapper): iImport {
                return $mapper;
            },
            'args' => [
                MemoryMapper::class
            ],
        ],

        EventDispatcherInterface::class => [
            'class' => fn(): EventDispatcher => new EventDispatcher(),
        ],

    ];
})();
