<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\ToEntity;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\PlexGuid;
use App\Libs\Entity\StateInterface;
use App\Backends\Common\Response;
use App\Libs\Container;

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
}
