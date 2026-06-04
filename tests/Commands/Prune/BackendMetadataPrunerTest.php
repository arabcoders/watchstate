<?php

declare(strict_types=1);

namespace Tests\Commands\Prune;

use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Prune\BackendMetadataPruner;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;

final class BackendMetadataPrunerTest extends TestCase
{
    private iDB $db;
    private iImport $mapper;
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->logger = new Logger('test');
        $this->writeBackends([
            'kept' => [
                'type' => 'plex',
                'url' => 'http://localhost:32400',
                'token' => 'token',
            ],
        ]);

        $this->db = Container::get(iDB::class);
        $pdo = $this->db->getDBLayer()->getBackend();
        $migrations = new PackageMigrationFactory();
        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }

        $this->mapper = $this->createStub(iImport::class);
        $this->mapper->method('withUserContext')->willReturnSelf();
    }

    public function test_exec(): void
    {
        $keptMixed = $this->insertState(
            'kept',
            [
                'deleted' => [
                    'via' => 'other',
                ],
                'kept' => [
                    iState::COLUMN_ID => '1',
                ],
            ],
            [
                'deleted' => [
                    iState::COLUMN_EXTRA_DATE => 20,
                ],
                'kept' => [
                    iState::COLUMN_EXTRA_DATE => 10,
                ],
            ],
        );
        $this->insertState(
            'deleted',
            [
                'deleted' => [
                    iState::COLUMN_ID => '2',
                ],
            ],
            [
                'deleted' => [
                    iState::COLUMN_EXTRA_DATE => 30,
                ],
            ],
        );
        $keptOnly = $this->insertState('kept', [
            'kept' => [
                iState::COLUMN_ID => '3',
            ],
        ]);

        new BackendMetadataPruner($this->logger, $this->mapper)->__invoke(true);

        $rows = $this->fetchRows();

        self::assertSame([$keptMixed, $keptOnly], array_column($rows, 'id'));
        self::assertSame(['kept'], array_keys($rows[0][iState::COLUMN_META_DATA]));
        self::assertSame(['kept'], array_keys($rows[0][iState::COLUMN_EXTRA]));
        self::assertSame(['kept'], array_keys($rows[1][iState::COLUMN_META_DATA]));
    }

    public function test_dry(): void
    {
        $this->insertState('kept', [
            'deleted' => [
                'via' => 'other',
            ],
            'kept' => [
                iState::COLUMN_ID => '1',
            ],
        ]);

        new BackendMetadataPruner($this->logger, $this->mapper)->__invoke(false);

        $rows = $this->fetchRows();

        self::assertSame(['deleted', 'kept'], array_keys($rows[0][iState::COLUMN_META_DATA]));
    }

    public function test_discover(): void
    {
        Config::save('prune.paths', [ROOT_PATH . '/src/Libs/Prune']);
        Config::save('prune.cache.time', 0);

        $pruners = discover_pruners();

        self::assertArrayHasKey('backend_metadata', $pruners);
        self::assertSame('Remove metadata for deleted backends.', $pruners['backend_metadata']['desc']);
    }

    private function insertState(string $via, array $metadata, array $extra = []): int
    {
        $this->db->getDBLayer()->query(
            'INSERT INTO state
                (type, updated, watched, via, title, year, season, episode, parent, guids, metadata, extra, created_at, updated_at)
             VALUES
                (:type, :updated, :watched, :via, :title, :year, :season, :episode, :parent, :guids, :metadata, :extra, :created_at, :updated_at)',
            [
                'type' => iState::TYPE_MOVIE,
                'updated' => 1,
                'watched' => 0,
                'via' => $via,
                'title' => 'Movie',
                'year' => 2024,
                'season' => null,
                'episode' => null,
                'parent' => null,
                'guids' => '{}',
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'extra' => json_encode($extra, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'created_at' => 1,
                'updated_at' => 1,
            ],
        );

        return (int) $this->db->getDBLayer()->lastInsertId();
    }

    /**
     * @return Array<array{id:int,via:string,metadata:array,extra:array}>
     */
    private function fetchRows(): array
    {
        $rows = $this->db
            ->getDBLayer()
            ->query('SELECT id, via, metadata, extra FROM state ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row[iState::COLUMN_META_DATA] = json_decode(
                (string) $row[iState::COLUMN_META_DATA],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $row[iState::COLUMN_EXTRA] = json_decode(
                (string) $row[iState::COLUMN_EXTRA],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        return $rows;
    }

    private function writeBackends(array $backends): void
    {
        ConfigFile::open((string) Config::get('backends_file'), 'yaml', autoCreate: true, autoBackup: false)
            ->replaceAll($backends)
            ->persist(true);
    }
}
