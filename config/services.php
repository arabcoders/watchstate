<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Events\EventQueue;
use App\Libs\Events\Queue\ArrayEventTransport;
use App\Libs\Events\Queue\EventTransportInterface;
use App\Libs\Events\Queue\FilesystemEventTransport;
use App\Libs\Events\Queue\NullEventTransport;
use App\Libs\Events\Queue\RedisStreamEventTransport;
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
use App\Model\Events\EventsRepository;
use arabcoders\database\Connection as DatabaseConnection;
use arabcoders\database\ConnectionManager;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Dialect\DialectInterface;
use arabcoders\database\Orm\OrmManager;
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
            'class' => fn() => new Logger(name: 'logger', processors: [new LogMessageProcessor()]),
        ],

        HttpClientInterface::class => [
            'class' => function (iLogger $logger): HttpClientInterface {
                $instance = new HttpClient(
                    new CurlHttpClient(
                        defaultOptions: Config::get('http.default.options', []),
                        maxHostConnections: Config::get('http.default.maxHostConnections', 25),
                        maxPendingPushes: Config::get('http.default.maxPendingPushes', 50),
                    ),
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
                    maxRetries: (int) Config::get('http.default.maxRetries', 3),
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
            'class' => fn() => new StateEntity([]),
        ],

        QueueRequests::class => [
            'class' => fn() => new QueueRequests(),
        ],

        EventTransportInterface::class => [
            'class' => function (): EventTransportInterface {
                $driver = strtolower((string) Config::get('events.queue.driver', 'auto'));

                if ('null' === $driver) {
                    return new NullEventTransport();
                }

                if ('array' === $driver || true === (defined('IN_TEST_MODE') && true === IN_TEST_MODE) && 'auto' === $driver) {
                    return new ArrayEventTransport();
                }

                if ('file' === $driver) {
                    return new FilesystemEventTransport(
                        path: (string) Config::get('events.queue.path'),
                        claimAfterSeconds: (int) Config::get('events.queue.file.claim_after_seconds', 300),
                    );
                }

                try {
                    return new RedisStreamEventTransport(
                        redis: Container::get(Redis::class),
                        stream: (string) Config::get('events.queue.redis.stream'),
                        group: (string) Config::get('events.queue.redis.group'),
                        consumer: (string) Config::get('events.queue.redis.consumer'),
                        claimAfterMs: (int) Config::get('events.queue.redis.claim_after_ms', 300_000),
                    );
                } catch (Throwable $e) {
                    if ('redis' === $driver) {
                        throw $e;
                    }

                    return new FilesystemEventTransport(
                        path: (string) Config::get('events.queue.path'),
                        claimAfterSeconds: (int) Config::get('events.queue.file.claim_after_seconds', 300),
                    );
                }
            },
        ],

        EventQueue::class => [
            'class' => fn(EventTransportInterface $transport, EventsRepository $repo): EventQueue => new EventQueue($transport, $repo),
            'args' => [
                EventTransportInterface::class,
                EventsRepository::class,
            ],
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
                    $redis->select((int) ag($params, 'db'));
                }

                return $redis;
            },
        ],

        iCache::class => [
            'class' => function () {
                if (true === (bool) env('WS_CACHE_NULL', false)) {
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
                        $redis->select((int) ag($params, 'db'));
                    }

                    $backend = new RedisAdapter(redis: $redis, namespace: $ns);
                } catch (Throwable) {
                    // -- in case of error, fallback to file system cache.
                    $backend = new FilesystemAdapter(namespace: $ns, directory: Config::get('cache.path'));
                }

                return new Psr16Cache($backend);
            },
        ],

        UriInterface::class => [
            'class' => fn() => new Uri(''),
            'shared' => false,
        ],

        InputInterface::class => [
            'class' => fn(): InputInterface => new ArgvInput(),
        ],

        OutputInterface::class => [
            'class' => fn(): OutputInterface => new ConsoleOutput(),
        ],

        PDO::class => [
            'class' => function (PdoFactory $factory): PDO {
                return $factory->createMain();
            },
            'args' => [PdoFactory::class],
        ],

        DialectInterface::class => [
            'class' => fn(PDO $pdo) => DialectFactory::fromPdo($pdo),
            'args' => [
                PDO::class,
            ],
        ],

        DatabaseConnection::class => [
            'class' => fn(PDO $pdo, DialectInterface $dialect) => new DatabaseConnection($pdo, $dialect),
            'args' => [
                PDO::class,
                DialectInterface::class,
            ],
        ],

        ConnectionManager::class => [
            'class' => static function (DatabaseConnection $connection): ConnectionManager {
                $manager = new ConnectionManager();
                $manager->register('default', $connection);

                return $manager;
            },
            'args' => [
                DatabaseConnection::class,
            ],
        ],

        OrmManager::class => [
            'class' => fn(ConnectionManager $connections, EventDispatcherInterface $ed) => new OrmManager($connections, dispatcher: $ed),
            'args' => [
                ConnectionManager::class,
                EventDispatcherInterface::class,
            ],
        ],

        DBLayer::class => [
            'class' => fn(PDO $pdo): DBLayer => new DBLayer($pdo),
            'args' => [
                PDO::class,
            ],
        ],

        iDB::class => [
            'class' => fn(iLogger $logger, DBLayer $db): iDB => new PDOAdapter($logger, $db),
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
                iCache::class,
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
                iCache::class,
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
                    file: (string) Config::get('backends_file'),
                    type: 'yaml',
                    autoSave: false,
                    autoCreate: true,
                ),
                mapper: $mapper,
                cache: $cache,
                db: $db,
            ),
            'args' => [iCache::class, iImport::class, iDB::class],
        ],
    ];
})();
