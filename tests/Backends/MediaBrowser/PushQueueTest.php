<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Push as EmbyPush;
use App\Backends\Jellyfin\Action\Push as JellyfinPush;
use App\Backends\Common\Request;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\QueueRequests;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushQueueTest extends MediaBrowserTestCase
{
    public function test_push_queues_updates(): void
    {
        $payload = [
            'Id' => 'item-1',
            'UserData' => ['Played' => false],
            'DateCreated' => '1970-01-01T00:00:01Z',
        ];

        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $this->handler?->clear();
            $http = new HttpClient(new MockHttpClient(
                fn(string $method, string $url, array $options) => new MockResponse(
                    json_encode($payload),
                    [
                        'http_code' => 200,
                        'user_data' => $options['user_data'] ?? null,
                    ],
                ),
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
            $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());

            $request = $queue->getQueue()[0];
            $this->assertSame('POST', $request->method->value);
            $this->assertStringContainsString('/Users/user-1/PlayedItems/item-1', (string) $request->url);

            $followUps = ($request->success)(new MockResponse('', ['http_code' => 200]));
            $this->assertCount(1, $followUps);
            $this->assertContainsOnlyInstancesOf(Request::class, $followUps);
            $this->assertStringContainsString('/Users/user-1/Items/item-1/UserData', (string) $followUps[0]->url);

            $records = $this->handler?->getRecords() ?? [];
            $start = array_filter(
                $records,
                static fn($record): bool => 'backend.request.started' === ($record->context['event_name'] ?? null)
                    && 'backend.push' === ($record->context['subsystem'] ?? null)
                    && 'update_state' === ($record->context['operation'] ?? null),
            );
            $completed = array_filter(
                $records,
                static fn($record): bool => 'backend.state_update.completed' === ($record->context['event_name'] ?? null)
                    && 'backend.push' === ($record->context['subsystem'] ?? null)
                    && 'update_state' === ($record->context['operation'] ?? null),
            );

            $this->assertNotEmpty($start);
            $this->assertNotEmpty($completed);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinPush::class],
            ['Emby', EmbyPush::class],
        ];
    }
}
