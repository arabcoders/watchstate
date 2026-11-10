<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\API\State\UserStatistics;
use App\Commands\State\UserStatisticsCommand;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\TestCase;
use App\Libs\UserContext;
use App\Libs\UserStatisticsGenerator;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;
use Psr\SimpleCache\CacheInterface as iCache;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\FakeBackendClient;
use Tests\Support\RequestResponseTrait;
use Tests\Support\StateCommandTestSupport;

final class UserStatisticsGeneratorTest extends TestCase
{
    use RequestResponseTrait;
    use StateCommandTestSupport;

    public function test_counts(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('alpha'));
        $context = $this->makeUserContext('main', $logger);
        $this->record($context, iState::TYPE_MOVIE, 1, metadata: [
            'alpha' => ['played_at' => 200, 'extra' => ['genres' => ['Drama', 'drama']]],
            'beta' => ['played_at' => 100, 'extra' => ['genres' => ['Drama', 'Comedy']]],
        ]);
        $this->record($context, iState::TYPE_MOVIE, 0, metadata: ['alpha' => ['played_at' => 999]]);
        $this->record(
            $context,
            iState::TYPE_EPISODE,
            1,
            [Guid::GUID_TVDB => '10'],
            [
                'alpha' => ['show' => '100', 'played_at' => 100, 'extra' => ['genres' => ['Drama']]],
            ],
        );
        $this->record(
            $context,
            iState::TYPE_EPISODE,
            0,
            [Guid::GUID_TMDB => '20'],
            [
                'beta' => ['show' => '200', 'progress' => 65_000, 'extra' => ['genres' => ['Sci-fi']]],
            ],
        );
        $this->record($context, iState::TYPE_EPISODE, 0, [Guid::GUID_TVDB => '10', Guid::GUID_TMDB => '20']);
        $this->record($context, iState::TYPE_EPISODE, 1, metadata: [
            'alpha' => ['show' => '200', 'extra' => ['genres' => ['unknown', '??', '']]],
        ]);
        $this->record($context, iState::TYPE_EPISODE, 0, metadata: [
            'alpha' => ['show' => '300', 'progress' => 65_000],
        ]);
        $this->record($context, iState::TYPE_EPISODE, 1, metadata: ['alpha' => ['show' => '??']]);

        $report = Container::get(UserStatisticsGenerator::class)->generate($context);

        self::assertSame(['total' => 2, 'watched' => 1, 'in_progress' => 0], $report['summary']['movies']);
        self::assertSame(['total' => 6, 'watched' => 3, 'in_progress' => 2], $report['summary']['episodes']);
        self::assertSame(['total' => 3, 'started' => 3, 'in_progress' => 2, 'fully_watched' => 1], $report['summary']['shows']);
        self::assertArrayNotHasKey('missing', $report['summary']);
        self::assertCount(3, $report['shows']);
        self::assertSame(3, $report['shows'][0]['total']);
        self::assertSame(1, $report['shows'][0]['watched']);
        self::assertSame(1, $report['shows'][0]['in_progress']);
        self::assertSame(100, $report['shows'][0]['last_played_at']);
        self::assertSame('fully_watched', $report['shows'][1]['status']);
        self::assertNull($report['shows'][1]['last_played_at']);
        self::assertSame('in_progress', $report['shows'][2]['status']);
        self::assertSame(
            [
                ['name' => 'Drama', 'movies' => 1, 'shows' => 1, 'total' => 2],
                ['name' => 'Comedy', 'movies' => 1, 'shows' => 0, 'total' => 1],
                ['name' => 'Sci-fi', 'movies' => 0, 'shows' => 1, 'total' => 1],
            ],
            $report['genres'],
        );
        self::assertSame([200, 100], array_column($report['recent'], 'played_at'));
        self::assertSame(['Same title', 'Same title'], array_column($report['recent'], 'title'));
        foreach (['movies', 'episodes'] as $type) {
            self::assertCount($report['summary'][$type]['in_progress'], $report['in_progress'][$type]);
        }
        self::assertSame([], FakeBackendClient::getCalls('metadata'));
        self::assertSame([], FakeBackendClient::getCalls('libraries'));
    }

    public function test_aliases(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $this->record($context, iState::TYPE_EPISODE, 1, metadata: ['alpha' => ['show' => '10']]);
        $this->record($context, iState::TYPE_EPISODE, 0, metadata: ['beta' => ['show' => '20']]);
        $this->record($context, iState::TYPE_EPISODE, 0, metadata: [
            'alpha' => ['show' => '10', 'parent' => [Guid::GUID_TVDB => '100']],
            'beta' => ['show' => '20', 'parent' => [Guid::GUID_TMDB => '200']],
        ]);
        $this->record($context, iState::TYPE_EPISODE, 0, [Guid::GUID_TMDB => '200']);
        $this->record($context, iState::TYPE_EPISODE, 0, metadata: ['beta' => ['show' => '10']]);

        $report = Container::get(UserStatisticsGenerator::class)->generate($context);

        self::assertCount(2, $report['shows']);
        self::assertSame([4, 1], array_column($report['shows'], 'total'));
    }

    public function test_scope(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('alpha'), ['alice' => $this->fakeBackendConfig('alpha')]);
        $main = $this->makeUserContext('main', $logger);
        $alice = $this->makeUserContext('alice', $logger);
        $this->record($main, iState::TYPE_MOVIE, 1);
        $this->record($main, iState::TYPE_MOVIE, 0, metadata: ['alpha' => ['progress' => 70_000]], title: 'Main movie');
        $this->record(
            $alice,
            iState::TYPE_EPISODE,
            0,
            metadata: ['alpha' => ['show' => '10', 'progress' => 70_000]],
            title: 'Alice episode',
            extra: ['alpha' => ['received_at' => 1000]],
        );
        $generator = Container::get(UserStatisticsGenerator::class);
        $generator->generate($main);
        $generator->generate($alice);
        $api = Container::get(UserStatistics::class);
        $mapper = Container::get(iImport::class);
        $request = $this->getRequest(headers: ['X-User' => 'alice']);
        $payload = json_decode((string) $api->index($request, $mapper, $logger)->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['report']['summary']['movies']['total']);
        self::assertSame(1, $payload['report']['summary']['episodes']['total']);
        self::assertSame([], $payload['report']['in_progress']['movies']);
        self::assertSame(['Alice episode'], array_column($payload['report']['in_progress']['episodes'], 'title'));
        self::assertSame(70_000, $payload['report']['in_progress']['episodes'][0]['progress']);
        self::assertSame(['Main movie'], array_column($generator->latest($main)['in_progress']['movies'], 'title'));
        self::assertFalse($payload['stale']);
        self::assertFalse($payload['queued']);
        self::assertNull($payload['queued_event']);
        self::assertSame($generator->latest($alice), $generator->latest($this->makeUserContext('alice', $logger)));

        $export = $api->export($request, 'json', $mapper, $logger);
        $public = json_decode((string) $export->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $public['summary']['movies']['total']);
        self::assertSame([], $public['in_progress']['movies']);
        self::assertSame(['Alice episode'], array_column($public['in_progress']['episodes'], 'title'));
        self::assertSame(70_000, $public['in_progress']['episodes'][0]['progress']);
        self::assertSame(
            'attachment; filename="user-statistics-' . $public['generated_at'] . '.json"',
            $export->getHeaderLine('Content-Disposition'),
        );
    }

    public function test_stale(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $generator = Container::get(UserStatisticsGenerator::class);
        self::assertNull($generator->latest($context));
        self::assertTrue($generator->isStale($context));
        $generator->generate($context);
        self::assertFalse($generator->isStale($context));
        $this->record($context, iState::TYPE_MOVIE, 1);
        self::assertTrue($generator->isStale($context));
        $generator->generate($context);
        self::assertFalse($generator->isStale($context));
        $context->db->getDBLayer()->query('UPDATE state SET metadata = :metadata, updated_at = :updated', [
            'metadata' => json_encode(['alpha' => ['extra' => ['genres' => ['New']]]], JSON_THROW_ON_ERROR),
            'updated' => time() + 1,
        ]);
        self::assertTrue($generator->isStale($context));
        $generator->generate($context);
        self::assertFalse($generator->isStale($context));
        $snapshot = $context->cache->get(UserStatisticsGenerator::CACHE_KEY);
        $snapshot['report']['generated_at'] -= 86_400;
        $context->cache->set(UserStatisticsGenerator::CACHE_KEY, $snapshot);
        self::assertTrue($generator->isStale($context));
        $context->db->getDBLayer()->query('DELETE FROM state');
        self::assertTrue($generator->isStale($context));
    }

    public function test_cache_shape(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $generator = Container::get(UserStatisticsGenerator::class);
        $report = $generator->generate($context);
        $snapshot = $context->cache->get('user_statistics_v1');

        self::assertSame(1, UserStatisticsGenerator::VERSION);
        self::assertSame('user_statistics_v1', UserStatisticsGenerator::CACHE_KEY);
        self::assertSame(1, $report['version']);
        self::assertSame($report, $snapshot['report']);

        foreach ([null, [], ['movies' => []], ['episodes' => []], ['movies' => [], 'episodes' => 'invalid']] as $inProgress) {
            $incomplete = $snapshot;
            $incomplete['report']['in_progress'] = $inProgress;
            $context->cache->set('user_statistics_v1', $incomplete);

            self::assertNull($generator->latest($context));
            self::assertTrue($generator->isStale($context));
        }

        $incomplete = $snapshot;
        unset($incomplete['report']['in_progress']);
        $context->cache->set('user_statistics_v1', $incomplete);
        self::assertNull($generator->latest($context));
        self::assertTrue($generator->isStale($context));

        $refreshed = $generator->generate($context);
        self::assertSame(['movies' => [], 'episodes' => []], $refreshed['in_progress']);
        self::assertSame($refreshed, $generator->latest($context));
        self::assertFalse($generator->isStale($context));

        foreach ([iState::TYPE_MOVIE, iState::TYPE_EPISODE] as $type) {
            $this->record($context, $type, 0, metadata: ['alpha' => ['progress' => 70_000]]);
        }
        $generator->generate($context);
        $snapshot = $context->cache->get('user_statistics_v1');

        foreach (['movies', 'episodes'] as $type) {
            foreach ([null, '0', 0.0] as $progress) {
                $incomplete = $snapshot;
                if (null === $progress) {
                    unset($incomplete['report']['in_progress'][$type][0]['progress']);
                } else {
                    $incomplete['report']['in_progress'][$type][0]['progress'] = $progress;
                }
                $context->cache->set('user_statistics_v1', $incomplete);

                self::assertNull($generator->latest($context));
                self::assertTrue($generator->isStale($context));
            }
        }

        self::assertSame(1, $generator->generate($context)['version']);
        self::assertFalse($generator->isStale($context));
    }

    public function test_refresh_failure(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $generator = Container::get(UserStatisticsGenerator::class);
        $this->record($context, iState::TYPE_MOVIE, 0, metadata: ['alpha' => ['progress' => 70_000]]);
        $report = $generator->generate($context);
        $db = $this->createStub(iDB::class);
        $db->method('getDBLayer')->willThrowException(new RuntimeException('Read failed.'));
        $failed = new UserContext($context->name, $context->config, $context->mapper, $context->cache, $db);

        try {
            $generator->generate($failed);
            self::fail('Expected refresh failure.');
        } catch (RuntimeException $e) {
            self::assertSame('Read failed.', $e->getMessage());
        }

        self::assertSame($report, $generator->latest($context));
        $cache = $this->createMock(iCache::class);
        $cache->method('get')->willReturn($context->cache->get(UserStatisticsGenerator::CACHE_KEY));
        $cache->expects(self::once())->method('set')->with(UserStatisticsGenerator::CACHE_KEY, self::isArray(), null)->willReturn(false);
        $failed = new UserContext($context->name, $context->config, $context->mapper, $cache, $context->db);

        try {
            $generator->generate($failed);
            self::fail('Expected cache failure.');
        } catch (RuntimeException $e) {
            self::assertSame('Failed to persist user statistics snapshot.', $e->getMessage());
        }

        self::assertSame($report, $generator->latest($failed));
    }

    public function test_scan_changes(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $this->record($context, iState::TYPE_MOVIE, 1);
        $calls = 0;
        $db = $this->createMock(iDB::class);
        $db
            ->expects(self::exactly(2))
            ->method('getDBLayer')
            ->willReturnCallback(function () use ($context, &$calls) {
                $calls++;
                if (2 === $calls) {
                    $this->record($context, iState::TYPE_MOVIE, 0);
                }

                return $context->db->getDBLayer();
            });
        $changing = new UserContext($context->name, $context->config, $context->mapper, $context->cache, $db);
        $generator = Container::get(UserStatisticsGenerator::class);
        $report = $generator->generate($changing);

        self::assertSame(2, $report['summary']['movies']['total']);
        self::assertTrue($generator->isStale($context));
    }

    public function test_progress(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        Config::save('progress.minimum', 60_000);
        Config::save('progress.threshold', 0);
        $this->record(
            $context,
            iState::TYPE_EPISODE,
            0,
            [Guid::GUID_TVDB => '10'],
            [
                'alpha' => ['progress' => 60_000, 'played_at' => 100],
            ],
        );
        $this->record(
            $context,
            iState::TYPE_EPISODE,
            0,
            [Guid::GUID_TVDB => '20'],
            [
                'alpha' => ['progress' => 60_001],
            ],
        );
        $this->record($context, iState::TYPE_MOVIE, 1, metadata: ['alpha' => ['progress' => 65_000]]);
        $this->record($context, iState::TYPE_MOVIE, 0, metadata: ['alpha' => ['progress' => 59_999]]);
        $this->record($context, iState::TYPE_MOVIE, 0, metadata: ['alpha' => ['progress' => 60_000]]);
        $backendWatched = $this->record($context, iState::TYPE_MOVIE, 0);
        $context->db->getDBLayer()->query('UPDATE state SET metadata = :metadata WHERE id = :id', [
            'metadata' => json_encode(['alpha' => ['progress' => 70_000, 'watched' => 1]], JSON_THROW_ON_ERROR),
            'id' => $backendWatched,
        ]);
        $this->record($context, iState::TYPE_EPISODE, 1, metadata: ['alpha' => ['progress' => 70_000]]);
        $report = Container::get(UserStatisticsGenerator::class)->generate($context);

        self::assertSame(['not_started', 'in_progress'], array_column($report['shows'], 'status'));
        self::assertSame(1, $report['summary']['shows']['started']);
        self::assertSame(0, $report['summary']['movies']['in_progress']);
        self::assertSame([], $report['in_progress']['movies']);
        self::assertCount(1, $report['in_progress']['episodes']);
        self::assertCount($report['summary']['episodes']['in_progress'], $report['in_progress']['episodes']);
        self::assertSame([], $report['recent']);
        self::assertNull($report['shows'][0]['last_played_at']);
        self::assertNull($report['shows'][0]['year']);

        Config::save('progress.threshold', 1);
        $report = Container::get(UserStatisticsGenerator::class)->generate($context);
        self::assertSame([$backendWatched], array_column($report['in_progress']['movies'], 'history_id'));
        self::assertCount($report['summary']['movies']['in_progress'], $report['in_progress']['movies']);
        self::assertCount(1, $report['in_progress']['episodes']);
    }

    public function test_progress_items(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $metadata = ['alpha' => ['progress' => 70_000], 'beta' => ['progress' => 80_000]];
        for ($index = 24; $index >= 1; $index--) {
            $this->record($context, iState::TYPE_MOVIE, 0, metadata: $metadata, title: 'Movie ' . $index);
        }
        $last = $this->record($context, iState::TYPE_EPISODE, 0, metadata: $metadata, title: 'Show 10', season: 2, episode: 1);
        $second = $this->record($context, iState::TYPE_EPISODE, 0, metadata: $metadata, title: 'Show 2', season: 2, episode: 1);
        $first = $this->record($context, iState::TYPE_EPISODE, 0, metadata: $metadata, title: 'Show 2', season: 0, episode: 7, year: null);
        $duplicate = $this->record($context, iState::TYPE_EPISODE, 0, metadata: $metadata, title: 'Show 2', season: 0, episode: 7);
        $third = $this->record($context, iState::TYPE_EPISODE, 0, metadata: $metadata, title: 'Show 2', season: 2, episode: 12);
        $generator = Container::get(UserStatisticsGenerator::class);
        $report = $generator->generate($context);

        self::assertSame(0, $report['summary']['shows']['total']);
        self::assertSame([], $report['shows']);
        self::assertSame([$first, $duplicate, $second, $third, $last], array_column($report['in_progress']['episodes'], 'history_id'));
        self::assertSame(
            [
                'title' => 'Show 2',
                'type' => 'episode',
                'year' => null,
                'season' => 0,
                'episode' => 7,
                'progress' => 0,
                'history_id' => $first,
            ],
            $report['in_progress']['episodes'][0],
        );
        self::assertSame(
            [
                'title' => 'Movie 1',
                'type' => 'movie',
                'year' => 2020,
                'season' => null,
                'episode' => null,
                'progress' => 0,
                'history_id' => 24,
            ],
            $report['in_progress']['movies'][0],
        );
        self::assertSame(
            array_map(static fn(int $index): string => 'Movie ' . $index, range(1, 24)),
            array_column($report['in_progress']['movies'], 'title'),
        );
        foreach (['movies', 'episodes'] as $type) {
            self::assertCount($report['summary'][$type]['in_progress'], $report['in_progress'][$type]);
        }

        $public = $generator->export($report);
        self::assertCount(24, $public['in_progress']['movies']);
        self::assertCount(5, $public['in_progress']['episodes']);
        $markdown = $generator->markdown($public);
        self::assertStringContainsString('| Movie | Movie 24 |  | 2020 |  |', $markdown);
        self::assertStringContainsString('| Episode | Show 2 | 0x07 |  |  |', $markdown);
        self::assertStringContainsString('| Episode | Show 2 | 2x12 | 2020 |  |', $markdown);
        self::assertStringNotContainsString('00:00:00', $markdown);
    }

    public function test_positions(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        Config::save('progress.minimum', 60_000);
        Config::save('progress.threshold', 0);
        $cases = [
            'Latest smaller' => [
                'metadata' => ['alpha' => ['progress' => 7_200_000], 'beta' => ['progress' => 3_723_456]],
                'extra' => ['alpha' => ['received_at' => 2000], 'beta' => ['received_at' => 2001]],
                'progress' => 3_723_456,
            ],
            'Watched backend' => [
                'metadata' => ['alpha' => ['progress' => 75_000], 'beta' => ['progress' => 4_567_000, 'watched' => 1]],
                'extra' => ['alpha' => ['received_at' => 2000], 'beta' => ['received_at' => 2001]],
                'progress' => 75_000,
            ],
            'Stale position' => [
                'metadata' => ['alpha' => ['progress' => 70_000], 'beta' => ['progress' => 80_000]],
                'extra' => ['alpha' => ['received_at' => 1998], 'beta' => ['received_at' => 1997]],
                'progress' => 0,
            ],
            'Missing date' => [
                'metadata' => ['alpha' => ['progress' => 70_000], 'beta' => ['progress' => 80_000]],
                'extra' => ['alpha' => ['received_at' => null]],
                'progress' => 0,
            ],
        ];
        foreach ([iState::TYPE_MOVIE, iState::TYPE_EPISODE] as $type) {
            foreach ($cases as $title => $case) {
                $id = $this->record($context, $type, 0, title: $title);
                $context->db->getDBLayer()->query('UPDATE state SET metadata = :metadata, extra = :extra, updated = :updated WHERE id = :id', [
                    'metadata' => json_encode($case['metadata'], JSON_THROW_ON_ERROR),
                    'extra' => json_encode($case['extra'], JSON_THROW_ON_ERROR),
                    'updated' => 2000,
                    'id' => $id,
                ]);
            }
        }
        $generator = Container::get(UserStatisticsGenerator::class);
        $report = $generator->generate($context);

        foreach (['movies', 'episodes'] as $type) {
            self::assertSame(4, $report['summary'][$type]['in_progress']);
            self::assertCount(4, $report['in_progress'][$type]);
            $positions = array_column($report['in_progress'][$type], 'progress', 'title');
            foreach ($cases as $title => $case) {
                self::assertSame($case['progress'], $positions[$title]);
            }
        }

        Config::save('progress.threshold', 1);
        $report = $generator->generate($context);
        foreach (['movies', 'episodes'] as $type) {
            self::assertSame(4, $report['summary'][$type]['in_progress']);
            $positions = array_column($report['in_progress'][$type], 'progress', 'title');
            self::assertSame(4_567_000, $positions['Watched backend']);
            self::assertSame(3_723_456, $positions['Latest smaller']);
            self::assertSame(0, $positions['Stale position']);
            self::assertSame(0, $positions['Missing date']);
        }
    }

    public function test_exports(): void
    {
        $logger = $this->initFakeBackendApp($this->fakeBackendConfig('alpha'));
        $context = $this->makeUserContext('main', $logger);
        $title = "[link](javascript:alert(1))<img src=x>|\n# title";
        $this->record(
            $context,
            iState::TYPE_EPISODE,
            1,
            [Guid::GUID_TVDB => '10'],
            [
                'alpha' => [
                    'show' => 'server-private-id',
                    'played_at' => 100,
                    'url' => 'https://private.invalid',
                    'path' => '/private/path',
                    'poster' => 'https://poster.invalid',
                    'token' => 'private-token',
                    'extra' => ['genres' => ['<b>Drama</b>|[*]']],
                ],
            ],
            $title,
        );
        foreach ([iState::TYPE_MOVIE => 3_723_456, iState::TYPE_EPISODE => 70_001] as $type => $progress) {
            $this->record(
                $context,
                $type,
                0,
                metadata: ['alpha' => [
                    'progress' => $progress,
                    'url' => 'https://private.invalid',
                    'path' => '/private/path',
                    'poster' => 'https://poster.invalid',
                    'token' => 'private-token',
                ]],
                title: $title,
                season: 0,
                episode: 7,
                extra: ['alpha' => ['received_at' => 1000]],
            );
        }
        $generator = Container::get(UserStatisticsGenerator::class);
        $report = $generator->generate($context);
        $report['in_progress']['username'] = 'private-user';
        foreach (['movies', 'episodes'] as $type) {
            $report['in_progress'][$type][0]['username'] = 'private-user';
            $report['in_progress'][$type][0]['poster'] = 'https://poster.invalid';
            $report['in_progress'][$type][0]['played_at'] = 123;
        }
        $public = $generator->export($report);
        $json = json_encode($public, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('history_id', $report['shows'][0]);
        self::assertSame($title, $report['recent'][0]['title']);
        self::assertSame(['title', 'type', 'year', 'season', 'episode', 'played_at', 'history_id'], array_keys($report['recent'][0]));
        self::assertSame(['title', 'type', 'year', 'season', 'episode', 'played_at'], array_keys($public['recent'][0]));
        self::assertSame(
            ['version', 'generated_at', 'completed_at', 'summary', 'genres', 'shows', 'recent', 'in_progress'],
            array_keys($public),
        );
        self::assertSame(['movies', 'episodes'], array_keys($public['in_progress']));
        foreach (['movies', 'episodes'] as $type) {
            self::assertSame(['title', 'type', 'year', 'season', 'episode', 'progress'], array_keys($public['in_progress'][$type][0]));
            self::assertArrayHasKey('history_id', $report['in_progress'][$type][0]);
        }
        self::assertSame(3_723_456, $public['in_progress']['movies'][0]['progress']);
        self::assertSame(70_001, $public['in_progress']['episodes'][0]['progress']);
        foreach ([
            'history_id',
            'server-private-id',
            'guid_tvdb',
            'alpha',
            'private.invalid',
            '/private/path',
            'poster.invalid',
            'private-token',
            'private-user',
        ] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        $markdown = $generator->markdown($public);
        self::assertStringNotContainsString('<img', $markdown);
        self::assertStringNotContainsString('<b>', $markdown);
        self::assertStringNotContainsString('[link](', $markdown);
        self::assertStringContainsString('\\[link\\]\\(javascript:alert\\(1\\)\\)&lt;img src=x&gt;\\| \\# title', $markdown);
        self::assertStringContainsString('&lt;b&gt;Drama&lt;/b&gt;\\|\\[\\*\\]', $markdown);
        self::assertStringContainsString('## Recently watched', $markdown);
        self::assertStringContainsString('## In progress', $markdown);
        self::assertStringContainsString('| Type | Title | Episode | Year | Playback position |', $markdown);
        self::assertStringContainsString(
            '| Movie | \\[link\\]\\(javascript:alert\\(1\\)\\)&lt;img src=x&gt;\\| \\# title |  | 2020 | 01:02:03 |',
            $markdown,
        );
        self::assertStringContainsString(
            '| Episode | \\[link\\]\\(javascript:alert\\(1\\)\\)&lt;img src=x&gt;\\| \\# title | 0x07 | 2020 | 00:01:10 |',
            $markdown,
        );
        self::assertStringContainsString('Fully watched', $markdown);
        self::assertStringNotContainsString('fully_watched', $markdown);
        self::assertStringNotContainsString('imported', $markdown);
        self::assertStringNotContainsString('Missing information', $markdown);
        self::assertStringNotContainsString('history_id', $markdown);
        $api = Container::get(UserStatistics::class);
        $response = $api->export($this->getRequest(), 'markdown', Container::get(iImport::class), $logger);
        self::assertSame($markdown, (string) $response->getBody());
        self::assertStringEndsWith('.md"', $response->getHeaderLine('Content-Disposition'));
    }

    public function test_recent_limit(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        for ($index = 1; $index <= 24; $index++) {
            $this->record($context, iState::TYPE_MOVIE, 1, metadata: ['alpha' => ['played_at' => $index]]);
        }
        $report = Container::get(UserStatisticsGenerator::class)->generate($context);

        self::assertCount(20, $report['recent']);
        self::assertSame(range(24, 5), array_column($report['recent'], 'played_at'));
    }

    public function test_episode_numbers(): void
    {
        $logger = $this->initFakeBackendApp([]);
        $context = $this->makeUserContext('main', $logger);
        $this->record($context, iState::TYPE_EPISODE, 1, metadata: ['alpha' => ['played_at' => 100]], season: 0, episode: 7);
        $this->record($context, iState::TYPE_MOVIE, 1, metadata: ['alpha' => ['played_at' => 200]]);
        $this->record($context, iState::TYPE_EPISODE, 1, metadata: ['alpha' => ['played_at' => 300]], season: 2, episode: 12);
        $generator = Container::get(UserStatisticsGenerator::class);
        $report = $generator->generate($context);
        $public = $generator->export($report);

        self::assertSame([2, null, 0], array_column($public['recent'], 'season'));
        self::assertSame([12, null, 7], array_column($public['recent'], 'episode'));
        $markdown = $generator->markdown($public);
        self::assertStringContainsString('| Same title | 2x12 |', $markdown);
        self::assertStringContainsString('| Same title | 0x07 |', $markdown);
    }

    public function test_queue(): void
    {
        $logger = $this->initFakeBackendApp([], ['alice' => []]);
        $this->makeUserContext('main', $logger);
        $this->makeUserContext('alice', $logger);
        Config::save('events.queue.driver', 'file');
        Config::save('events.queue.path', self::$tmpPath . '/queue');
        Config::save('events.logfile', self::$tmpPath . '/events.jsonl');
        $api = Container::get(UserStatistics::class);
        $mapper = Container::get(iImport::class);
        $request = $this->getRequest();
        self::assertSame(404, $api->export($request, 'json', $mapper, $logger)->getStatusCode());
        $first = json_decode((string) $api->run($request, $mapper, $logger)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $second = json_decode((string) $api->run($request, $mapper, $logger)->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($first['queued']);
        self::assertFalse($first['running']);
        self::assertFalse($second['queued']);
        self::assertSame($first['event_id'], $second['event_id']);
        $events = Container::get(EventsRepository::class);
        $event = $events->findById($first['event_id']);
        self::assertSame(UserStatisticsCommand::ROUTE, $event->event_data['command']);
        self::assertSame(['--user', 'main', '-v'], $event->event_data['args']);
        $event->status = EventStatus::RUNNING;
        $events->save($event);
        $running = json_decode((string) $api->run($request, $mapper, $logger)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($running['running']);
        $alice = json_decode(
            (string) $api->run($this->getRequest(headers: ['X-User' => 'alice']), $mapper, $logger)->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertTrue($alice['queued']);
        self::assertNotSame($first['event_id'], $alice['event_id']);
        $status = json_decode((string) $api->index($request, $mapper, $logger)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($status['queued']);
        self::assertSame($first['event_id'], $status['queued_event']);

        $event->reference = 'task://' . UserStatisticsCommand::TASK_NAME;
        $events->save($event);
        $aliceEvent = $events->findById($alice['event_id']);
        $aliceEvent->status = EventStatus::SUCCESS;
        $events->save($aliceEvent);
        $scheduled = json_decode(
            (string) $api->run($this->getRequest(headers: ['X-User' => 'alice']), $mapper, $logger)->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertFalse($scheduled['queued']);
        self::assertTrue($scheduled['running']);
        self::assertSame($first['event_id'], $scheduled['event_id']);
    }

    public function test_command(): void
    {
        $logger = $this->initFakeBackendApp([], ['alice' => []]);
        $main = $this->makeUserContext('main', $logger);
        $alice = $this->makeUserContext('alice', $logger);
        $this->record($main, iState::TYPE_MOVIE, 1);
        $this->record($alice, iState::TYPE_MOVIE, 0);
        $command = new CommandTester(Container::get(UserStatisticsCommand::class));

        self::assertSame(0, $command->execute(['--user' => 'alice']));
        $generator = Container::get(UserStatisticsGenerator::class);
        self::assertNull($generator->latest($main));
        self::assertSame(0, $generator->latest($alice)['summary']['movies']['watched']);
    }

    public function test_user_cache(): void
    {
        $this->initTempApp();
        $alice = per_user_cache_adapter('alice');
        $alice->set('snapshot', 'alice');

        self::assertSame($alice, per_user_cache_adapter('alice'));
        self::assertSame('alice', per_user_cache_adapter('alice')->get('snapshot'));
        self::assertNull(per_user_cache_adapter('bob')->get('snapshot'));
        $this->initContainer();
        self::assertNull(per_user_cache_adapter('alice')->get('snapshot'));
    }

    /**
     * @param array<string,string> $parent
     * @param array<string,array<string,mixed>> $metadata
     * @param array<string,array<string,mixed>> $extra
     */
    private function record(
        UserContext $context,
        string $type,
        int $watched,
        array $parent = [],
        array $metadata = [],
        string $title = 'Same title',
        ?int $season = 1,
        ?int $episode = 1,
        ?int $year = 2020,
        array $extra = [],
    ): int {
        $entity = $context->db->insert(new StateEntity([
            'type' => $type,
            'watched' => $watched,
            'title' => $title,
            'year' => $year,
            'season' => iState::TYPE_EPISODE === $type ? $season : null,
            'episode' => iState::TYPE_EPISODE === $type ? $episode : null,
            'parent' => $parent,
            'metadata' => $metadata,
            'extra' => $extra,
            'updated' => 1000,
        ]));

        return (int) $entity->id;
    }
}
