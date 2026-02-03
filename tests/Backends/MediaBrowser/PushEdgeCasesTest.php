<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Push as EmbyPush;
use App\Backends\Jellyfin\Action\Push as JellyfinPush;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\QueueRequests;
use Symfony\Component\HttpClient\Response\MockResponse;

class PushEdgeCasesTest extends MediaBrowserTestCase
{
    public function test_push_skips_missing_metadata(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $entity = StateEntity::fromArray([
                iState::COLUMN_ID => 10,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_UPDATED => 2000,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_VIA => $context->backendName,
                iState::COLUMN_TITLE => 'Test Movie',
                iState::COLUMN_META_DATA => [],
            ]);

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_push_skips_identical_state(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $entity = $this->makeEntity($context, watched: 1);
            $payload = $this->fixture('metadata');
            $payload['UserData']['Played'] = true;

            $action = new $actionClass($this->makeHttpWithPayload($payload), $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_push_skips_missing_date(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $entity = $this->makeEntity($context, watched: 0);
            $payload = $this->fixture('metadata');
            $payload['UserData']['Played'] = true;
            unset($payload['UserData']['LastPlayedDate']);

            $action = new $actionClass($this->makeHttpWithPayload($payload), $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_push_skips_backend_date_newer(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $entity = $this->makeEntity($context, watched: 0, updated: 1000);
            $payload = $this->fixture('metadata');
            $payload['UserData']['Played'] = true;
            $payload['UserData']['LastPlayedDate'] = '2024-01-02T00:00:00Z';

            $action = new $actionClass($this->makeHttpWithPayload($payload), $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_push_skips_not_found_metadata(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $entity = $this->makeEntity($context, watched: 0);
            $http = new HttpClient(new MockHttpClient(
                fn(string $method, string $url, array $options) => new MockResponse('', [
                    'http_code' => 404,
                    'user_data' => $options['user_data'] ?? null,
                ]),
            ));

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    private function makeEntity(\App\Backends\Common\Context $context, int $watched, int $updated = 2000): StateEntity
    {
        return StateEntity::fromArray([
            iState::COLUMN_ID => 10,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => $updated,
            iState::COLUMN_WATCHED => $watched,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => 'item-1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => (string) $watched,
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
            ],
        ]);
    }

    private function makeHttpWithPayload(array $payload): HttpClient
    {
        return new HttpClient(new MockHttpClient(
            fn(string $method, string $url, array $options) => new MockResponse(
                json_encode($payload),
                [
                    'http_code' => 200,
                    'user_data' => $options['user_data'] ?? null,
                ],
            ),
        ));
    }

    private function makeQueueHttp(): HttpClient
    {
        return new HttpClient(new MockHttpClient(
            fn(string $method, string $url, array $options) => new MockResponse('', [
                'http_code' => 200,
                'user_data' => $options['user_data'] ?? null,
            ]),
        ));
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinPush::class],
            ['Emby', EmbyPush::class],
        ];
    }
}
