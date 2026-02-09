<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Push;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\QueueRequests;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushQueueTest extends PlexTestCase
{
    public function test_push_queues_state_change(): void
    {
        $payload = [
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'ratingKey' => '1',
                        'type' => 'movie',
                        'title' => 'Test Movie',
                        'viewCount' => 0,
                        'addedAt' => 1000,
                    ],
                ],
            ],
        ];

        $http = new HttpClient(new MockHttpClient(
            fn(string $method, string $url, array $options) => new MockResponse(
                json_encode($payload),
                [
                    'http_code' => 200,
                    'user_data' => $options['user_data'] ?? null,
                ],
            ),
        ));

        $context = $this->makeContext();
        $queue = new QueueRequests();

        $entity = StateEntity::fromArray([
            iState::COLUMN_ID => 10,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 2000,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => '1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => '0',
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
            ],
        ]);

        $action = new Push($http, $this->logger);
        $result = $action($context, [$entity], $queue);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $queue->count());
    }
}
