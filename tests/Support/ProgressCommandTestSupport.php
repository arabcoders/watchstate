<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Jellyfin\Action\GetSessions as JellyfinGetSessions;
use App\Backends\Plex\Action\GetSessions as PlexGetSessions;
use App\Libs\Container;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

trait ProgressCommandTestSupport
{
    /**
     * @return array<string,array{string,string,array<string,string>}>
     */
    public static function progressBackends(): array
    {
        return [
            'Plex' => ['plex', '/:/timeline/', ['ratingKey' => '101', 'time' => '90000', 'duration' => '600000']],
            'Emby' => [
                'emby',
                '/Users/u000000000000000000000000000000e/Items/item-1/UserData',
                ['PlaybackPositionTicks' => '900000000'],
            ],
            'Jellyfin' => [
                'jellyfin',
                '/Users/u000000000000000000000000000000j/Items/item-1/UserData',
                ['PlaybackPositionTicks' => '900000000'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function progressMovie(string $backend, int $id, int $position, ?int $playedAt = null, bool $played = false): array
    {
        $guid = 'tt123456' . $id;
        $title = 'Test Movie ' . $id;

        if ('plex' === $backend) {
            return (
                [
                    'ratingKey' => (string) (100 + $id),
                    'title' => $title,
                    'type' => 'movie',
                    'year' => 2024,
                    'addedAt' => 1_704_067_200,
                    'Guid' => [['id' => 'imdb://' . $guid]],
                    'duration' => 600_000,
                    'viewCount' => (int) $played,
                    'viewOffset' => $position,
                ] + (null === $playedAt ? [] : ['lastViewedAt' => $playedAt])
            );
        }

        return [
            'Id' => 'item-' . $id,
            'Name' => $title,
            'Type' => 'Movie',
            'ProductionYear' => 2024,
            'DateCreated' => '2024-01-01T00:00:00Z',
            'ProviderIds' => ['Imdb' => $guid],
            'UserData' =>
                [
                    'Played' => $played,
                    'PlaybackPositionTicks' => $position * 10_000,
                ] + (null === $playedAt ? [] : ['LastPlayedDate' => make_date($playedAt)->format(DATE_ATOM)]),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<array{string,string,array<string,mixed>}> $writes
     */
    private function progressHttp(string $backend, array $items, array &$writes, ?int $playing = null): HttpClient
    {
        $sessions = 'plex' === $backend ? PlexGetSessions::class : JellyfinGetSessions::class;
        new ReflectionProperty($sessions, 'sessionsCache')->setValue(null, []);

        $http = new HttpClient(new MockHttpClient(
            static function (string $method, string $url, array $options) use ($backend, $items, &$writes, $playing): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ('GET' !== $method || in_array($path, ['/:/scrobble', '/:/unscrobble', '/:/timeline/'], true)) {
                    $body = $options['body'] ?? '';
                    $writes[] = [
                        $method,
                        $path,
                        '' === $body ? $query : json_decode($body, true, flags: JSON_THROW_ON_ERROR),
                    ];
                    return new MockResponse('', ['http_code' => 204]);
                }

                $itemId = null;
                if (1 === preg_match('~/items/item-(\d+)$~', $path, $match)) {
                    $itemId = (int) $match[1];
                } elseif (1 === preg_match('~^/library/metadata/(\d+)$~', $path, $match)) {
                    $itemId = (int) $match[1] - 100;
                }

                $payload = match (true) {
                    '/system/Info' === $path => ['Id' => 'server', 'Version' => '10.11.0'],
                    '/Sessions' === $path => null === $playing
                        ? []
                        : [[
                            'UserId' => 'u000000000000000000000000000000' . $backend[0],
                            'NowPlayingItem' => ['Id' => 'item-' . $playing],
                            'PlayState' => ['PositionTicks' => 0],
                            'LastActivityDate' => '2024-01-01T00:00:00Z',
                        ]],
                    '/status/sessions' === $path => ['MediaContainer' => [
                        'Metadata' => null === $playing
                            ? []
                            : [[
                                'User' => ['id' => 11111111, 'thumb' => '/users/test/avatar'],
                                'ratingKey' => (string) (100 + $playing),
                                'viewOffset' => 0,
                                'Player' => ['state' => 'playing'],
                            ]],
                    ]],
                    '/library/sections' === $path => [
                        'MediaContainer' => ['Directory' => [['key' => '10', 'title' => 'Movies', 'type' => 'movie']]],
                    ],
                    '/library/sections/10/all' === $path => ['MediaContainer' => ['Metadata' => array_values($items)]],
                    null !== $itemId && 'plex' === $backend => ['MediaContainer' => ['Metadata' => [$items[$itemId]]]],
                    null !== $itemId => $items[$itemId],
                    str_ends_with($path, '/items/') && !isset($query['parentId']) => [
                        'Items' => [['Id' => 'movies', 'Name' => 'Movies', 'CollectionType' => 'movies']],
                    ],
                    str_ends_with($path, '/items/') && '0' === ($query['limit'] ?? null) => [
                        'TotalRecordCount' => count($items),
                    ],
                    str_ends_with($path, '/items/') => ['Items' => array_values($items)],
                    default => throw new RuntimeException('Unexpected request to ' . $path),
                };

                $info = '/library/sections/10/all' === $path
                    ? ['response_headers' => ['X-Plex-Container-Total-Size: ' . count($items)]]
                    : [];
                return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), $info);
            },
        ));
        Container::getContainer()->add(iHttp::class, fn() => $http, overwrite: true);

        return $http;
    }

    /**
     * @param array<array{string,string,array<string,mixed>}> $writes
     * @param array<string,string> $expectedProgress
     */
    private function assertProgressWrite(array $writes, string $progressPath, array $expectedProgress): void
    {
        self::assertCount(1, $writes);
        [$method, $path, $progress] = $writes[0];
        self::assertSame('POST', $method);
        self::assertSame($progressPath, $path);
        foreach ($expectedProgress as $key => $value) {
            self::assertSame($value, $progress[$key] ?? null, $key);
        }
    }
}
