<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Context;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\GetSessions;
use App\Backends\Plex\Action\Progress;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Symfony\Component\HttpClient\Response\MockResponse;

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
            public function __invoke(Context $context, string|int $id, array $opts = []): Response
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

        $http = new HttpClient(
            new MockHttpClient(
                new MockResponse('ok', ['http_code' => 200]),
            ),
        );
        $action = new Progress($http, $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);
        $result = $action($context, $guid, [$entity], $queue);

        $message = $result->error?->format() ?? '';
        $this->assertTrue($result->isSuccessful(), $message);
        $this->assertSame(1, $queue->count());
        $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());
    }

    public function test_progress_unwatches_remote(): void
    {
        Container::add(GetSessions::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['sessions' => []]);
            }
        });

        Container::add(GetMetaData::class, fn() => new class() {
            public function __invoke(Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: [
                    'MediaContainer' => [
                        'Metadata' => [
                            [
                                'ratingKey' => '1',
                                'type' => 'movie',
                                'title' => 'Test Movie',
                                'duration' => 100000,
                                'viewCount' => 1,
                                'addedAt' => 1000,
                                'lastViewedAt' => 2000,
                                'Guid' => [
                                    ['id' => 'imdb://tt123'],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        });

        $context = $this->makeContext([Options::IGNORE_DATE => true, Options::REPLAY_PROGRESS => true]);
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
                    iState::COLUMN_WATCHED => '1',
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

        $http = new HttpClient(
            new MockHttpClient(
                new MockResponse('ok', ['http_code' => 200]),
            ),
        );
        $action = new Progress($http, $this->logger);
        $guid = new PlexGuid($this->logger)->withContext($context);
        $result = $action($context, $guid, [$entity], $queue);

        $message = $result->error?->format() ?? '';
        $this->assertTrue($result->isSuccessful(), $message);
        $this->assertSame(1, $queue->count());

        $request = $queue->getQueue()[0];
        $this->assertSame('GET', $request->method->value);
        $this->assertStringContainsString('/:/unscrobble', (string) $request->url);

        $followUps = ($request->success)(new MockResponse('', ['http_code' => 200]));
        $this->assertCount(1, $followUps);
        $this->assertContainsOnlyInstancesOf(Request::class, $followUps);
        $this->assertSame('POST', $followUps[0]->method->value);
        $this->assertStringContainsString('/:/timeline/', (string) $followUps[0]->url);
        $this->assertStringContainsString('time=70000', (string) $followUps[0]->url);
    }
}
