<?php

declare(strict_types=1);

namespace Tests\Libs\Playlists;

use App\Backends\Common\Cache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Playlists\PlaylistStore;
use App\Libs\Playlists\PlaylistSyncService;
use App\Libs\TestCase;
use App\Libs\Uri;
use App\Libs\UserContext;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class PlaylistSyncServiceTest extends TestCase
{
    public function test_partial_sync_no_promote(): void
    {
        $logger = new Logger('test', [new NullHandler()]);
        $userContext = $this->makeUserContext($logger);
        $store = new PlaylistStore($userContext->db->getDBLayer());
        Container::reinitialize();
        Container::add(iState::class, new StateEntity([]));

        $first = $this->insertLocalState($userContext->db, 'Shared Episode A', [
            'source' => [iState::COLUMN_ID => 'source-item-1'],
            'target' => [iState::COLUMN_ID => 'target-item-1'],
        ]);
        $second = $this->insertLocalState($userContext->db, 'Shared Episode B', [
            'source' => [iState::COLUMN_ID => 'source-item-2'],
        ]);

        $sourceState = new stdClass();
        $sourceState->playlists = [
            'source-playlist-1' => [
                'id' => 'source-playlist-1',
                'title' => 'Shared Episodes',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'remote_updated_at' => 100,
                'items' => [
                    ['Id' => 'source-item-1', 'Type' => 'Movie', 'Name' => 'Shared Episode A'],
                    ['Id' => 'source-item-2', 'Type' => 'Movie', 'Name' => 'Shared Episode B'],
                ],
            ],
        ];
        $sourceState->nextId = 1;
        $sourceState->nextUpdatedAt = 400;
        $sourceState->createCalls = 0;
        $sourceState->deleteCalls = 0;

        $targetState = new stdClass();
        $targetState->playlists = [];
        $targetState->nextId = 1;
        $targetState->nextUpdatedAt = 200;
        $targetState->createCalls = 0;
        $targetState->deleteCalls = 0;

        $clients = [
            'source' => $this->makeClientMock(
                backendName: 'source',
                state: $sourceState,
                entityIds: [
                    'source-item-1' => (int) $first->id,
                    'source-item-2' => (int) $second->id,
                ],
                titles: [
                    'source-item-1' => 'Shared Episode A',
                    'source-item-2' => 'Shared Episode B',
                    'target-item-1' => 'Shared Episode A',
                    'target-item-2' => 'Shared Episode B',
                ],
                userContext: $userContext,
                logger: $logger,
            ),
            'target' => $this->makeClientMock(
                backendName: 'target',
                state: $targetState,
                entityIds: [
                    'source-item-1' => (int) $first->id,
                    'source-item-2' => (int) $second->id,
                    'target-item-1' => (int) $first->id,
                    'target-item-2' => (int) $second->id,
                ],
                titles: [
                    'source-item-1' => 'Shared Episode A',
                    'source-item-2' => 'Shared Episode B',
                    'target-item-1' => 'Shared Episode A',
                    'target-item-2' => 'Shared Episode B',
                ],
                userContext: $userContext,
                logger: $logger,
            ),
        ];

        $service = new PlaylistSyncService($logger);
        $opts = [
            Options::DRY_RUN => false,
            'source_backends' => ['source', 'target'],
            'target_backends' => ['source', 'target'],
        ];

        $firstRun = $service->sync($userContext, $clients, $opts);

        self::assertSame(0, $sourceState->createCalls);
        self::assertSame(0, $sourceState->deleteCalls);
        self::assertSame(1, $targetState->createCalls);
        self::assertSame(0, $targetState->deleteCalls);
        self::assertSame(1, $firstRun['target']['playlists']);
        self::assertSame(1, $firstRun['target']['items']);
        self::assertSame(1, $firstRun['target']['added']);

        $targetRows = $store->getByBackend('target');
        self::assertCount(1, $targetRows);
        self::assertCount(1, $targetRows[0]['items']);
        self::assertTrue((bool) ag($targetRows[0], 'metadata.sync.partial', false));
        self::assertTrue((bool) ag($targetRows[0], 'metadata.sync.generated_by_sync', false));
        self::assertSame(2, ag($targetRows[0], 'metadata.sync.expected_item_count'));
        self::assertSame(1, ag($targetRows[0], 'metadata.sync.available_item_count'));
        self::assertSame(['Shared Episode B (2024)'], ag($targetRows[0], 'metadata.sync.missing_titles'));

        $secondRun = $service->sync($userContext, $clients, $opts);

        self::assertSame(0, $sourceState->createCalls);
        self::assertSame(0, $sourceState->deleteCalls);
        self::assertSame(1, $targetState->createCalls);
        self::assertSame(0, $targetState->deleteCalls);
        self::assertSame(1, $secondRun['target']['playlists']);
        self::assertSame(1, $secondRun['target']['items']);
        self::assertSame(0, $secondRun['target']['updated']);

        $second->metadata['target'] = [iState::COLUMN_ID => 'target-item-2'];
        $userContext->db->update($second);

        $thirdRun = $service->sync($userContext, $clients, $opts);

        self::assertSame(0, $sourceState->createCalls);
        self::assertSame(0, $sourceState->deleteCalls);
        self::assertSame(2, $targetState->createCalls);
        self::assertSame(1, $targetState->deleteCalls);
        self::assertSame(1, $thirdRun['target']['updated']);
        self::assertSame(2, $thirdRun['target']['items']);

        $targetRows = $store->getByBackend('target');
        self::assertCount(1, $targetRows);
        self::assertCount(2, $targetRows[0]['items']);
        self::assertFalse((bool) ag($targetRows[0], 'metadata.sync.partial', false));
        self::assertFalse((bool) ag($targetRows[0], 'metadata.sync.generated_by_sync', false));
        self::assertNull(ag($targetRows[0], 'metadata.sync.desired_content_hash'));
    }

    public function test_sync_skips_empty(): void
    {
        $logger = new Logger('test', [new NullHandler()]);
        $userContext = $this->makeUserContext($logger);
        $store = new PlaylistStore($userContext->db->getDBLayer());
        Container::reinitialize();
        Container::add(iState::class, new StateEntity([]));

        $first = $this->insertLocalState($userContext->db, 'Shared Episode A', [
            'source' => [iState::COLUMN_ID => 'source-item-1'],
        ]);
        $second = $this->insertLocalState($userContext->db, 'Shared Episode B', [
            'source' => [iState::COLUMN_ID => 'source-item-2'],
        ]);

        $sourceState = new stdClass();
        $sourceState->playlists = [
            'source-playlist-1' => [
                'id' => 'source-playlist-1',
                'title' => 'Shared Episodes',
                'type' => 'video',
                'editable' => true,
                'smart' => false,
                'public' => false,
                'remote_updated_at' => 100,
                'items' => [
                    ['Id' => 'source-item-1', 'Type' => 'Movie', 'Name' => 'Shared Episode A'],
                    ['Id' => 'source-item-2', 'Type' => 'Movie', 'Name' => 'Shared Episode B'],
                ],
            ],
        ];
        $sourceState->nextId = 1;
        $sourceState->nextUpdatedAt = 400;
        $sourceState->createCalls = 0;
        $sourceState->deleteCalls = 0;

        $targetState = new stdClass();
        $targetState->playlists = [];
        $targetState->nextId = 1;
        $targetState->nextUpdatedAt = 200;
        $targetState->createCalls = 0;
        $targetState->deleteCalls = 0;

        $clients = [
            'source' => $this->makeClientMock(
                backendName: 'source',
                state: $sourceState,
                entityIds: [
                    'source-item-1' => (int) $first->id,
                    'source-item-2' => (int) $second->id,
                ],
                titles: [
                    'source-item-1' => 'Shared Episode A',
                    'source-item-2' => 'Shared Episode B',
                ],
                userContext: $userContext,
                logger: $logger,
            ),
            'target' => $this->makeClientMock(
                backendName: 'target',
                state: $targetState,
                entityIds: [
                    'source-item-1' => (int) $first->id,
                    'source-item-2' => (int) $second->id,
                ],
                titles: [
                    'target-item-1' => 'Shared Episode A',
                    'target-item-2' => 'Shared Episode B',
                ],
                userContext: $userContext,
                logger: $logger,
            ),
        ];

        $service = new PlaylistSyncService($logger);
        $results = $service->sync($userContext, $clients, [
            Options::DRY_RUN => false,
            'source_backends' => ['source'],
            'target_backends' => ['target'],
        ]);

        self::assertSame(0, $targetState->createCalls);
        self::assertSame(0, $targetState->deleteCalls);
        self::assertSame(0, $results['target']['playlists']);
        self::assertSame(0, $results['target']['items']);
        self::assertSame([], $store->getByBackend('target'));
    }

    private function makeUserContext(Logger $logger): UserContext
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $db = $this->createDb($logger);

        return new UserContext(
            name: 'main',
            config: new ConfigFile(
                file: __DIR__ . '/../../Fixtures/test_servers.yaml',
                autoSave: false,
                autoCreate: false,
                autoBackup: false,
            ),
            mapper: new DirectMapper(logger: $logger, db: $db, cache: $cache),
            cache: $cache,
            db: $db,
        );
    }

    private function insertLocalState(PDOAdapter $db, string $title, array $metadata): StateEntity
    {
        $entity = new StateEntity([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 100,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_VIA => 'source',
            iState::COLUMN_TITLE => $title,
            iState::COLUMN_YEAR => 2024,
            iState::COLUMN_META_DATA => $metadata,
        ]);

        $db->insert($entity);

        return $entity;
    }

    /**
     * @param array<string,int> $entityIds
     * @param array<string,string> $titles
     */
    private function makeClientMock(
        string $backendName,
        stdClass $state,
        array $entityIds,
        array $titles,
        UserContext $userContext,
        Logger $logger,
    ): iClient {
        $cache = new Cache($logger, new Psr16Cache(new ArrayAdapter()));
        $context = new Context(
            clientName: ucfirst($backendName),
            backendName: $backendName,
            backendUrl: new Uri("http://{$backendName}.test"),
            cache: $cache,
            userContext: $userContext,
            logger: $logger,
        );

        $client = $this->createStub(iClient::class);
        $client->method('getContext')->willReturn($context);
        $client->method('getName')->willReturn($backendName);
        $client->method('getType')->willReturn('plex');
        $client
            ->method('getPlaylistsList')
            ->willReturnCallback(
                static function (array $opts = []) use ($state): array {
                    return array_values(array_map(
                        static fn(array $playlist): array => [
                            'id' => $playlist['id'],
                            'title' => $playlist['title'],
                            'type' => $playlist['type'],
                            'remote_updated_at' => $playlist['remote_updated_at'],
                        ],
                        $state->playlists,
                    ));
                },
            );
        $client
            ->method('getPlaylist')
            ->willReturnCallback(
                static fn(string|int $id, array $opts = []): array => $state->playlists[(string) $id] ?? [],
            );
        $client
            ->method('deletePlaylist')
            ->willReturnCallback(
                static function (string|int $id, array $opts = []) use ($state): array {
                    unset($state->playlists[(string) $id]);
                    $state->deleteCalls++;

                    return [];
                },
            );
        $client
            ->method('createPlaylist')
            ->willReturnCallback(
                static function (string $title, array $itemIds = [], array $opts = []) use ($backendName, $state, $titles): array {
                    $playlistId = sprintf('%s-playlist-%d', $backendName, $state->nextId++);
                    $state->playlists[$playlistId] = [
                        'id' => $playlistId,
                        'title' => $title,
                        'type' => 'video',
                        'editable' => true,
                        'smart' => false,
                        'public' => false,
                        'remote_updated_at' => $state->nextUpdatedAt,
                        'items' => array_values(array_map(
                            static fn(string $itemId): array => [
                                'Id' => $itemId,
                                'Type' => 'Movie',
                                'Name' => $titles[$itemId] ?? $itemId,
                            ],
                            $itemIds,
                        )),
                    ];
                    $state->nextUpdatedAt += 100;
                    $state->createCalls++;

                    return ['id' => $playlistId];
                },
            );
        $client
            ->method('toEntity')
            ->willReturnCallback(
                static fn(array $item, array $opts = []): StateEntity => StateEntity::fromArray([
                    iState::COLUMN_ID => $entityIds[(string) ag($item, ['ratingKey', 'Id'], '')] ?? null,
                ]),
            );

        return $client;
    }
}
