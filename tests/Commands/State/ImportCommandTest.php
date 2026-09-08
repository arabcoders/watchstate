<?php

declare(strict_types=1);

namespace Tests\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Request;
use App\Commands\State\ImportCommand;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Guid;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Closure;
use Generator;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Tests\Support\FakeBackendClient;
use Tests\Support\StateCommandTestSupport;

final class ImportCommandTest extends TestCase
{
    use StateCommandTestSupport;

    public function test_fake_import(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fake_import', [
            'import' => [
                'enabled' => false,
            ],
        ]));
        $this->migrateMainDb($logger);
        FakeBackendClient::reset();

        $command = new ImportCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ImportCommand::SUCCESS, $status);
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
        self::assertSame([], FakeBackendClient::getCalls('backup'));

        $saved = Yaml::parseFile((string) Config::get('backends_file'));
        self::assertFalse(ag_exists(ag($saved, 'fake_import.options', []), 'IMPORT_METADATA_ONLY'));
    }

    public function test_buffer(): void
    {
        $http = new MockHttpClient(static fn(): MockResponse => new MockResponse('buffered'));
        $response = $http->request('GET', 'http://example.test', [
            'buffer' => static fn() => tmpfile(),
        ]);

        self::assertSame('buffered', implode('', iterator_to_array(http_client_chunks($response))));
        self::assertSame('buffered', implode('', iterator_to_array(http_client_chunks($response))));
    }

    public function test_download(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('page'));
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $emissions = [];
        $logger->pushProcessor(static function (LogRecord $record) use (&$emissions): LogRecord {
            $operation = (string) ag($record->context, 'operation', '');
            if (true === str_starts_with($operation, 'import.transaction.')) {
                $emissions[] = [$operation, Container::get(PDO::class)->inTransaction()];
            }

            return $record;
        });
        $downloadOutsideTransaction = false;
        $body = '';
        $this->initFileDatabase($logger);
        $this->migrateMainDb($logger);
        $writer = new PDO((string) Config::get('database.dsn'));
        self::assertNotSame($writer, Container::get(PDO::class));
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(function () use ($writer, &$downloadOutsideTransaction): MockResponse {
                return new MockResponse(
                    (function () use ($writer, &$downloadOutsideTransaction): Generator {
                        $downloadOutsideTransaction = false === Container::get(PDO::class)->inTransaction();
                        yield 'first';
                        $this->insertState($writer);
                        yield 'second';
                    })(),
                );
            }),
            function (iImport $mapper) use (&$body): array {
                return [new Request(
                    method: Method::GET,
                    url: 'https://example.test/page',
                    extras: [
                        'logContext' => [
                            'identity' => ['user' => 'main', 'backend' => 'page'],
                            'library' => ['title' => 'Movies'],
                            'segment' => ['number' => 1, 'of' => 1],
                        ],
                    ],
                    success: function (iResponse $response) use ($mapper, &$body): array {
                        $body = implode('', iterator_to_array(http_client_chunks($response)));
                        $mapper->add($this->pageState());

                        return [];
                    },
                )];
            },
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute([]));
        self::assertTrue($downloadOutsideTransaction);
        self::assertSame('firstsecond', $body);
        self::assertSame(1, (int) Container::get(PDO::class)->query('SELECT COUNT(*) FROM state')->fetchColumn());

        $transactions = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => str_starts_with((string) ag($record->context, 'operation', ''), 'import.transaction.'),
        ));
        self::assertCount(1, $transactions);
        self::assertSame('import.transaction.end', $transactions[0]->context['operation']);
        self::assertSame('main', $transactions[0]->context['identity']['user']);
        self::assertSame(
            [['import.transaction.end', false]],
            $emissions,
        );
        self::assertFalse(Container::get(PDO::class)->inTransaction());
        self::assertSame(1, $transactions[0]->context['transactions']);
        self::assertSame(0, $transactions[0]->context['failures']);
        self::assertSame('completed', $transactions[0]->context['outcome']);
        self::assertGreaterThanOrEqual(0.0, $transactions[0]->context['duration']);
        self::assertGreaterThanOrEqual(0.0, $transactions[0]->context['max_duration']);
    }

    public function test_dry_page(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('dry_page'));
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $inTransaction = true;
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(static fn(): MockResponse => new MockResponse('body')),
            function (iImport $mapper) use (&$inTransaction): array {
                return [new Request(
                    method: Method::GET,
                    url: 'https://example.test/page',
                    success: function () use ($mapper, &$inTransaction): array {
                        $inTransaction = Container::get(PDO::class)->inTransaction();
                        $mapper->add($this->pageState());

                        return [];
                    },
                )];
            },
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertFalse($inTransaction);
        self::assertSame([], array_filter(
            $handler->getRecords(),
            static fn($record): bool => str_starts_with((string) ag($record->context, 'operation', ''), 'import.transaction.'),
        ));
    }

    public function test_page_commit(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('pages'));
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $page = 0;
        $secondFetchSawCommit = false;
        $secondFetchCount = 0;
        $this->initFileDatabase($logger);
        $this->migrateMainDb($logger);
        $reader = new PDO((string) Config::get('database.dsn'));
        self::assertSame(
            1,
            (int) $reader->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'state'")->fetchColumn(),
        );
        $http = new MockHttpClient(function () use (&$page, &$secondFetchSawCommit, &$secondFetchCount, $reader): MockResponse {
            $page++;
            $currentPage = $page;
            $body = (function () use ($currentPage, &$secondFetchSawCommit, &$secondFetchCount, $reader): Generator {
                if (2 === $currentPage) {
                    $secondFetchSawCommit = false === Container::get(PDO::class)->inTransaction();
                    $secondFetchCount = (int) $reader->query('SELECT COUNT(*) FROM state')->fetchColumn();
                }

                yield 'page';
            })();

            return new MockResponse($body);
        });
        $tester = $this->makeRequestCommand($logger, $http, function (iImport $mapper): array {
            return [new Request(
                method: Method::GET,
                url: 'https://example.test/first',
                success: function () use ($mapper): array {
                    $mapper->add($this->pageState('pages'));

                    return [new Request(
                        method: Method::GET,
                        url: 'https://example.test/second',
                        success: static fn(): array => [],
                    )];
                },
            )];
        });

        self::assertSame(ImportCommand::SUCCESS, $tester->execute(['--sync-requests' => true]));
        self::assertTrue($secondFetchSawCommit);
        self::assertSame(1, $secondFetchCount);
        $transactions = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => str_starts_with((string) ag($record->context, 'operation', ''), 'import.transaction.'),
        ));
        self::assertCount(1, $transactions);
        self::assertSame('import.transaction.end', $transactions[0]->context['operation']);
        self::assertSame(2, $transactions[0]->context['transactions']);
        self::assertSame(0, $transactions[0]->context['failures']);
        self::assertSame('completed', $transactions[0]->context['outcome']);
        self::assertGreaterThanOrEqual(0.0, $transactions[0]->context['duration']);
        self::assertGreaterThanOrEqual(0.0, $transactions[0]->context['max_duration']);
    }

    public function test_order(): void
    {
        $logger = $this->initFakeBackendApp([
            ...$this->fakeBackendConfig('metadata_first', [
                'import' => [
                    'enabled' => false,
                ],
            ]),
            ...$this->fakeBackendConfig('full_second', [
                'import' => [
                    'enabled' => true,
                ],
            ]),
        ]);
        $this->migrateMainDb($logger);

        FakeBackendClient::reset();

        $command = new ImportCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        $status = $this->makeTester($command)->execute([]);

        self::assertSame(ImportCommand::SUCCESS, $status);
        self::assertSame(
            [
                [
                    'backend' => 'full_second',
                    'user' => 'main',
                    'after' => 1_700_000_000,
                ],
                [
                    'backend' => 'metadata_first',
                    'user' => 'main',
                    'after' => 1_700_000_000,
                ],
            ],
            FakeBackendClient::getCalls('pull'),
        );
    }

    public function test_dry_sync(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('dry_sync'));
        $this->migrateMainDb($logger);
        $command = new ImportCommand(
            $this->createRuntimeMapper($logger),
            $logger,
            new LogSuppressor([]),
            $this->createStub(iHttp::class),
        );

        self::assertSame(ImportCommand::SUCCESS, $this->makeTester($command)->execute(['--dry-run' => true]));
        $saved = Yaml::parseFile((string) Config::get('backends_file'));

        self::assertSame(1_700_000_000, ag($saved, 'dry_sync.import.lastSync'));
    }

    /**
     * @param array<string,bool> $option
     */
    #[DataProvider('scheduleProvider')]
    public function test_schedule(array $option, bool $expected): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('boundary'));
        $this->migrateMainDb($logger);
        $requests = 0;
        $firstCompleted = false;
        $secondSawFirstCompletion = false;
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(function () use (&$requests, &$firstCompleted, &$secondSawFirstCompletion): MockResponse {
                $requests++;
                if (2 === $requests) {
                    $secondSawFirstCompletion = $firstCompleted;
                }

                return new MockResponse('body');
            }),
            static function (iImport $mapper) use (&$firstCompleted): array {
                return [
                    new Request(
                        method: Method::GET,
                        url: 'https://example.test/first',
                        success: static function () use (&$firstCompleted): array {
                            $firstCompleted = true;

                            return [];
                        },
                    ),
                    new Request(
                        method: Method::GET,
                        url: 'https://example.test/second',
                    ),
                ];
            },
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute($option));
        self::assertSame($expected, $secondSawFirstCompletion);
    }

    /** @return iterable<string,array{array<string,bool>,bool}> */
    public static function scheduleProvider(): iterable
    {
        yield 'sync' => [['--sync-requests' => true], true];
        yield 'async' => [['--async-requests' => true], false];
    }

    public function test_backend_dry(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('backend_dry', [
            'options' => [Options::DRY_RUN => true],
        ]));
        $this->migrateMainDb($logger);
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(static fn(): MockResponse => new MockResponse('body')),
            static fn(iImport $mapper): array => [],
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute([]));
        $saved = Yaml::parseFile((string) Config::get('backends_file'));
        self::assertSame(1_700_000_000, ag($saved, 'backend_dry.import.lastSync'));
    }

    public function test_page_error(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('page_error'));
        $this->migrateMainDb($logger);
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(static fn(): MockResponse => new MockResponse('body')),
            static function (iImport $mapper): array {
                return [new Request(
                    method: Method::GET,
                    url: 'https://example.test/page',
                    success: static function (): array {
                        Message::add('page_error.has_errors', true);

                        return [];
                    },
                )];
            },
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute([]));
        $saved = Yaml::parseFile((string) Config::get('backends_file'));
        self::assertSame(1_700_000_000, ag($saved, 'page_error.import.lastSync'));
    }

    public function test_fatal_page(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('fatal_page'));
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $this->migrateMainDb($logger);
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(static function (): MockResponse {
                return new MockResponse('first page');
            }),
            function (iImport $mapper): array {
                return [new Request(
                    method: Method::GET,
                    url: 'https://example.test/first',
                    success: function () use ($mapper): array {
                        $mapper->add($this->pageState('fatal_page'));

                        return [new Request(
                            method: Method::GET,
                            url: 'https://example.test/second',
                            success: static function (): array {
                                throw new RuntimeException('second page failed');
                            },
                        )];
                    },
                )];
            },
        );

        $this->checkException(
            fn(): int => $tester->execute(['--sync-requests' => true]),
            'Expected the second page failure to abort the import.',
            RuntimeException::class,
            'second page failed',
        );

        self::assertSame(1, (int) Container::get(PDO::class)->query('SELECT COUNT(*) FROM state')->fetchColumn());
        unset($tester);
        gc_collect_cycles();
        $saved = Yaml::parseFile((string) Config::get('backends_file'));
        self::assertSame(1_700_000_000, ag($saved, 'fatal_page.import.lastSync'));
        self::assertTrue(self::hasRecordWith($handler, Level::Info, [
            'operation' => 'import.transaction.end',
            'outcome' => 'failed',
            'transactions' => 2,
            'failures' => 1,
        ]));
        $transactions = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => str_starts_with((string) ag($record->context, 'operation', ''), 'import.transaction.'),
        ));
        self::assertCount(1, $transactions);
        self::assertSame('import.transaction.end', $transactions[0]->context['operation']);
        self::assertGreaterThanOrEqual(0.0, $transactions[0]->context['duration']);
        self::assertGreaterThanOrEqual(0.0, $transactions[0]->context['max_duration']);
    }

    public function test_user_page(): void
    {
        $logger = $this->initFakeBackendApp(
            $this->fakeBackendConfig('main_page'),
            ['alice' => $this->fakeBackendConfig('alice_page')],
        );
        ensure_migration(get_user_db('alice'));
        $userDb = null;
        $inTransaction = false;
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(static fn(): MockResponse => new MockResponse('page')),
            function (iImport $mapper) use (&$userDb, &$inTransaction): array {
                return [new Request(
                    method: Method::GET,
                    url: 'https://example.test/user-page',
                    success: function () use ($mapper, &$userDb, &$inTransaction): array {
                        assert($userDb instanceof iDB);
                        $inTransaction = $userDb->getDBLayer()->getBackend()->inTransaction();
                        $mapper->add($this->pageState('alice_page'));

                        return [];
                    },
                )];
            },
            static function (UserContext $context) use (&$userDb): void {
                $userDb = $context->db;
            },
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute(['--user' => 'alice']));
        self::assertTrue($inTransaction);
        self::assertNotNull($userDb);
        self::assertSame(1, $userDb->getDBLayer()->getCount('state'));
        self::assertSame(0, (int) Container::get(PDO::class)->query('SELECT COUNT(*) FROM state')->fetchColumn());
    }

    public function test_user_transactions(): void
    {
        $logger = $this->initFakeBackendApp(
            $this->fakeBackendConfig('main_page'),
            ['alice' => $this->fakeBackendConfig('alice_page')],
        );
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        ensure_migration(get_user_db('alice'));
        $tester = $this->makeRequestCommand(
            $logger,
            new MockHttpClient(static fn(): MockResponse => new MockResponse('page')),
            function (iImport $mapper): array {
                return [new Request(
                    method: Method::GET,
                    url: 'https://example.test/user-page',
                    success: function () use ($mapper): array {
                        $mapper->add($this->pageState());

                        return [];
                    },
                )];
            },
        );

        self::assertSame(ImportCommand::SUCCESS, $tester->execute([]));
        $transactions = array_values(array_filter(
            $handler->getRecords(),
            static fn($record): bool => 'import.transaction.end' === ag($record->context, 'operation', ''),
        ));
        self::assertCount(2, $transactions);
        self::assertSame(['main', 'alice'], array_map(
            static fn($record): string => $record->context['identity']['user'],
            $transactions,
        ));
        foreach ($transactions as $transaction) {
            self::assertSame(1, $transaction->context['transactions']);
            self::assertSame(0, $transaction->context['failures']);
        }
    }

    private function makeTester(ImportCommand $command): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('trace', null, InputOption::VALUE_NONE));
        $application->addCommand($command);

        return new CommandTester($application->find(ImportCommand::ROUTE));
    }

    private function makeRequestCommand(Logger $logger, iHttp $http, Closure $pull, ?Closure $capture = null): CommandTester
    {
        $this->migrateMainDb($logger);
        $backend = $this->createStub(iClient::class);
        $backend->method('pull')->willReturnCallback($pull);
        $mapper = $this->createRuntimeMapper($logger);
        $command = new class($mapper, $logger, new LogSuppressor([]), $http, $backend, $capture) extends ImportCommand {
            public function __construct(
                iImport $mapper,
                Logger $logger,
                LogSuppressor $suppressor,
                iHttp $http,
                private readonly iClient $backend,
                private readonly ?Closure $capture,
            ) {
                parent::__construct($mapper, $logger, $suppressor, $http);
            }

            protected function makeBackend(array $backend, string $name, UserContext $userContext): iClient
            {
                $this->capture?->__invoke($userContext);

                return $this->backend;
            }
        };

        return $this->makeTester($command);
    }

    private function initFileDatabase(Logger $logger): void
    {
        $pdo = new PdoFactory()->createForFile((string) Config::get('database.file'));
        $db = new PDOAdapter($logger, new DBLayer($pdo));
        Container::getContainer()->addShared(PDO::class, $pdo, overwrite: true);
        Container::getContainer()->addShared(iDB::class, $db, overwrite: true);
        self::assertSame($pdo, Container::get(PDO::class));
    }

    private function pageState(string $backend = 'page'): iState
    {
        return StateEntity::fromArray([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 1,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_VIA => $backend,
            iState::COLUMN_TITLE => 'Page Movie',
            iState::COLUMN_GUIDS => [Guid::GUID_IMDB => 'tt-page'],
            iState::COLUMN_META_DATA => [
                $backend => [
                    iState::COLUMN_ID => 1,
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                ],
            ],
        ]);
    }

    private function insertState(PDO $pdo, string $backend = 'page'): void
    {
        $statement = $pdo->prepare('INSERT INTO state (type, updated, watched, via, title, guids, metadata) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            'movie',
            1,
            1,
            $backend,
            'Page Movie',
            '{"imdb":"tt-page"}',
            r('{"{backend}":{"id":1,"type":"movie"}}', ['backend' => $backend]),
        ]);
    }
}
