<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\API\State\BackendReport as BackendReportApi;
use App\Libs\BackendReportGenerator;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Prune\BackendReportPruner;
use App\Libs\TestCase;
use PDO;
use RuntimeException;
use Tests\Support\FakeBackendClient;
use Tests\Support\RequestResponseTrait;
use Tests\Support\StateCommandTestSupport;

final class BackendReportGeneratorTest extends TestCase
{
    use RequestResponseTrait;
    use StateCommandTestSupport;

    public function test_counts_local_state(): void
    {
        $logger = $this->initFakeBackendApp(
            mainBackends: [
                ...$this->fakeBackendConfig('alpha'),
                ...$this->fakeBackendConfig('empty'),
            ],
            userBackends: [
                'alice' => $this->fakeBackendConfig('alpha'),
            ],
        );
        $main = $this->makeUserContext('main', $logger);
        $alice = $this->makeUserContext('alice', $logger);
        FakeBackendClient::setLibraryResponse('main', 'alpha', [
            ['id' => 2, 'title' => 'Movies', 'contentType' => iState::TYPE_MOVIE],
            ['id' => 'shows', 'title' => 'TV Shows', 'contentType' => iState::TYPE_SHOW],
        ]);
        FakeBackendClient::setLibraryResponse('alice', 'alpha', new RuntimeException('Backend is offline.'));

        $movie = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $movie[iState::COLUMN_VIA] = 'alpha';
        $movie[iState::COLUMN_META_DATA] = [
            'alpha' => [
                iState::COLUMN_ID => 1,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_LIBRARY => '2',
            ],
            'removed' => [
                iState::COLUMN_ID => 2,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_META_LIBRARY => 'archive',
            ],
        ];
        $main->db->insert(new StateEntity($movie));

        $episode = require __DIR__ . '/../Fixtures/EpisodeEntity.php';
        $episode[iState::COLUMN_WATCHED] = 0;
        $episode[iState::COLUMN_VIA] = 'alpha';
        $episode[iState::COLUMN_PARENT] = [Guid::GUID_TVDB => '100'];
        $episode[iState::COLUMN_META_DATA] = [
            'alpha' => [
                iState::COLUMN_ID => 3,
                iState::COLUMN_TYPE => iState::TYPE_EPISODE,
                iState::COLUMN_WATCHED => 0,
                iState::COLUMN_META_LIBRARY => 'shows',
                iState::COLUMN_META_DATA_PROGRESS => 65_000,
            ],
        ];
        $main->db->insert(new StateEntity($episode));

        $episode[iState::COLUMN_ID] = null;
        $episode[iState::COLUMN_EPISODE] = 3;
        $episode[iState::COLUMN_GUIDS][Guid::GUID_TVDB] = 'episode-3';
        $episode[iState::COLUMN_META_DATA]['alpha'][iState::COLUMN_ID] = 4;
        $episode[iState::COLUMN_META_DATA]['alpha'][iState::COLUMN_META_DATA_PROGRESS] = 0;
        $main->db->insert(new StateEntity($episode));

        $aliceMovie = $movie;
        $aliceMovie[iState::COLUMN_ID] = null;
        $aliceMovie[iState::COLUMN_WATCHED] = 0;
        $aliceMovie[iState::COLUMN_META_DATA] = [
            'alpha' => [
                iState::COLUMN_ID => 5,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_WATCHED => 0,
            ],
        ];
        $alice->db->insert(new StateEntity($aliceMovie));

        $generator = Container::get(BackendReportGenerator::class);
        $mainSummary = $generator->generate($main)['summary'];
        $aliceSummary = $generator->generate($alice)['summary'];

        self::assertSame(3, $mainSummary['total']);
        self::assertSame(1, $mainSummary['watched']);
        self::assertSame(2, $mainSummary['unwatched']);
        self::assertSame(1, $mainSummary['in_progress']);
        self::assertSame(2, $mainSummary['types']['episode']['total']);
        self::assertSame(3, $mainSummary['backends']['alpha']['total']);
        self::assertSame('2', $mainSummary['backends']['alpha']['libraries'][0]['id']);
        self::assertSame('Movies', $mainSummary['backends']['alpha']['libraries'][0]['title']);
        self::assertSame(1, $mainSummary['backends']['alpha']['libraries'][0]['total']);
        self::assertSame(iState::TYPE_MOVIE, $mainSummary['backends']['alpha']['libraries'][0]['type']);
        self::assertSame('shows', $mainSummary['backends']['alpha']['libraries'][1]['id']);
        self::assertSame('TV Shows', $mainSummary['backends']['alpha']['libraries'][1]['title']);
        self::assertSame(2, $mainSummary['backends']['alpha']['libraries'][1]['total']);
        self::assertSame(iState::TYPE_SHOW, $mainSummary['backends']['alpha']['libraries'][1]['type']);
        self::assertSame(0, $mainSummary['backends']['empty']['total']);
        self::assertFalse($mainSummary['backends']['removed']['configured']);
        self::assertSame(3, $mainSummary['origins']['alpha']['total']);
        self::assertSame(1, $aliceSummary['total']);
        self::assertSame(
            BackendReportGenerator::UNKNOWN_LIBRARY,
            $aliceSummary['backends']['alpha']['libraries'][0]['id'],
        );
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
        self::assertCount(2, FakeBackendClient::getCalls('libraries'));
        self::assertSame(
            BackendReportGenerator::STATUS_COMPLETED,
            Container::get(PDO::class)->query("SELECT status FROM backend_reports WHERE identity = 'alice'")->fetchColumn(),
        );
        self::assertSame(
            ['alice', 'main'],
            Container::get(PDO::class)
                ->query('SELECT identity FROM backend_reports ORDER BY identity ASC')
                ->fetchAll(PDO::FETCH_COLUMN),
        );

        $api = Container::get(BackendReportApi::class);
        $mapper = Container::get(iImport::class);
        $request = $this->getRequest(headers: ['X-User' => 'alice']);
        $response = $api->index($request, $mapper, $logger);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, ag($payload, 'report.summary.total'));
        self::assertArrayNotHasKey('identities', ag($payload, 'report.summary'));

        $export = $api->export($request, 'json', $mapper, $logger);
        $exported = json_decode((string) $export->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('alice', $exported['identity']);
        self::assertSame(1, $exported['summary']['total']);
        self::assertArrayNotHasKey('identities', $exported['summary']);

        $csv = (string) $api->export($request, 'csv', $mapper, $logger)->getBody();
        self::assertStringStartsWith(
            'identity,backend,configured,library,type,total,watched,unwatched,in_progress',
            $csv,
        );
        self::assertStringContainsString('alice,alpha,yes,unknown,movie,1,0,1,0', $csv);
    }

    public function test_empty_maps(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        Container::get(BackendReportGenerator::class)->generate($context);

        $response = Container::get(BackendReportApi::class)->index(
            $this->getRequest(headers: ['X-User' => 'main']),
            Container::get(iImport::class),
            $logger,
        );
        $payload = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);

        self::assertIsObject($payload->report->summary->backends);
        self::assertIsObject($payload->report->summary->origins);
    }

    public function test_prune_statuses(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('alpha'));
        $this->makeUserContext('main', $logger);
        Config::save('backend_report.keep', 1);
        $pdo = Container::get(PDO::class);
        $stmt = $pdo->prepare(
            'INSERT INTO backend_reports (identity, status, generated_at, completed_at, version, backend_count, summary) VALUES (:identity, :status, :generated_at, :completed_at, 1, 0, :summary)',
        );

        foreach (['main', 'alice'] as $identity) {
            foreach ([
                BackendReportGenerator::STATUS_COMPLETED,
                BackendReportGenerator::STATUS_FAILED,
                BackendReportGenerator::STATUS_COMPLETED,
                BackendReportGenerator::STATUS_FAILED,
            ] as $index => $status) {
                $stmt->execute([
                    'status' => $status,
                    'identity' => $identity,
                    'generated_at' => $index + 1,
                    'completed_at' => $index + 1,
                    'summary' => '{}',
                ]);
            }
        }

        Container::get(BackendReportPruner::class)(true);

        self::assertSame(
            [
                'alice:completed' => 1,
                'alice:failed' => 1,
                'main:completed' => 1,
                'main:failed' => 1,
            ],
            $pdo
                ->query(
                    "SELECT identity || ':' || status, COUNT(*) AS count FROM backend_reports GROUP BY identity, status ORDER BY identity ASC, status ASC",
                )
                ->fetchAll(PDO::FETCH_KEY_PAIR),
        );
    }
}
