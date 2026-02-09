<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\ConfigFile;
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
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\Import\ReadOnlyMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use App\Libs\UserContext;
use Monolog\Logger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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

        RetryableHttpClient::class => [
            'class' => function (HttpClientInterface $client, iLogger $logger): RetryableHttpClient {
                return new RetryableHttpClient(
                    client: $client,
                    maxRetries: (int)Config::get('http.default.maxRetries', 3),
                    logger: $logger,
                );
            },
            'args' => [
                HttpClientInterface::class,
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

        iCache::class => [
            'class' => function () {
                if (true === (bool)env('WS_CACHE_NULL', false)) {
                    return new Psr16Cache(new NullAdapter());
                }

                if (true === (defined('IN_TEST_MODE') && true === IN_TEST_MODE)) {
                    return new Psr16Cache(new ArrayAdapter());
                }

                $ns = get_app_version();

                if (null !== ($prefix = Config::get('cache.prefix')) && true === is_valid_name($prefix)) {
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
                $inTestMode = true === (defined('IN_TEST_MODE') && true === IN_TEST_MODE);
                $dsn = $inTestMode ? 'sqlite::memory:' : Config::get('database.dsn');

                if (false === $inTestMode) {
                    $dbFile = Config::get('database.file');
                    $changePerm = !file_exists($dbFile);
                }

                $pdo = new PDO(dsn: $dsn, options: Config::get('database.options', []));

                if (!$inTestMode && $changePerm && in_container() && 777 !== (int)(decoct(fileperms($dbFile) & 0777))) {
                    @chmod($dbFile, 0777);
                }

                foreach (Config::get('database.exec', []) as $cmd) {
                    $pdo->exec($cmd);
                }

                return $pdo;
            },
        ],

        DBLayer::class => [
            'class' => fn(PDO $pdo): DBLayer => new DBLayer($pdo),
            'args' => [
                PDO::class,
            ],
        ],

        iDB::class => [
            'class' => function (iLogger $logger, DBLayer $pdo): iDB {
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
                DBLayer::class,
            ],
        ],

        ReadOnlyMapper::class => [
            'class' => function (iLogger $logger, iDB $db, iCache $cache): iImport {
                return new ReadOnlyMapper(logger: $logger, db: $db, cache: $cache)
                    ->setOptions(options: Config::get('mapper.import.opts', []));
            },
            'args' => [
                iLogger::class,
                iDB::class,
                iCache::class
            ],
        ],

        DirectMapper::class => [
            'class' => function (iLogger $logger, iDB $db, iCache $cache): iImport {
                return new DirectMapper(logger: $logger, db: $db, cache: $cache)
                    ->setOptions(options: Config::get('mapper.import.opts', []));
            },
            'args' => [
                iLogger::class,
                iDB::class,
                iCache::class
            ],
        ],

        iImport::class => [
            'class' => fn(iImport $mapper): iImport => $mapper,
            'args' => [DirectMapper::class],
        ],

        EventDispatcherInterface::class => [
            'class' => fn(): EventDispatcher => new EventDispatcher(),
        ],

        UserContext::class => [
            'class' => fn(iCache $cache, iImport $mapper, iDB $db): UserContext => new UserContext(
                name: 'main',
                config: new ConfigFile(
                    file: (string)Config::get('backends_file'),
                    type: 'yaml',
                    autoSave: false,
                    autoCreate: true
                ),
                mapper: $mapper,
                cache: $cache,
                db: $db
            ),
            'args' => [iCache::class, iImport::class, iDB::class]
        ],
    ];
})();
