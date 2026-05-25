<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\ToEntity;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\PlexGuid;
use App\Libs\Config;
use App\Libs\Entity\StateInterface;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Guid;

class ToEntityTest extends PlexTestCase
{
    public function test_to_entity_success(): void
    {
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $context = $this->makeContext();

        $action = new ToEntity(new PlexGuid($this->logger));
        $result = $action($context, $item);

        $this->assertTrue($result->isSuccessful());
        $this->assertInstanceOf(StateInterface::class, $result->response);
        $this->assertSame('Ferengi: Rules of Acquisition', $result->response->title);
    }

    public function test_to_entity_episode_parent(): void
    {
        $context = $this->makeContext();
        $item = [
            'ratingKey' => '11',
            'type' => 'episode',
            'title' => 'Pilot',
            'grandparentTitle' => 'Test Show',
            'parentIndex' => 1,
            'index' => 1,
            'addedAt' => 1000,
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
            'grandparentRatingKey' => 'show-1',
        ];

        $showPayload = [
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'ratingKey' => 'show-1',
                        'type' => 'show',
                        'title' => 'Test Show',
                        'Guid' => [
                            ['id' => 'imdb://tt123'],
                        ],
                        'guid' => 'imdb://tt123',
                    ],
                ],
            ],
        ];

        Container::add(GetMetaData::class, fn() => new class($showPayload) {
            public function __construct(private array $payload)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: $this->payload);
            }
        });

        $action = new ToEntity(new PlexGuid($this->logger));
        $result = $action($context, $item);

        $this->assertTrue($result->isSuccessful());
        $this->assertInstanceOf(StateInterface::class, $result->response);
        $this->assertNotEmpty($result->response->parent);
    }

    public function test_to_entity_toplevel_nfo(): void
    {
        $context = $this->makeContext();
        $item = [
            'ratingKey' => '42',
            'type' => 'movie',
            'title' => 'NFO Movie',
            'addedAt' => 1000,
            'guid' => 'tv.plex.agents.nfo.movie://movie/tmdb_383498',
        ];

        $action = new ToEntity(new PlexGuid($this->logger));
        $result = $action($context, $item);

        $this->assertTrue($result->isSuccessful());
        $this->assertInstanceOf(StateInterface::class, $result->response);
        $this->assertSame('383498', $result->response->guids['guid_tmdb'] ?? null);
    }

    public function test_to_entity_showlevel_nfo(): void
    {
        $context = $this->makeContext();
        $item = [
            'ratingKey' => '11',
            'type' => 'episode',
            'title' => 'Pilot',
            'grandparentTitle' => 'Test Show',
            'parentIndex' => 1,
            'index' => 1,
            'addedAt' => 1000,
            'Guid' => [
                ['id' => 'tvdb://84871'],
            ],
            'grandparentRatingKey' => 'show-1',
        ];

        $showPayload = [
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'ratingKey' => 'show-1',
                        'type' => 'show',
                        'title' => 'Test Show',
                        'guid' => 'tv.plex.agents.nfo.series://show/tvdb_72408',
                    ],
                ],
            ],
        ];

        Container::add(GetMetaData::class, fn() => new class($showPayload) {
            public function __construct(private array $payload)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: $this->payload);
            }
        });

        $action = new ToEntity(new PlexGuid($this->logger));
        $result = $action($context, $item);

        $this->assertTrue($result->isSuccessful());
        $this->assertInstanceOf(StateInterface::class, $result->response);
        $this->assertSame('72408', $result->response->parent['guid_tvdb'] ?? null);
    }

    public function test_to_entity_path_guid(): void
    {
        Config::save('guid.path.enabled', true);

        try {
            $context = $this->makeContext();
            $action = new ToEntity(new PlexGuid($this->logger));

            $movie = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
            $movieResult = $action($context, $movie);

            $this->assertTrue($movieResult->isSuccessful());
            $this->assertSame(
                md5('movie:/ferengi rules of acquisition (2000)/ferengi rules of acquisition (2000).mp4'),
                $movieResult->response->guids[Guid::GUID_PATH] ?? null,
            );

            $episode = [
                'ratingKey' => '12',
                'type' => 'episode',
                'title' => 'Pilot',
                'grandparentTitle' => 'Show Title',
                'parentIndex' => 1,
                'index' => 1,
                'addedAt' => 1000,
                'Media' => [
                    [
                        'Part' => [
                            ['file' => '/media/tv/Show_Title/Season 01/S01E01.MKV'],
                        ],
                    ],
                ],
            ];
            $episodeResult = $action($context, $episode);

            $this->assertTrue($episodeResult->isSuccessful());
            $this->assertSame(
                md5('episode:/show_title/season 01/s01e01.mkv/1/1'),
                $episodeResult->response->guids[Guid::GUID_PATH] ?? null,
            );
            $this->assertSame(
                md5('show:/show_title/season 01'),
                $episodeResult->response->parent[Guid::GUID_PATH] ?? null,
            );
        } finally {
            Config::save('guid.path.enabled', false);
        }
    }
}
