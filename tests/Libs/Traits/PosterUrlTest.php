<?php

declare(strict_types=1);

namespace Tests\Libs\Traits;

use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\MockHttpClient;
use App\Libs\TestCase;
use App\Libs\Traits\APITraits;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class PosterUrlTest extends TestCase
{
    use APITraits;

    private MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempApp();
        $this->seedTestServersConfig();
        $this->http = new MockHttpClient(static function (): never {
            self::fail('Poster URLs must not request backend metadata.');
        });
        Container::add(iHttp::class, fn() => $this->http);
    }

    /**
     * Verify movie and show thumbnails omit credentials.
     */
    public function test_plex_posters(): void
    {
        $client = $this->getClient('test_plex');

        foreach ([iState::TYPE_MOVIE, iState::TYPE_SHOW] as $type) {
            self::assertSame(
                '/backend/test_plex/proxy/library/metadata/12/thumb/Poster%20Name%2F1',
                $this->getBackendPosterUrl(
                    $client,
                    ['type' => $type],
                    [
                        'thumb' => '/library/metadata/12/thumb/Poster Name%2F1?X-Plex-Token=secret#private',
                    ],
                ),
            );
        }

        self::assertSame(0, $this->http->getRequestsCount());
    }

    /**
     * Prefer the series poster, then season and episode thumbnails.
     */
    public function test_plex_episode(): void
    {
        $client = $this->getClient('test_plex');
        $raw = [
            'grandparentThumb' => '/library/metadata/1/thumb/series',
            'parentThumb' => '/library/metadata/2/thumb/season',
            'thumb' => '/library/metadata/3/thumb/episode',
        ];

        foreach (['grandparentThumb', 'parentThumb', 'thumb'] as $key) {
            self::assertSame(
                '/backend/test_plex/proxy/' . ltrim($raw[$key], '/'),
                $this->getBackendPosterUrl($client, ['type' => iState::TYPE_EPISODE], $raw),
            );
            unset($raw[$key]);
        }

        self::assertNull($this->getBackendPosterUrl($client, ['type' => iState::TYPE_EPISODE], $raw));
        self::assertSame(0, $this->http->getRequestsCount());
    }

    /**
     * Reject remote URLs and missing thumbnails.
     */
    public function test_plex_missing(): void
    {
        $client = $this->getClient('test_plex');

        foreach ([
            null,
            '',
            '/',
            '?X-Plex-Token=secret',
            'https://user:secret@plex.example.invalid/library/metadata/1/thumb',
            ' https://user:secret@plex.example.invalid/library/metadata/1/thumb ',
            '//plex.example.invalid/library/metadata/1/thumb',
            'data:image/png;base64,secret',
        ] as $thumb) {
            self::assertNull($this->getBackendPosterUrl($client, ['type' => iState::TYPE_MOVIE], ['thumb' => $thumb]));
        }

        self::assertNull($this->getBackendPosterUrl($client, [], ['thumb' => '/library/metadata/1/thumb']));
        self::assertSame(0, $this->http->getRequestsCount());
    }

    /**
     * Use remote item IDs, series IDs and configured URL prefixes.
     */
    public function test_mediabrowser_posters(): void
    {
        foreach (['jellyfin' => '/Items/', 'emby' => '/emby/Items/'] as $type => $path) {
            $name = 'test_' . $type;
            $client = $this->getClient($name, ['url' => 'https://user:password@media.example.invalid/prefix?api_key=secret']);

            foreach ([iState::TYPE_MOVIE, iState::TYPE_SHOW, iState::TYPE_EPISODE] as $itemType) {
                $id = iState::TYPE_EPISODE === $itemType ? 'remote-series' : 'remote-item';
                self::assertSame(
                    '/backend/' . $name . '/proxy' . $path . $id . '/Images/Primary/',
                    $this->getBackendPosterUrl($client, [
                        'id' => 999,
                        'type' => $itemType,
                        'via' => $name,
                        'metadata' => [$name => ['id' => 'remote-item', 'show' => 'remote-series']],
                    ]),
                );
            }

            self::assertSame(
                '/backend/' . $name . '/proxy' . $path . 'raw-series/Images/Primary/',
                $this->getBackendPosterUrl($client, ['type' => iState::TYPE_EPISODE], ['SeriesId' => 'raw-series']),
            );
            self::assertSame(
                '/backend/' . $name . '/proxy' . $path . 'raw-item/Images/Primary/',
                $this->getBackendPosterUrl($client, ['type' => iState::TYPE_MOVIE], ['Id' => 'raw-item']),
            );
            self::assertNull($this->getBackendPosterUrl($client, ['type' => iState::TYPE_MOVIE, 'id' => 999]));
            self::assertNull($this->getBackendPosterUrl($client, ['type' => iState::TYPE_EPISODE], ['Id' => 'episode']));
        }

        self::assertSame(0, $this->http->getRequestsCount());
    }

    /**
     * Encode backend names and image path characters once.
     */
    public function test_url_encoding(): void
    {
        $client = $this->getBasicClient('plex', DataUtil::fromArray([
            'name' => 'Plex & Family',
            'url' => 'https://plex.example.invalid',
            'token' => 'secret',
        ]));

        self::assertSame(
            '/backend/Plex%20%26%20Family/proxy/library/metadata/1/thumb/poster%20%23%20one',
            $this->getBackendPosterUrl(
                $client,
                ['type' => iState::TYPE_MOVIE],
                [
                    'thumb' => '/library/metadata/1/thumb/poster %23 one?token=secret',
                ],
            ),
        );
        self::assertSame(0, $this->http->getRequestsCount());
    }

    /**
     * Leave configured base paths for the proxy to add once.
     */
    public function test_plex_prefix(): void
    {
        $client = $this->getClient('test_plex', ['url' => 'https://plex.example.invalid/prefix']);

        foreach (['/library/metadata/1/thumb', '/prefix/library/metadata/1/thumb'] as $thumb) {
            self::assertSame(
                '/backend/test_plex/proxy/library/metadata/1/thumb',
                $this->getBackendPosterUrl($client, ['type' => iState::TYPE_MOVIE], ['thumb' => $thumb]),
            );
        }

        self::assertSame(
            '/backend/test_plex/proxy/prefix-other/library/metadata/1/thumb',
            $this->getBackendPosterUrl(
                $client,
                ['type' => iState::TYPE_MOVIE],
                [
                    'thumb' => '/prefix-other/library/metadata/1/thumb',
                ],
            ),
        );
        self::assertSame(0, $this->http->getRequestsCount());
    }
}
