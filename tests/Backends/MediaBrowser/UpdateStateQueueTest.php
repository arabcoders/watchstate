<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\UpdateState as EmbyUpdateState;
use App\Backends\Jellyfin\Action\UpdateState as JellyfinUpdateState;
use App\Backends\Common\Request;
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
            $this->handler?->clear();
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
                        iState::COLUMN_META_DATA_ADDED_AT => '1000',
                    ],
                ],
            ]);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(1, $queue->count());
            $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());

            $request = $queue->getQueue()[0];
            ($request->success)(new MockResponse('', ['http_code' => 200]));

            $records = $this->handler?->getRecords() ?? [];
            $started = array_filter(
                $records,
                static fn($record): bool => 'backend.request.started' === ($record->context['event_name'] ?? null)
                    && 'backend.restore' === ($record->context['subsystem'] ?? null)
                    && 'update_state' === ($record->context['operation'] ?? null),
            );
            $completed = array_filter(
                $records,
                static fn($record): bool => 'backend.state_update.completed' === ($record->context['event_name'] ?? null)
                    && 'backend.restore' === ($record->context['subsystem'] ?? null)
                    && 'update_state' === ($record->context['operation'] ?? null),
            );

            $this->assertNotEmpty($started);
            $this->assertNotEmpty($completed);

            $record = end($completed);
            $this->assertSame((string) make_date(2000), $record->context['local_time'] ?? null);
            $this->assertSame((string) make_date(1000), $record->context['remote_time'] ?? null);
            $this->assertSame(1000, $record->context['diff_time'] ?? null);
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
