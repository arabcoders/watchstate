<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\QueryCommand;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Monolog\Logger;
use PDO;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class QueryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->seedTestServersConfig();
    }

    public function test_select_default_db(): void
    {
        $main = $this->makeUserContext('main');
        $other = $this->makeUserContext('alice');

        $this->seedTable($main, [[
            'id' => 1,
            'name' => 'main-row',
        ]]);
        $this->seedTable($other, [[
            'id' => 1,
            'name' => 'alice-row',
        ]]);

        $tester = $this->makeTester([
            'main' => $main,
            'alice' => $other,
        ]);
        $status = $tester->execute([
            '--output' => 'json',
            'sql' => 'SELECT id, name FROM sample ORDER BY id ASC',
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);
        self::assertSame(
            [
                [
                    'id' => 1,
                    'name' => 'main-row',
                ],
            ],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_write_affected_rows(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [[
            'id' => 1,
            'name' => 'before',
        ]]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            'sql' => "UPDATE sample SET name = 'after' WHERE id = 1",
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);
        self::assertStringContainsString('Affected 1 row(s).', $tester->getDisplay());

        $result = $main->db->getDBLayer()->query('SELECT name FROM sample WHERE id = 1')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(
            [
                [
                    'name' => 'after',
                ],
            ],
            $result,
        );
    }

    public function test_user_routes_db(): void
    {
        $main = $this->makeUserContext('main');
        $other = $this->makeUserContext('alice');

        $this->seedTable($main, [[
            'id' => 1,
            'name' => 'main-row',
        ]]);
        $this->seedTable($other, [[
            'id' => 1,
            'name' => 'alice-row',
        ]]);

        $tester = $this->makeTester([
            'main' => $main,
            'alice' => $other,
        ]);
        $status = $tester->execute([
            '--user' => 'alice',
            '--output' => 'json',
            'sql' => 'SELECT id, name FROM sample ORDER BY id ASC',
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);
        self::assertSame(
            [
                [
                    'id' => 1,
                    'name' => 'alice-row',
                ],
            ],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_named_params_bind(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [
            [
                'id' => 1,
                'name' => 'alpha',
            ],
            [
                'id' => 2,
                'name' => 'beta',
            ],
        ]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--output' => 'json',
            '--param' => ['name=beta'],
            'sql' => 'SELECT id, name FROM sample WHERE name = :name',
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);
        self::assertSame(
            [
                [
                    'id' => 2,
                    'name' => 'beta',
                ],
            ],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_named_params_kv(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [
            [
                'id' => 1,
                'name' => 'alpha',
            ],
        ]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--param' => ['beta'],
            'sql' => 'SELECT id, name FROM sample WHERE name = :name',
        ]);

        self::assertSame(QueryCommand::FAILURE, $status);
        self::assertStringContainsString("Invalid named SQL parameter 'beta'. Expected key=value.", $tester->getDisplay());
    }

    public function test_positional_params_bind(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [
            [
                'id' => 1,
                'name' => 'alpha',
            ],
            [
                'id' => 2,
                'name' => 'beta',
            ],
        ]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--output' => 'json',
            '--param' => ['beta'],
            'sql' => 'SELECT id, name FROM sample WHERE name = ?',
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);
        self::assertSame(
            [
                [
                    'id' => 2,
                    'name' => 'beta',
                ],
            ],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_positional_equals_literal(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [
            [
                'id' => 1,
                'name' => 'alpha=beta',
            ],
            [
                'id' => 2,
                'name' => 'beta',
            ],
        ]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--output' => 'json',
            '--param' => ['alpha=beta'],
            'sql' => 'SELECT id, name FROM sample WHERE name = ?',
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);
        self::assertSame(
            [
                [
                    'id' => 1,
                    'name' => 'alpha=beta',
                ],
            ],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_mixed_placeholders_rejected(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [
            [
                'id' => 1,
                'name' => 'alpha',
            ],
        ]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--param' => ['name=alpha', '1'],
            'sql' => 'SELECT id, name FROM sample WHERE name = :name OR id = ?',
        ]);

        self::assertSame(QueryCommand::FAILURE, $status);
        self::assertStringContainsString('Mixed named and positional SQL placeholders are not supported.', $tester->getDisplay());
    }

    public function test_scalar_params_coerced(): void
    {
        $main = $this->makeUserContext('main');
        $db = $main->db->getDBLayer();
        $db->exec('CREATE TABLE flags (id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL, score REAL NOT NULL, note TEXT NULL)');

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--param' => ['1', 'true', '2.5', 'null'],
            'sql' => 'INSERT INTO flags (id, enabled, score, note) VALUES (?, ?, ?, ?)',
        ]);

        self::assertSame(QueryCommand::SUCCESS, $status);

        $result = $db->query('SELECT id, enabled, score, note FROM flags')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(
            [
                [
                    'id' => 1,
                    'enabled' => 1,
                    'score' => 2.5,
                    'note' => null,
                ],
            ],
            $result,
        );
    }

    public function test_unknown_user(): void
    {
        $main = $this->makeUserContext('main');
        $this->seedTable($main, [[
            'id' => 1,
            'name' => 'main-row',
        ]]);

        $tester = $this->makeTester([
            'main' => $main,
        ]);
        $status = $tester->execute([
            '--user' => 'ghost',
            'sql' => 'SELECT id, name FROM sample ORDER BY id ASC',
        ]);

        self::assertSame(QueryCommand::FAILURE, $status);
        self::assertStringContainsString("User 'ghost' not found.", $tester->getDisplay());
    }

    /**
     * @param array<string,UserContext> $contexts
     */
    private function makeTester(array $contexts): CommandTester
    {
        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'table'));
        $application->getDefinition()->addOption(new InputOption('param', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED));
        $bootstrapContext = $contexts['main'] ?? array_values($contexts)[0];
        assert($bootstrapContext instanceof UserContext, 'Expected bootstrap query user context.');

        $application->addCommand(new QueryCommand($bootstrapContext->mapper, new Logger('test')));

        return new CommandTester($application->find(QueryCommand::ROUTE));
    }

    private function makeUserContext(string $name): UserContext
    {
        if ('main' !== $name) {
            $this->seedTestServersConfig($name);
        }

        $logger = new Logger('test');
        $mapper = new DirectMapper(
            logger: $logger,
            db: Container::get(iDB::class),
            cache: Container::get(iCache::class),
        );

        return get_user_context($name, $mapper, $logger);
    }

    /**
     * @param array<int,array{id:int,name:string}> $rows
     */
    private function seedTable(UserContext $userContext, array $rows): void
    {
        $db = $userContext->db->getDBLayer();
        $db->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $stmt = $db->prepare('INSERT INTO sample (id, name) VALUES (:id, :name)');

        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }
}
