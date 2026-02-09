<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\UpdateState as EmbyUpdateState;
use App\Backends\Jellyfin\Action\UpdateState as JellyfinUpdateState;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\QueueRequests;
use Symfony\Component\HttpClient\Response\MockResponse;

class UpdateStateQueueTest extends MediaBrowserTestCase
{
    public function test_update_state_queues_request(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $http = new HttpClient(new MockHttpClient(
                fn(string $method, string $url, array $options) => new MockResponse('', [
                    'http_code' => 204,
                    'user_data' => $options['user_data'] ?? null,
                ]),
            ));
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $entity = StateEntity::fromArray([
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_UPDATED => 2000,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_VIA => $context->backendName,
                iState::COLUMN_TITLE => 'Test Movie',
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_ID => 'item-1',
                        iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                        iState::COLUMN_WATCHED => '0',
                        iState::COLUMN_TITLE => 'Test Movie',
                    ],
                ],
            ]);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(1, $queue->count());
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinUpdateState::class],
            ['Emby', EmbyUpdateState::class],
        ];
    }
}
