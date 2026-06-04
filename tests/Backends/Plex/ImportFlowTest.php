<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\Import;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use ReflectionMethod;

class ImportFlowTest extends PlexTestCase
{
    public function test_import_process_adds_items(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['movie']['added']);
    }

    public function test_import_ignores_missing_date(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        unset($item['addedAt'], $item['lastViewedAt']);

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(0, $result['movie']['added']);
    }

    public function test_import_episode_adds(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;

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
            public function __construct(
                private array $payload,
            ) {}

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: $this->payload);
            }
        });

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['episode']['added']);
    }

    public function test_import_prefetched_genres(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;

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

        $show = [
            'ratingKey' => 'show-1',
            'type' => 'show',
            'title' => 'Test Show',
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
            'guid' => 'imdb://tt123',
            'Genre' => [
                ['tag' => 'Drama'],
                ['tag' => 'Sci-Fi'],
            ],
        ];

        $metaAction = new class($show) {
            public function __construct(
                private array $payload,
            ) {}

            public int $calls = 0;

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                $this->calls++;

                return new Response(
                    status: true,
                    response: ['MediaContainer' => ['Metadata' => [$this->payload]]],
                );
            }
        };

        Container::add(GetMetaData::class, fn() => $metaAction);

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcessShow($action, $context, $guid, $show, []);
        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(1, $result['episode']['added']);
        $this->assertSame(0, $metaAction->calls);
    }

    public function test_import_ignores_no_guids(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $item['Guid'] = [];
        $item['guid'] = 'plex://local';

        $action = new Import($this->makeHttpClient(), $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            [],
        );

        $result = $mapper->commit();

        $this->assertSame(0, $result['movie']['added']);
    }

    private function invokeProcess(
        object $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        \App\Libs\Mappers\ImportInterface $mapper,
        array $item,
        array $logContext,
        array $opts,
    ): void {
        $method = new ReflectionMethod($action, 'process');
        $method->invoke($action, $context, $guid, $mapper, $item, $logContext, $opts);
    }

    private function invokeProcessShow(
        object $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        array $item,
        array $logContext,
    ): void {
        $method = new ReflectionMethod($action, 'processShow');
        $method->invoke($action, $context, $guid, $item, $logContext);
    }
}
