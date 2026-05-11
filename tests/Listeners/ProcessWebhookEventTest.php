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
        $client->expects($this->once())->method('processRequest')->willReturnCallback($this->inspect(...));
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
        self::assertStringContainsString('Processing', implode("\n", $event->getLogs()));
    }

    public function test_tasks_processes(): void
    {
        $cache = Container::get(CacheInterface::class);
        $cache->set(TasksCommand::CACHE_NAME, true, new \DateInterval('PT6H'));
        $logger = new Logger('test');

        $client = $this->createMock(iClient::class);
        $client->expects($this->once())->method('processRequest')->willReturnCallback($this->inspect(...));
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
        self::assertStringContainsString('Processing', implode("\n", $event->getLogs()));
        self::assertStringNotContainsString('live: false', implode("\n", $event->getLogs()));
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

    private function event(string $requestId): DataEvent
    {
        return new DataEvent(new Event([
            EventsTable::COLUMN_ID => generate_uuid(),
            EventsTable::COLUMN_STATUS => EventStatus::RUNNING->value,
            EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME,
            EventsTable::COLUMN_EVENT_DATA => json_encode($this->data($requestId)),
            EventsTable::COLUMN_OPTIONS => json_encode([]),
            EventsTable::COLUMN_ATTEMPTS => 1,
            EventsTable::COLUMN_LOGS => json_encode([]),
            EventsTable::COLUMN_CREATED_AT => '2024-01-01 00:00:00',
            EventsTable::COLUMN_UPDATED_AT => '2024-01-01 00:00:01',
        ]));
    }

    private function data(string $requestId): array
    {
        $request = $this->getRequest(
            method: Method::POST,
            uri: '/v1/api/webhook',
            server: [
                'X_REQUEST_ID' => $requestId,
            ],
        );

        return [
            'server' => $request->getServerParams(),
            'get' => $request->getQueryParams(),
            'post' => $request->getParsedBody(),
            'cookie' => $request->getCookieParams(),
            'files' => [],
            'body' => (string) $request->getBody(),
        ];
    }

    private function movie(): StateEntity
    {
        return StateEntity::fromArray(require TESTS_PATH . '/Fixtures/MovieEntity.php');
    }
}
