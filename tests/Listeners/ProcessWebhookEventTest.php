<?php

declare(strict_types=1);

namespace Tests\Listeners;

use App\Backends\Common\ClientInterface as iClient;
use App\Commands\System\TasksCommand;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Enums\Http\Method;
use App\Libs\Events\DataEvent;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use App\Listeners\ProcessWebhookEvent;
use App\Model\Events\Event;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeBackendClient;
use Tests\Support\RequestResponseTrait;

final class ProcessWebhookEventTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->seedTestServersConfig();
        $db = $this->createDb();

        Container::add(iDB::class, $db);

        Config::save('supported.plex', iClient::class);
        Config::save('supported.jellyfin', iClient::class);
        Config::save('supported.emby', iClient::class);
    }

    public function test_processes(): void
    {
        $cache = Container::get(CacheInterface::class);
        $logger = new Logger('test');

        $client = $this->createMock(iClient::class);
        $client
            ->expects($this->once())
            ->method('processRequest')
            ->willReturnCallback($this->inspect(...));
        $client->expects($this->once())->method('parseWebhook')->willReturn($this->movie());
        $client->method('withContext')->willReturnSelf();
        $client->method('setLogger')->willReturnSelf();
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        Container::add(iClient::class, $client);

        $listener = new ProcessWebhookEvent(new DirectMapper($logger, Container::get(iDB::class), $cache), $logger);
        $event = $this->event('req-1');

        $listener($event);

        $events = $cache->get('events', []);

        self::assertSame([], $events);
        self::assertSame(EventStatus::RUNNING, $event->getStatus());
    }

    public function test_tasks_processes(): void
    {
        $cache = Container::get(CacheInterface::class);
        $cache->set(TasksCommand::CACHE_NAME, true, new \DateInterval('PT6H'));
        $logger = new Logger('test');

        $client = $this->createMock(iClient::class);
        $client
            ->expects($this->once())
            ->method('processRequest')
            ->willReturnCallback($this->inspect(...));
        $client->expects($this->once())->method('parseWebhook')->willReturn($this->movie());
        $client->method('withContext')->willReturnSelf();
        $client->method('setLogger')->willReturnSelf();
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        Container::add(iClient::class, $client);

        $listener = new ProcessWebhookEvent(new DirectMapper($logger, Container::get(iDB::class), $cache), $logger);
        $event = $this->event('req-1');

        $listener($event);

        $events = $cache->get('events', []);

        self::assertSame([], $events);
        self::assertSame(EventStatus::RUNNING, $event->getStatus());
    }

    public function test_raw_body(): void
    {
        $cache = Container::get(CacheInterface::class);
        $logger = new Logger('test');

        $client = $this->createMock(iClient::class);
        $client
            ->expects($this->once())
            ->method('processRequest')
            ->willReturnCallback(function (iRequest $request): iRequest {
                self::assertNull($request->getParsedBody());
                self::assertSame('raw-body', (string) $request->getBody());

                return $this->inspect($request);
            });
        $client->expects($this->once())->method('parseWebhook')->willReturn($this->movie());
        $client->method('withContext')->willReturnSelf();
        $client->method('setLogger')->willReturnSelf();
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        Container::add(iClient::class, $client);

        $listener = new ProcessWebhookEvent(new DirectMapper($logger, Container::get(iDB::class), $cache), $logger);
        $event = $this->event('req-1', null, 'raw-body');

        $listener($event);
    }

    public function test_disabled_import_means_metadata_only(): void
    {
        $userContext = get_user_context(
            'main',
            new DirectMapper(new Logger('setup'), Container::get(iDB::class), Container::get(CacheInterface::class)),
            new Logger('setup'),
        );
        $userContext->config->set('test_plex.import.enabled', false);
        $userContext->config->set('test_plex.options.IMPORT_METADATA_ONLY', true)->persist();

        $cache = Container::get(CacheInterface::class);
        $logger = new Logger('test');

        $client = $this->createMock(iClient::class);
        $client
            ->expects($this->once())
            ->method('processRequest')
            ->willReturnCallback($this->inspect(...));
        $client->expects($this->once())->method('parseWebhook')->willReturn($this->movie());
        $client->method('withContext')->willReturnSelf();
        $client->method('setLogger')->willReturnSelf();
        $client->method('getName')->willReturn('test_plex');
        $client->method('getType')->willReturn('plex');

        Container::add(iClient::class, $client);

        $listener = new ProcessWebhookEvent(new DirectMapper($logger, Container::get(iDB::class), $cache), $logger);
        $event = $this->event('req-2');

        $listener($event);
    }

    public function test_orders_full_before_metadata(): void
    {
        Config::save('supported.plex', FakeBackendClient::class);
        FakeBackendClient::reset();

        file_put_contents(
            (string) Config::get('backends_file'),
            Yaml::dump(
                [
                    'metadata_first' => [
                        'type' => 'plex',
                        'url' => 'https://example.invalid',
                        'token' => 'token-1',
                        'user' => '11111111',
                        'uuid' => 's00000000000000000000000000000000000000p',
                        'import' => [
                            'enabled' => false,
                        ],
                        'export' => [
                            'enabled' => true,
                        ],
                        'options' => [],
                    ],
                    'full_second' => [
                        'type' => 'plex',
                        'url' => 'https://example.invalid',
                        'token' => 'token-2',
                        'user' => '11111111',
                        'uuid' => 's00000000000000000000000000000000000000p',
                        'import' => [
                            'enabled' => true,
                        ],
                        'export' => [
                            'enabled' => true,
                        ],
                        'options' => [],
                    ],
                ],
                8,
                2,
            ),
        );

        $cache = Container::get(CacheInterface::class);
        $logger = new Logger('test');

        $listener = new ProcessWebhookEvent(new DirectMapper($logger, Container::get(iDB::class), $cache), $logger);
        $event = $this->event('req-3');

        $listener($event);

        $processingBackends = [];
        foreach ($event->getLogs() as $log) {
            if (false === str_contains($log, "Processing 'main@")) {
                continue;
            }

            $processingBackends[] = before(after($log, "Processing 'main@"), "' request");
        }

        self::assertSame(['full_second', 'metadata_first'], $processingBackends);
    }

    private function inspect(iRequest $request): iRequest
    {
        return $request
            ->withAttribute('backend', [
                'id' => 's00000000000000000000000000000000000000p',
                'name' => 'test_plex',
            ])
            ->withAttribute('user', [
                'id' => '11111111',
                'name' => 'main',
            ]);
    }

    private function event(string $requestId, ?array $post = [], string $body = ''): DataEvent
    {
        return new DataEvent(new Event([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::RUNNING->value,
            EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME,
            EventsTable::COLUMN_EVENT_DATA => json_encode($this->data($requestId, $post, $body)),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 1,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 00:00:00',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-01 00:00:01',
        ]));
    }

    private function data(string $requestId, ?array $post, string $body): array
    {
        $request = $this->getRequest(
            method: Method::POST,
            uri: '/v1/api/webhook',
            server: [
                'X_REQUEST_ID' => $requestId,
            ],
        );

        $data = [
            'server' => $request->getServerParams(),
            'get' => $request->getQueryParams(),
            'cookie' => $request->getCookieParams(),
            'files' => [],
            'body' => $body,
        ];

        if (null !== $post) {
            $data['post'] = $post;
        }

        return $data;
    }

    private function movie(): StateEntity
    {
        return StateEntity::fromArray(require TESTS_PATH . '/Fixtures/MovieEntity.php');
    }
}
