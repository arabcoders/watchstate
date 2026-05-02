<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PdoFactory;
use App\Libs\TestCase;
use PDO;

final class PerUserDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
    }

    public function test_main_bootstrap(): void
    {
        $db = ensure_migration((string) Config::get('database.file'));
        $tables = $db->getDBLayer()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('events', $tables);
        self::assertContains('migration_version', $tables);
        self::assertContains('playlist_items', $tables);
        self::assertContains('playlists', $tables);
        self::assertContains('state', $tables);
    }

    public function test_per_user_db(): void
    {
        ensure_migration(get_user_db('alice'));

        $db = per_user_db('alice');
        $dbLayer = $db->getDBLayer();
        $tables = $dbLayer->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('events', $tables);
        self::assertContains('migration_version', $tables);
        self::assertContains('playlist_items', $tables);
        self::assertContains('playlists', $tables);
        self::assertContains('state', $tables);

        $dbLayer->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $statement = $dbLayer->prepare('INSERT INTO sample (id, name) VALUES (:id, :name)');
        $statement->execute([
            'id' => 1,
            'name' => 'alice',
        ]);

        self::assertSame([
            [
                'id' => 1,
                'name' => 'alice',
            ],
        ], $dbLayer->query('SELECT id, name FROM sample')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame('sqlite', $dbLayer->getDriver());
    }

    public function test_per_user_db_does_not_bootstrap_main_db(): void
    {
        self::assertFalse(file_exists(self::$tmpPath . '/db/' . PdoFactory::DB_FILE));

        ensure_migration(get_user_db('alice'));
        $db = per_user_db('alice');
        $tables = $db->getDBLayer()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertFalse(file_exists(self::$tmpPath . '/db/' . PdoFactory::DB_FILE));
        self::assertContains('migration_version', $tables);
        self::assertContains('state', $tables);
    }

    public function test_per_user_db_does_not_resolve_main_db_service(): void
    {
        Container::add(iDB::class, [
            'class' => static function (): iDB {
                throw new \RuntimeException('main db service should not be resolved');
            },
        ]);

        ensure_migration(get_user_db('alice'));

        $db = per_user_db('alice');

        self::assertSame('sqlite', $db->getDBLayer()->getDriver());
        self::assertFalse(file_exists(self::$tmpPath . '/db/' . PdoFactory::DB_FILE));
    }
}
