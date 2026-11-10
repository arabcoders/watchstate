<?php

declare(strict_types=1);

namespace Tests\API\History;

use App\API\History\Index;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\HistoryExport;
use App\Libs\Middlewares\ParseJsonBodyMiddleware;
use App\Libs\Stream;
use App\Libs\TestCase;
use App\Libs\UserContext;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use RuntimeException;
use Tests\Support\FakeBackendClient;
use Tests\Support\RequestResponseTrait;
use Tests\Support\StateCommandTestSupport;

final class WorkflowTest extends TestCase
{
    use RequestResponseTrait;
    use StateCommandTestSupport;

    private UserContext $context;
    private Index $api;
    private HistoryExport $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initFakeBackendApp([...$this->fakeBackendConfig('alpha'), ...$this->fakeBackendConfig('beta')]);
        $this->context = $this->makeUserContext('main', new Logger('test', [new NullHandler()]));
        $this->api = Container::get(Index::class);
        $this->exporter = new HistoryExport();
        Config::save('progress.minimum', 60_000);
        Config::save('progress.threshold', 0);
    }

    public function test_combined_paging(): void
    {
        $metadata = [
            'alpha' => ['library' => '1', 'extra' => ['genres' => ['Drama']]],
            'beta' => ['library' => '2', 'extra' => ['genres' => ['drama']]],
        ];
        $this->record('Excluded', $metadata);
        $this->record('Zulu', $metadata, ['watched' => 1]);
        $this->record('Alpha', $metadata, ['watched' => 1]);
        $this->record('Episode', $metadata, ['watched' => 1, 'type' => 'episode']);
        $this->record('Old', $metadata, ['watched' => 1, 'year' => 1999]);
        $this->record('Other library', ['alpha' => ['library' => '2', 'extra' => ['genres' => ['drama']]]], ['watched' => 1]);

        $query = [
            'genres' => [' DRAMA ', 'comedy'],
            'status' => 'watched',
            'library' => ['alpha://1'],
            'type' => 'movie',
            'year_min' => 2000,
            'year_max' => 2000,
            'sort' => ['title:asc'],
            'perpage' => 1,
        ];
        $payload = $this->json($this->api->list($this->getRequest(query: $query)));
        self::assertSame(2, $payload['paging']['total']);
        self::assertSame(['Alpha'], array_column($payload['history'], 'title'));
        self::assertStringContainsString('genres', $payload['links']['next_url']);
        $payload = $this->json($this->api->list($this->getRequest(query: [...$query, 'page' => 2])));
        self::assertSame(2, $payload['paging']['total']);
        self::assertSame(['Zulu'], array_column($payload['history'], 'title'));
        self::assertNull($payload['paging']['next_page']);
        $export = $this->json($this->export(['filters' => $query, 'group' => 'records']));
        self::assertSame(2, $export['count']);
        self::assertSame(['Alpha', 'Zulu'], array_column($export['items'], 'title'));
    }

    public function test_legacy_genre(): void
    {
        $this->record('A', ['alpha' => ['extra' => ['genres' => ['dark comedy']]]], ['watched' => 1]);
        $this->record('B', ['alpha' => ['extra' => ['genres' => ['comedy']]]], ['watched' => 1]);
        $this->record('C', ['alpha' => ['extra' => ['genres' => ['comedy']]]]);
        $query = ['genres' => 'comedy', 'watched' => 1, 'sort' => ['title:desc'], 'perpage' => 1, 'view' => 'title'];
        $payload = $this->json($this->api->list($this->getRequest(query: $query)));
        self::assertSame(2, $payload['paging']['total']);
        self::assertSame([['title' => 'B']], $payload['history']);
        $payload = $this->json($this->api->list($this->getRequest(query: [...$query, 'page' => 2])));
        self::assertSame([['title' => 'A']], $payload['history']);
        $payload = $this->json($this->api->list($this->getRequest(query: [...$query, 'genres' => ['comedy']])));
        self::assertSame(1, $payload['paging']['total']);
        self::assertSame([['title' => 'B']], $payload['history']);
    }

    public function test_genre_shapes(): void
    {
        foreach ([null, 'drama', 'not JSON', 12, true, ['nested' => 'drama'], [], [['drama']]] as $index => $genres) {
            $this->record('Unknown ' . $index, ['alpha' => ['extra' => ['genres' => $genres]]]);
        }
        $this->record('Drama', ['alpha' => ['extra' => ['genres' => ['Drama', null, 12, ['bad']]]]]);
        $filters = ['genres' => ['drama']];
        $list = $this->json($this->api->list($this->getRequest(query: $filters)));
        self::assertSame(['Drama'], array_column($list['history'], 'title'));
        $export = $this->json($this->export(['filters' => $filters, 'group' => 'records']));
        self::assertSame(['Drama'], array_column($export['items'], 'title'));
        $options = $this->json($this->api->options($this->getRequest()));
        self::assertSame(['drama'], $options['genres']);
        $export = $this->json($this->export(['filters' => [], 'group' => 'records']));
        foreach ($export['items'] as $item) {
            self::assertSame('Drama' === $item['title'] ? ['drama'] : [], $item['genres']);
        }
    }

    public function test_year_range(): void
    {
        $this->record('Movie');
        foreach ([
            ['year_min' => 0],
            ['year_max' => 10000],
            ['year_min' => -1],
            ['year_min' => 2025, 'year_max' => 2000],
            ['year_max' => 'invalid'],
        ] as $filters) {
            self::assertSame(400, $this->api->list($this->getRequest(query: $filters))->getStatusCode());
            self::assertSame(400, $this->export(['filters' => $filters])->getStatusCode());
        }
        $payload = $this->json($this->export(['filters' => ['year_min' => 1, 'year_max' => 9999], 'group' => 'records']));
        self::assertSame(1, $payload['count']);
    }

    public function test_library_sources(): void
    {
        $this->record('Alpha', ['alpha' => ['library' => '1']]);
        $this->record('Beta', ['beta' => ['library' => '1']]);
        $this->record('Both', ['alpha' => ['library' => '1'], 'beta' => ['library' => '1']]);
        $this->record('Quoted', ['a\'b.c' => ['library' => "x' OR 1=1 --"]]);
        $payload = $this->json($this->api->list($this->getRequest(query: ['library' => 'alpha://1', 'sort' => ['title:asc']])));
        self::assertSame(['Alpha', 'Both'], array_column($payload['history'], 'title'));
        $payload = $this->json($this->api->list($this->getRequest(query: ['library' => ['alpha://1', 'beta://1']])));
        self::assertSame(3, $payload['paging']['total']);
        $payload = $this->json($this->api->list($this->getRequest(query: ['library' => "a'b.c://x' OR 1=1 --"])));
        self::assertSame(['Quoted'], array_column($payload['history'], 'title'));
    }

    public function test_statuses(): void
    {
        $this->record('Watched', ['alpha' => ['progress' => 70_000]], ['watched' => 1]);
        $this->record('Progress', ['alpha' => ['progress' => 60_001]]);
        $this->record('Minimum', ['alpha' => ['progress' => 60_000]]);
        $this->record('Backend watched', ['alpha' => ['progress' => 70_000, 'watched' => 1]]);
        $this->record('Unknown', ['alpha' => 'not JSON', 'beta' => null]);
        foreach (['watched' => 1, 'in_progress' => 1, 'not_started' => 3] as $status => $count) {
            $list = $this->json($this->api->list($this->getRequest(query: ['status' => $status])));
            $export = $this->json($this->export(['filters' => ['status' => $status], 'group' => 'records']));
            self::assertSame($count, $list['paging']['total']);
            self::assertSame($count, $export['count']);
            self::assertSame([$status], array_values(array_unique(array_column($export['items'], 'status'))));
        }
        Config::save('progress.threshold', 1);
        $payload = $this->json($this->api->list($this->getRequest(query: ['status' => 'in_progress'])));
        self::assertSame(2, $payload['paging']['total']);
        self::assertSame(404, $this->api->list($this->getRequest(query: ['status' => 'not_started', 'in_progress' => 1]))->getStatusCode());
    }

    public function test_full_export(): void
    {
        foreach (range(1, 27) as $index) {
            $this->record('Movie ' . $index, [], ['watched' => 1]);
        }
        $response = $this->export([
            'filters' => ['status' => 'watched', 'page' => 8, 'perpage' => 1, 'view' => 'id'],
            'group' => 'records',
        ]);
        $payload = $this->json($response);
        self::assertSame(1, $payload['version']);
        self::assertSame('records', $payload['grouping']);
        self::assertSame(27, $payload['count']);
        self::assertMatchesRegularExpression(
            '/^attachment; filename="history-\d+\.json"$/',
            $response->getHeaderLine('Content-Disposition'),
        );
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Movie 1', $payload['items'][0]['title']);
        self::assertSame('Movie 27', $payload['items'][26]['title']);
    }

    public function test_selection(): void
    {
        $a = $this->record('A', [], ['watched' => 1]);
        $b = $this->record('B', [], ['watched' => 1]);
        $c = $this->record('C');
        $payload = $this->json($this->export(['filters' => ['watched' => 1], 'ids' => [$b, $c, $b], 'group' => 'records']));
        self::assertSame(1, $payload['count']);
        self::assertSame(['B'], array_column($payload['items'], 'title'));
        self::assertSame(404, $this->export(['filters' => ['watched' => 1], 'ids' => [$c]])->getStatusCode());
        self::assertSame(404, $this->export(['filters' => [], 'ids' => [$a + 999]])->getStatusCode());
    }

    public function test_export_order(): void
    {
        $this->record('A');
        $this->record('B');
        $filters = ['sort' => ['id:desc']];
        $list = $this->json($this->api->list($this->getRequest(query: $filters)));
        $export = $this->json($this->export(['filters' => $filters, 'group' => 'records']));
        self::assertSame(['B', 'A'], array_column($export['items'], 'title'));
        self::assertSame(array_column($list['history'], 'title'), array_column($export['items'], 'title'));
    }

    public function test_invalid_selection(): void
    {
        $this->record('A');
        foreach ([[], null, '1', [0], [-1], ['1'], [1.0], [true], [[1]], ['id' => 1]] as $ids) {
            self::assertSame(400, $this->export(['filters' => [], 'ids' => $ids])->getStatusCode());
        }
        foreach ([
            ['filters' => 'x'],
            ['filters' => ['watched']],
            ['filters' => [], 'group' => 'invalid'],
            ['filters' => ['genres' => [12]]],
            ['filters' => ['library' => 'invalid']],
            ['filters' => ['year_min' => 'n/a']],
            ['filters' => ['status' => 'liked']],
            ['filters' => ['parent' => 'bad']],
        ] as $body) {
            self::assertSame(400, $this->export($body)->getStatusCode());
        }
        self::assertSame(404, $this->export(['filters' => []], 'csv')->getStatusCode());
    }

    public function test_choice_limit(): void
    {
        foreach (['genres' => 'drama', 'library' => 'alpha://1'] as $field => $value) {
            foreach ([101, 980] as $count) {
                $filters = [$field => array_fill(0, $count, $value)];

                foreach ([
                    $this->api->list($this->getRequest(query: $filters)),
                    $this->export(['filters' => $filters, 'group' => 'records']),
                ] as $response) {
                    self::assertSame(400, $response->getStatusCode());
                    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
                    self::assertSame("History filter '{$field}' accepts at most 100 choices.", $payload['error']['message']);
                }
            }
        }
    }

    public function test_choice_boundary(): void
    {
        $this->record('Drama', ['alpha' => ['library' => '1', 'extra' => ['genres' => ['drama']]]]);

        foreach ([0, 1, 100] as $count) {
            $filters = [
                'genres' => array_fill(0, $count, 'drama'),
                'library' => array_fill(0, $count, 'alpha://1'),
            ];
            $list = $this->json($this->api->list($this->getRequest(query: $filters)));
            $export = $this->json($this->export(['filters' => $filters, 'group' => 'records']));

            self::assertSame(1, $list['paging']['total']);
            self::assertSame(1, $export['count']);
            self::assertSame('Drama', $export['items'][0]['title']);
        }
    }

    /** @param array<string,mixed> $filters */
    #[DataProvider('invalid_strings')]
    public function test_string_types(array $filters): void
    {
        self::assertSame(400, $this->api->list($this->getRequest(query: $filters))->getStatusCode());
        $request = $this->getRequest(
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            body: Stream::create(json_encode(['filters' => $filters, 'group' => 'records'], JSON_THROW_ON_ERROR)),
        );
        $response = new ParseJsonBodyMiddleware()->process(
            $request,
            $this->getHandler(fn(iRequest $parsed): iResponse => $this->api->export($parsed, 'json', $this->exporter)),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalid_strings(): iterable
    {
        foreach (['rguid', 'parent', 'guids', 'metadata', 'extra'] as $field) {
            foreach ([123, 1.5, true, false] as $index => $value) {
                $filters = in_array($field, ['metadata', 'extra'], true)
                    ? [$field => 1, 'key' => $value, 'value' => 'x']
                    : [$field => $value];

                yield $field . ':' . $index => [$filters];
            }
        }
    }

    public function test_json_body(): void
    {
        $id = $this->record('Selected', ['alpha' => ['id' => 123]]);
        $stream = Stream::make('php://temp', 'w+b');
        $stream->write(json_encode([
            'filters' => ['title' => 'Selected', 'metadata' => true, 'key' => 'alpha.id', 'value' => 123],
            'ids' => [$id],
            'group' => 'records',
        ], JSON_THROW_ON_ERROR));
        $stream->rewind();
        $request = $this->getRequest(method: 'POST', headers: ['Content-Type' => 'application/json'], body: $stream);
        $response = new ParseJsonBodyMiddleware()->process(
            $request,
            $this->getHandler(fn(iRequest $parsed): iResponse => $this->api->export($parsed, 'json', $this->exporter)),
        );
        $payload = $this->json($response);
        self::assertSame(['Selected'], array_column($payload['items'], 'title'));
    }

    public function test_large_selection(): void
    {
        $id = $this->record('Selected');
        $payload = $this->json($this->export(['filters' => [], 'ids' => [$id, ...range(100_000, 140_000)], 'group' => 'records']));
        self::assertSame(1, $payload['count']);
        self::assertSame('Selected', $payload['items'][0]['title']);
    }

    public function test_show_counts(): void
    {
        $first = $this->record(
            'Series',
            [
                'alpha' => [
                    'show' => '10',
                    'library' => '1',
                    'rating' => '8',
                    'played_at' => 1_700_000_001,
                    'extra' => ['genres' => ['drama']],
                ],
            ],
            ['type' => 'episode', 'parent' => ['guid_tvdb' => '100'], 'watched' => 1, 'episode' => 1],
        );
        $this->record(
            'Series',
            ['beta' => ['show' => '20', 'extra' => ['genres' => ['comedy']]]],
            ['type' => 'episode', 'parent' => ['guid_tvdb' => '100'], 'episode' => 2],
        );
        $this->record('Series', ['beta' => ['show' => '20', 'progress' => 70_000]], ['type' => 'episode', 'episode' => 3]);
        $this->record('Other', ['alpha' => ['show' => '30']], ['type' => 'episode', 'watched' => 1]);
        $payload = $this->json($this->export([
            'filters' => ['watched' => 1, 'genres' => ['drama'], 'library' => 'alpha://1', 'page' => 2, 'perpage' => 1],
            'group' => 'titles',
        ]));
        self::assertSame(1, $payload['count']);
        $show = $payload['items'][0];
        self::assertSame('show', $show['type']);
        self::assertSame('in_progress', $show['status']);
        self::assertSame(['watched' => 1, 'available' => 3, 'in_progress' => 1], $show['episodes']);
        self::assertSame(['comedy', 'drama'], $show['genres']);
        self::assertSame(1_700_000_001, $show['last_watched_at']);
        self::assertNull($show['year']);
        self::assertNull($show['user_rating']);
        $payload = $this->json($this->export(['filters' => [], 'ids' => [$first], 'group' => 'titles']));
        self::assertSame($show, $payload['items'][0]);
    }

    public function test_show_identity(): void
    {
        $this->record('Same title', ['alpha' => ['show' => '10']], ['type' => 'episode', 'watched' => 1]);
        $this->record('Same title', ['alpha' => ['show' => '20']], ['type' => 'episode']);
        $this->record('Same title', ['alpha' => ['show' => '??']], ['type' => 'episode', 'episode' => 3]);
        $this->record('Same title', ['alpha' => ['show' => ['bad']]], ['type' => 'episode', 'episode' => 4]);
        $this->record('Same title', [], ['type' => 'movie']);
        $payload = $this->json($this->export(['filters' => [], 'group' => 'titles']));
        self::assertSame(5, $payload['count']);
        $shows = array_values(array_filter($payload['items'], static fn(array $item): bool => 'show' === $item['type']));
        self::assertCount(2, $shows);
        self::assertSame(['fully_watched', 'not_started'], array_column($shows, 'status'));
        self::assertCount(2, array_filter($payload['items'], static fn(array $item): bool => 'episode' === $item['type']));
    }

    #[DataProvider('export_cases')]
    public function test_export_memory(string $format, string $group): void
    {
        $metadata = json_encode([
            'alpha' => ['show' => '100', 'extra' => ['genres' => ['drama'], 'overview' => str_repeat('x', 32_768)]],
        ], JSON_THROW_ON_ERROR);
        $this->context
            ->db
            ->getDBLayer()
            ->query(
                <<<'SQL'
                    WITH RECURSIVE records(number) AS (
                        VALUES (1)
                        UNION ALL SELECT number + 1 FROM records WHERE number < 2048
                    )
                    INSERT INTO state (type, updated, watched, via, title, season, episode, parent, metadata)
                    SELECT 'episode', 1700000000, number % 2, 'alpha', 'Large show', 1, number,
                           '{"guid_tvdb":"100"}', :metadata
                    FROM records
                    SQL,
                ['metadata' => $metadata],
            );

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $response = $this->export(['filters' => [], 'group' => $group], $format);
        $peak = memory_get_peak_usage() - $before;

        self::assertSame(200, $response->getStatusCode());
        self::assertLessThan(24 * 1024 * 1024, $peak, 'Export retained raw episode metadata.');
        if ('json' === $format) {
            $payload = $this->json($response);
            self::assertSame('titles' === $group ? 1 : 2048, $payload['count']);
            if ('titles' === $group) {
                self::assertSame(['watched' => 1024, 'available' => 2048, 'in_progress' => 0], $payload['items'][0]['episodes']);
            }
        } else {
            $body = (string) $response->getBody();
            self::assertSame('titles' === $group ? 1 : 2048, substr_count($body, '| Large show |'));
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function export_cases(): iterable
    {
        yield 'record JSON' => ['json', 'records'];
        yield 'title JSON' => ['json', 'titles'];
        yield 'record Markdown' => ['markdown', 'records'];
        yield 'title Markdown' => ['markdown', 'titles'];
    }

    public function test_show_bridge(): void
    {
        $selected = $this->record(
            'Bridge show',
            ['alpha' => ['show' => '10']],
            [
                'type' => 'episode',
                'parent' => ['guid_tvdb' => '10'],
                'episode' => 1,
                'watched' => 1,
            ],
        );
        $this->record(
            'Bridge show',
            ['beta' => ['show' => '20']],
            [
                'type' => 'episode',
                'parent' => ['guid_tmdb' => '20'],
                'episode' => 2,
            ],
        );
        $this->record(
            'Bridge show',
            [],
            [
                'type' => 'episode',
                'parent' => ['guid_tvdb' => '10', 'guid_tmdb' => '20'],
                'episode' => 3,
            ],
        );

        $payload = $this->json($this->export(['filters' => ['watched' => 1], 'ids' => [$selected], 'group' => 'titles']));

        self::assertSame(1, $payload['count']);
        self::assertSame(['watched' => 1, 'available' => 3, 'in_progress' => 0], $payload['items'][0]['episodes']);
        self::assertSame('in_progress', $payload['items'][0]['status']);
    }

    public function test_privacy_ratings(): void
    {
        $this->context->config->set('alpha.type', 'plex')->persist();
        $this->record('Personal', [
            'alpha' => [
                'rating' => '8.5',
                'played_at' => 1_700_000_000,
                'path' => '/private/secret.mkv',
                'id' => 'private-id',
                'url' => 'https://secret.invalid',
                'token' => 'secret-token',
                'extra' => ['genres' => ['Drama']],
            ],
        ]);
        $this->record('Unrated', ['alpha' => ['rating' => '0']]);
        $this->record('Critic', ['beta' => ['rating' => '9.8']]);
        $this->record('Episode', ['alpha' => ['rating' => '7']], ['type' => 'episode', 'year' => 2023]);
        $response = $this->export(['filters' => [], 'group' => 'records']);
        $payload = $this->json($response);
        $items = array_column($payload['items'], null, 'title');
        self::assertEquals(8.5, $items['Personal']['user_rating']);
        self::assertNull($items['Unrated']['user_rating']);
        self::assertNull($items['Critic']['user_rating']);
        self::assertNull($items['Unrated']['last_watched_at']);
        self::assertSame(1_700_000_000, $items['Personal']['last_watched_at']);
        self::assertSame(2023, $items['Episode']['year']);
        self::assertSame(
            ['title', 'type', 'year', 'genres', 'status', 'season', 'episode', 'progress', 'last_watched_at', 'user_rating'],
            array_keys($items['Personal']),
        );
        foreach (['secret', 'private-id', 'alpha', 'beta', 'metadata', 'history_id', 'library', 'poster', 'uuid'] as $private) {
            self::assertStringNotContainsString($private, (string) $response->getBody());
        }
    }

    public function test_markdown(): void
    {
        $this->record(
            "<script>x</script>|[bad](javascript:x)\n# Title",
            ['alpha' => ['progress' => 70_000, 'extra' => ['genres' => ['Drama|Comedy']]]],
            ['type' => 'episode', 'season' => 2, 'episode' => 3],
        );
        $response = $this->export(['filters' => [], 'group' => 'records'], 'markdown');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="history-\d+\.md"$/', $response->getHeaderLine('Content-Disposition'));
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('[bad](javascript:x)', $body);
        self::assertStringContainsString('\\|', $body);
        self::assertStringContainsString('2x03', $body);
        self::assertStringContainsString('00:01:10', $body);
        self::assertStringNotContainsString("\n# Title", $body);
        self::assertStringNotContainsString('disliked', $body);
        self::assertStringNotContainsString('alpha', $body);
    }

    public function test_options(): void
    {
        $this->record('A', [
            'alpha' => ['library' => '1', 'extra' => ['genres' => [' Drama ', '??', 'unknown', '', 'COMEDY']]],
            'beta' => ['library' => '2'],
            'removed' => ['library' => '9'],
        ]);
        FakeBackendClient::setLibraryResponse('main', 'alpha', [['id' => 1, 'title' => 'Cinema']]);
        FakeBackendClient::setLibraryResponse('main', 'beta', new RuntimeException('Unavailable'));
        $payload = $this->json($this->api->options($this->getRequest()));
        self::assertSame(['comedy', 'drama'], $payload['genres']);
        $libraries = array_column($payload['libraries'], 'label', 'value');
        self::assertSame('alpha / Cinema', $libraries['alpha://1']);
        self::assertSame('beta / 2', $libraries['beta://2']);
        self::assertSame('removed / 9', $libraries['removed://9']);
        self::assertCount(2, FakeBackendClient::getCalls('libraries'));
        self::assertSame($payload, $this->json($this->api->options($this->getRequest())));
        self::assertCount(2, FakeBackendClient::getCalls('libraries'));
    }

    public function test_user_scope(): void
    {
        $this->record(
            'Main secret',
            ['alpha' => ['library' => '1', 'extra' => ['genres' => ['private']]]],
            ['type' => 'episode', 'parent' => ['guid_tvdb' => '1'], 'watched' => 1],
        );
        $mainOptions = $this->json($this->api->options($this->getRequest()));
        self::assertSame(['private'], $mainOptions['genres']);
        $userDir = self::$tmpPath . '/users/alice';
        mkdir($userDir, 0o755, true);
        $this->writeBackendsFile($userDir . '/servers.yaml', $this->fakeBackendConfig('beta'));
        $alice = $this->makeUserContext('alice', new Logger('test', [new NullHandler()]));
        $id = $this->record(
            'Alice show',
            ['beta' => ['library' => '7', 'extra' => ['genres' => ['comedy']]]],
            ['type' => 'episode', 'parent' => ['guid_tvdb' => '1']],
            $alice,
        );
        FakeBackendClient::setLibraryResponse('alice', 'beta', [['id' => 7, 'title' => 'Alice library']]);
        $payload = $this->json($this->export(['filters' => [], 'ids' => [$id], 'group' => 'titles'], user: 'alice'));
        self::assertSame(1, $payload['count']);
        self::assertSame('Alice show', $payload['items'][0]['title']);
        self::assertSame(['watched' => 0, 'available' => 1, 'in_progress' => 0], $payload['items'][0]['episodes']);
        $options = $this->json($this->api->options($this->getRequest(headers: ['X-User' => 'alice'])));
        self::assertSame(['comedy'], $options['genres']);
        self::assertSame([['value' => 'beta://7', 'label' => 'beta / Alice library']], $options['libraries']);
        $list = $this->json($this->api->list($this->getRequest(headers: ['X-User' => 'alice'])));
        self::assertSame(['Alice show'], array_column($list['history'], 'title'));
        self::assertSame(404, $this->export(['filters' => ['title' => 'Main secret']], user: 'alice')->getStatusCode());
    }

    public function test_advanced_export(): void
    {
        $metadata = ['alpha' => ['path' => '/media/one.mkv', 'extra' => ['title' => 'Pilot'], 'custom' => 'needle']];
        $id = $this->record('Series', $metadata, [
            'type' => 'episode',
            'parent' => ['guid_tvdb' => '123'],
            'season' => 2,
            'episode' => 3,
            'extra' => ['flag' => 'yes'],
        ]);
        $this->record('Series', $metadata, ['type' => 'episode', 'parent' => ['guid_tvdb' => '124'], 'season' => 2, 'episode' => 3]);
        foreach ([
            ['rguid' => 'tvdb://123/2/3'],
            ['parent' => 'tvdb://123', 'season' => 2, 'episode' => 3],
            ['metadata' => 1, 'key' => 'alpha.custom', 'value' => 'needle', 'exact' => 1],
            ['extra' => 1, 'key' => 'flag', 'value' => 'yes'],
            ['path' => '/media/one', 'subtitle' => 'Pilot', 'id' => $id],
        ] as $filters) {
            $list = $this->json($this->api->list($this->getRequest(query: $filters)));
            $export = $this->json($this->export(['filters' => $filters, 'group' => 'records']));
            self::assertSame($list['paging']['total'], $export['count']);
            self::assertSame(array_column($list['history'], 'title'), array_column($export['items'], 'title'));
        }
    }

    public function test_advanced_and(): void
    {
        $this->record('Both', [
            'alpha' => ['path' => '/media/match.mkv', 'extra' => ['genres' => ['drama']]],
            'beta' => ['extra' => ['title' => 'Pilot']],
        ]);
        $this->record('Path only', ['alpha' => ['path' => '/media/match.mkv', 'extra' => ['genres' => ['drama']]]]);
        $this->record('Subtitle only', ['alpha' => ['path' => '/other.mkv', 'extra' => ['title' => 'Pilot', 'genres' => ['drama']]]]);
        $this->record('Wrong genre', ['alpha' => ['path' => '/media/match.mkv', 'extra' => ['title' => 'Pilot', 'genres' => ['comedy']]]]);
        $this->record('Malformed', ['alpha' => 'bad JSON', 'beta' => ['extra' => ['title' => 'Pilot', 'genres' => ['drama']]]]);
        $filters = ['path' => '/media/match', 'subtitle' => 'Pilot', 'genres' => ['drama']];
        $list = $this->json($this->api->list($this->getRequest(query: $filters)));
        $export = $this->json($this->export(['filters' => $filters, 'group' => 'records']));
        self::assertSame(['Both'], array_column($list['history'], 'title'));
        self::assertSame(['Both'], array_column($export['items'], 'title'));
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $overrides
     */
    private function record(string $title, array $metadata = [], array $overrides = [], ?UserContext $context = null): int
    {
        $context ??= $this->context;
        $entity = $context->db->insert(new StateEntity(array_replace([
            'title' => $title,
            'type' => 'movie',
            'year' => 2000,
            'watched' => 0,
            'updated' => 1_700_000_000,
            'via' => 'alpha',
            'season' => 1,
            'episode' => 1,
            'extra' => ['alpha' => ['received_at' => 1_700_000_000]],
        ], $overrides)));
        $context->db->getDBLayer()->query('UPDATE state SET metadata = :metadata WHERE id = :id', [
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'id' => $entity->id,
        ]);

        return (int) $entity->id;
    }

    /** @param array<string,mixed> $body */
    private function export(array $body, string $format = 'json', string $user = 'main'): iResponse
    {
        $request = $this->getRequest(method: 'POST', post: $body, headers: ['X-User' => $user]);

        return $this->api->export($request, $format, $this->exporter);
    }

    /** @return array<string,mixed> */
    private function json(iResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
