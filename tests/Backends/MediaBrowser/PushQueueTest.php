<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Backends\Emby\Action\GetSessions as EmbyGetSessions;
use App\Backends\Emby\Action\Push as EmbyPush;
use App\Backends\Jellyfin\Action\GetSessions as JellyfinGetSessions;
use App\Backends\Jellyfin\Action\Push as JellyfinPush;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\QueueRequests;
use DateTimeImmutable;
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
            $this->assertStringContainsString('DatePlayed=', (string) $request->url);

            $followUps = ($request->success)(new MockResponse('', ['http_code' => 200]));
            $this->assertCount(1, $followUps);
            $this->assertContainsOnlyInstancesOf(Request::class, $followUps);
            $this->assertProgressResetRequest($clientName, $followUps[0]);
        }
    }

    public function test_push_event_date(): void
    {
        $payload = [
            'Id' => 'item-1',
            'UserData' => ['Played' => false],
            'DateCreated' => '1970-01-01T00:00:01Z',
        ];

        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
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
                iState::COLUMN_UPDATED => 1000,
                iState::COLUMN_UPDATED_AT => 2000,
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
            $result = $action($context, [$entity], $queue, new DateTimeImmutable('@1500'));

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_push_played_resets_progress(): void
    {
        $payload = [
            'Id' => 'item-1',
            'UserData' => [
                'Played' => true,
                'PlaybackPositionTicks' => 900000000,
                'LastPlayedDate' => '1970-01-01T00:00:01Z',
            ],
            'DateCreated' => '1970-01-01T00:00:01Z',
        ];

        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
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
                        iState::COLUMN_WATCHED => '1',
                        iState::COLUMN_TITLE => 'Test Movie',
                    ],
                ],
            ]);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(1, $queue->count());
            $this->assertContainsOnlyInstancesOf(Request::class, $queue->getQueue());
            $this->assertProgressResetRequest($clientName, $queue->getQueue()[0]);
        }
    }

    public function test_push_played_skips_active(): void
    {
        $payload = [
            'Id' => 'item-1',
            'UserData' => [
                'Played' => true,
                'PlaybackPositionTicks' => 900000000,
                'LastPlayedDate' => '1970-01-01T00:00:01Z',
            ],
            'DateCreated' => '1970-01-01T00:00:01Z',
        ];

        foreach ($this->provideBackends() as [$clientName, $actionClass, $sessionsClass]) {
            Container::add($sessionsClass, fn() => new class() {
                public function __invoke(): Response
                {
                    return new Response(status: true, response: [
                        'sessions' => [
                            [
                                'item_id' => 'item-1',
                                'item_offset_at' => 1000,
                                'user_id' => 'user-1',
                            ],
                        ],
                    ]);
                }
            });

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
                        iState::COLUMN_WATCHED => '1',
                        iState::COLUMN_TITLE => 'Test Movie',
                    ],
                ],
            ]);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    private function assertProgressResetRequest(string $clientName, Request $request): void
    {
        $this->assertSame('POST', $request->method->value);

        if ('Emby' === $clientName) {
            $this->assertStringContainsString('/Users/user-1/PlayingItems/item-1/Progress', (string) $request->url);
            $this->assertStringContainsString('PositionTicks=0', (string) $request->url);
            return;
        }

        $this->assertStringContainsString('/Users/user-1/Items/item-1/UserData', (string) $request->url);
        $this->assertSame(0, $request->options['json']['PlaybackPositionTicks']);
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinPush::class, JellyfinGetSessions::class],
            ['Emby',     EmbyPush::class,     EmbyGetSessions::class],
        ];
    }
}
