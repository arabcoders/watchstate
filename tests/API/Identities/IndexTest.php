<?php

declare(strict_types=1);

namespace Tests\API\Identities;

use App\API\Identities\Index;
use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;
use Tests\Support\RequestResponseTrait;

final class IndexTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
    }

    public function test_add_runs_migrations(): void
    {
        $handler = new Index(Container::get(iImport::class), new Logger('test'));

        $response = $handler->identity_add($this->getRequest(
            method: Method::POST,
            uri: '/v1/api/identities',
            post: ['identity' => 'alice'],
        ));

        self::assertSame(Status::CREATED->value, $response->getStatusCode());

        $configFile = self::$tmpPath . '/users/alice/servers.yaml';
        $dbFile = self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE;

        self::assertFileExists($configFile);
        self::assertFileExists($dbFile);

        $tables = (new PDO('sqlite:' . $dbFile))
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('events', $tables);
        self::assertContains('migration_version', $tables);
        self::assertContains('playlist_items', $tables);
        self::assertContains('playlists', $tables);
        self::assertContains('state', $tables);
    }
}
