<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Common\Response;
use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Emby\Action\GetSessions as EmbyGetSessions;
use App\Backends\Emby\Action\Progress as EmbyProgress;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use App\Backends\Jellyfin\Action\GetSessions as JellyfinGetSessions;
use App\Backends\Jellyfin\Action\Progress as JellyfinProgress;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Response\MockResponse;

class ProgressSkipTest extends MediaBrowserTestCase
{
    public function test_progress_skips_same_backend_origin(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass, $sessionsClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            Container::add($sessionsClass, fn() => new class() {
                public function __invoke(): Response
                {
                    return new Response(status: true, response: ['sessions' => []]);
                }
            });

            $entity = $this->makeEntity($context, via: $context->backendName);

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);
            $result = $action($context, $guid, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_progress_skips_missing_metadata(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass, $sessionsClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            Container::add($sessionsClass, fn() => new class() {
                public function __invoke(): Response
                {
                    return new Response(status: true, response: ['sessions' => []]);
                }
            });

            $entity = StateEntity::fromArray([
                iState::COLUMN_ID => 10,
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_UPDATED => 2000,
                iState::COLUMN_WATCHED => 0,
                iState::COLUMN_VIA => 'Other',
                iState::COLUMN_TITLE => 'Test Movie',
                iState::COLUMN_META_DATA => [],
            ]);

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);
            $result = $action($context, $guid, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_progress_skips_missing_sender_date(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass, $sessionsClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            Container::add($sessionsClass, fn() => new class() {
                public function __invoke(): Response
                {
                    return new Response(status: true, response: ['sessions' => []]);
                }
            });

            $entity = $this->makeEntity($context, extra: [
                $context->backendName => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-02T00:00:00Z',
                ],
            ]);

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);
            $result = $action($context, $guid, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_progress_skips_local_date_newer(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass, $sessionsClass]) {
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            Container::add($sessionsClass, fn() => new class() {
                public function __invoke(): Response
                {
                    return new Response(status: true, response: ['sessions' => []]);
                }
            });

            $entity = $this->makeEntity($context, extra: [
                'Other' => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-01T00:00:00Z',
                ],
                $context->backendName => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-03T00:00:00Z',
                ],
            ]);

            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);
            $result = $action($context, $guid, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_progress_skips_active_session(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass, $sessionsClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
            $cache = new Psr16Cache(new ArrayAdapter());

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

            $metaResponse = new MockResponse(
                json_encode($this->fixture('metadata')),
                ['http_code' => 200],
            );
            $metaHttp = new HttpClient(new MockHttpClient($metaResponse));
            Container::add($metaClass, fn() => new $metaClass($metaHttp, $this->logger, $cache));

            $entity = $this->makeEntity($context, extra: [
                'Other' => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-01T00:00:00Z',
                ],
                $context->backendName => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-02T00:00:00Z',
                ],
            ]);

            $queue = new QueueRequests();
            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);
            $result = $action($context, $guid, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    public function test_progress_skips_remote_watched(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass, $sessionsClass]) {
            $context = $this->makeContext($clientName, [Options::IGNORE_DATE => true]);
            $cache = new Psr16Cache(new ArrayAdapter());

            Container::add($sessionsClass, fn() => new class() {
                public function __invoke(): Response
                {
                    return new Response(status: true, response: ['sessions' => []]);
                }
            });

            $payload = $this->fixture('metadata');
            $payload['UserData']['Played'] = true;
            $payload['UserData']['PlayCount'] = 1;
            $payload['UserData']['PlaybackPositionTicks'] = 0;
            $payload['UserData']['LastPlayedDate'] = '2024-01-02T00:00:00Z';

            $metaResponse = new MockResponse(json_encode($payload), ['http_code' => 200]);
            $metaHttp = new HttpClient(new MockHttpClient($metaResponse));
            Container::add($metaClass, fn() => new $metaClass($metaHttp, $this->logger, $cache));

            $entity = $this->makeEntity($context, extra: [
                'Other' => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-01T00:00:00Z',
                ],
                $context->backendName => [
                    iState::COLUMN_EXTRA_DATE => '2024-01-02T00:00:00Z',
                ],
            ]);

            $queue = new QueueRequests();
            $action = new $actionClass($this->makeQueueHttp(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);
            $result = $action($context, $guid, [$entity], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    private function makeEntity(\App\Backends\Common\Context $context, string $via = 'Other', array $extra = []): StateEntity
    {
        return StateEntity::fromArray([
            iState::COLUMN_ID => 10,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 2000,
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => $via,
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => 'item-1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => '0',
                    iState::COLUMN_META_DATA_PROGRESS => '70000',
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
            ],
            iState::COLUMN_EXTRA => $extra,
        ]);
    }

    private function makeQueueHttp(): HttpClient
    {
        return new HttpClient(new MockHttpClient(
            fn(string $method, string $url, array $options) => new MockResponse('ok', [
                'http_code' => 200,
                'user_data' => $options['user_data'] ?? null,
            ]),
        ));
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinProgress::class, JellyfinGuid::class, JellyfinGetMetaData::class, JellyfinGetSessions::class],
            ['Emby', EmbyProgress::class, EmbyGuid::class, EmbyGetMetaData::class, EmbyGetSessions::class],
        ];
    }
}
