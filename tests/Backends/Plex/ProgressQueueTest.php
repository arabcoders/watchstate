<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\GetSessions;
use App\Backends\Plex\Action\Progress;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\QueueRequests;

class ProgressQueueTest extends PlexTestCase
{
    public function test_progress_queues_update(): void
    {
        Container::add(GetSessions::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['sessions' => []]);
            }
        });

        Container::add(GetMetaData::class, fn() => new class() {
            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: [
                    'MediaContainer' => [
                        'Metadata' => [
                            [
                                'ratingKey' => '1',
                                'type' => 'movie',
                                'title' => 'Test Movie',
                                'duration' => 100000,
                                'viewCount' => 0,
                                'addedAt' => 1000,
                                'Guid' => [
                                    ['id' => 'imdb://tt123'],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        });

        $context = $this->makeContext([Options::IGNORE_DATE => true]);
        $queue = new QueueRequests();

        $entity = StateEntity::fromArray([
            iState::COLUMN_ID => 10,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 2000,
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => 'Other',
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => '1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => '0',
                    iState::COLUMN_META_DATA_PROGRESS => '70000',
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
                'Other' => [
                    iState::COLUMN_META_DATA_PROGRESS => '70000',
                    iState::COLUMN_WATCHED => '0',
                ],
            ],
            iState::COLUMN_EXTRA => [
                $context->backendName => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-02T00:00:00Z',
                ],
                'Other' => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-01T00:00:00Z',
                ],
            ],
        ]);

        $http = new \App\Libs\Extends\HttpClient(
            new \App\Libs\Extends\MockHttpClient(
                new \Symfony\Component\HttpClient\Response\MockResponse('ok', ['http_code' => 200]),
            ),
        );
        $action = new Progress($http, $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);
        $result = $action($context, $guid, [$entity], $queue);

        $message = $result->error?->format() ?? '';
        $this->assertTrue($result->isSuccessful(), $message);
        $this->assertSame(1, $queue->count());
    }
}
