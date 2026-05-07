<?php

declare(strict_types=1);

namespace Tests\Commands\History;

use App\API\History\Index as HistoryIndex;
use App\Commands\History\ListCommand;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Initializer;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class ListCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->seedTestServersConfig();
        $this->bootstrapMainDb();
        $this->registerInitializer();
    }

    public function test_list_table(): void
    {
        $main = $this->makeUserContext('main');
        $main->db->insert(new StateEntity($this->makeMovie(
            via: 'test_plex',
            title: 'The Movie',
            watched: 1,
            updated: 1_710_000_001,
            updatedAt: 1_710_000_101,
        )));

        $tester = $this->makeTester();
        $status = $tester->execute([]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString('The Movie', $tester->getDisplay());
        self::assertStringContainsString('test_plex', $tester->getDisplay());
    }

    public function test_list_json(): void
    {
        $main = $this->makeUserContext('main');
        $main->db->insert(new StateEntity($this->makeMovie(
            via: 'test_jellyfin',
            title: 'Json Movie',
            updated: 1_710_000_002,
            updatedAt: 1_710_000_102,
        )));

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--output' => 'json',
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('history', $payload);
        self::assertArrayHasKey('paging', $payload);
        self::assertSame('Json Movie', ag($payload, 'history.0.content_title'));
    }

    public function test_list_user(): void
    {
        $main = $this->makeUserContext('main');
        $alice = $this->makeUserContext('alice');

        $main->db->insert(new StateEntity($this->makeMovie(
            via: 'test_plex',
            title: 'Main Only',
            updated: 1_710_000_003,
            updatedAt: 1_710_000_103,
        )));
        $alice->db->insert(new StateEntity($this->makeMovie(
            via: 'test_plex',
            title: 'Alice Only',
            updated: 1_710_000_004,
            updatedAt: 1_710_000_104,
        )));

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--user' => 'alice',
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString('Alice Only', $tester->getDisplay());
        self::assertStringNotContainsString('Main Only', $tester->getDisplay());
    }

    public function test_list_query(): void
    {
        $main = $this->makeUserContext('main');

        $main->db->insert(new StateEntity($this->makeMovie(
            via: 'test_plex',
            title: 'Matched Movie',
            watched: 1,
            updated: 1_710_000_005,
            updatedAt: 1_710_000_105,
        )));
        $main->db->insert(new StateEntity($this->makeMovie(
            via: 'test_emby',
            title: 'Skipped Movie',
            watched: 0,
            updated: 1_710_000_006,
            updatedAt: 1_710_000_106,
        )));

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--played' => true,
            '--query' => ['via=test_plex'],
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString('Matched Movie', $tester->getDisplay());
        self::assertStringNotContainsString('Skipped Movie', $tester->getDisplay());
    }

    public function test_list_play_flags(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute([
            '--played' => true,
            '--unplayed' => true,
        ]);

        self::assertSame(ListCommand::FAILURE, $status);
        self::assertStringContainsString('cannot be used together', $tester->getDisplay());
    }

    public function test_list_empty_table(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute([
            '--title' => 'missing title',
        ]);

        self::assertSame(ListCommand::SUCCESS, $status);
        self::assertStringContainsString('No history items matched.', $tester->getDisplay());
    }

    public function test_list_error(): void
    {
        $tester = $this->makeTester();
        $status = $tester->execute([
            '--query' => ['metadata=1'],
            '--output' => 'json',
        ]);

        self::assertSame(ListCommand::FAILURE, $status);
        self::assertStringContainsString('When searching using JSON fields', $tester->getDisplay());
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->addCommand(new ListCommand());

        return new CommandTester($application->find(ListCommand::ROUTE));
    }

    private function registerInitializer(): void
    {
        Container::add(Initializer::class, [
            'shared' => true,
            'class' => function () {
                return new class($this) {
                    public function __construct(
                        private readonly ListCommandTest $test,
                    ) {}

                    public function http(iRequest $request): iResponse
                    {
                        return Container::get(HistoryIndex::class)->list($request);
                    }
                };
            },
        ]);
    }

    private function makeUserContext(string $name): UserContext
    {
        if ('main' !== $name) {
            $this->seedTestServersConfig($name);
            ensure_migration(get_user_db($name))->setOptions(['class' => new StateEntity([])]);
        }

        $logger = new Logger('test');
        $mapper = new DirectMapper(
            logger: $logger,
            db: Container::get(iDB::class),
            cache: Container::get(iCache::class),
        );

        $userContext = get_user_context($name, $mapper, $logger);
        $userContext->db->setOptions(['class' => new StateEntity([])]);

        return $userContext;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeMovie(
        string $via,
        string $title,
        int $watched = 0,
        int $updated = 1_710_000_000,
        int $updatedAt = 1_710_000_000,
    ): array {
        $identifier = $this->makeIdentifier($title);

        return [
            'type' => 'movie',
            'updated' => $updated,
            'watched' => $watched,
            'via' => $via,
            'title' => $title,
            'year' => 2024,
            'parent' => [],
            'guids' => [
                'guid_' . $via => 'movie-' . $identifier,
            ],
            'metadata' => [
                $via => [
                    'id' => $identifier,
                    'type' => 'movie',
                    'path' => '/media/' . $identifier . '.mkv',
                    'extra' => [
                        'title' => $title,
                        'overview' => 'Overview for ' . $title,
                        'genres' => ['drama'],
                    ],
                ],
            ],
            'extra' => [
                $via => [
                    'title' => $title,
                ],
            ],
            'created_at' => $updatedAt - 50,
            'updated_at' => $updatedAt,
        ];
    }

    private function makeIdentifier(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '-', $normalized);

        return trim($normalized, '-');
    }

    private function bootstrapMainDb(): void
    {
        $migrations = new PackageMigrationFactory();
        $pdo = Container::get(\PDO::class);

        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }

        Container::get(iDB::class)->setOptions(['class' => new StateEntity([])]);
    }
}
