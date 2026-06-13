<?php

declare(strict_types=1);

namespace Tests\API\Webhook;

use App\API\System\Command as SystemCommand;
use App\API\WebHook;
use App\Commands\System\TasksCommand;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Enums\Http\Method;
use App\Libs\Events\EventQueue;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Listeners\ProcessWebhookEvent;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface;
use Tests\Support\RequestResponseTrait;

final class IndexTest extends TestCase
{
    use RequestResponseTrait;

    private EventsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $cache = Container::get(CacheInterface::class);
        $db = $this->createDb();

        Container::add(iDB::class, $db);
        Container::add(EventsRepository::class, $this->repo = new EventsRepository($db->getDBLayer()));
        Container::add(EventQueue::class, new EventQueue($cache, $this->repo));
    }

    public function test_ready(): void
    {
        $cache = Container::get(CacheInterface::class);
        $response = (new WebHook($cache))($this->getRequest(method: Method::GET, uri: '/v1/api/webhook'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_queue(): void
    {
        $cache = Container::get(CacheInterface::class);

        $response = (new Webhook($cache))($this->getWebhookRequest('req-1'));

        self::assertSame(200, $response->getStatusCode());

        $events = $this->repo->findAll([EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME]);

        self::assertCount(1, $events);
        self::assertSame(['keep' => '1'], $events[0]->event_data['get']);
        self::assertSame(['payload' => 'ok'], $events[0]->event_data['post']);
        self::assertSame('', $events[0]->event_data['body']);
        self::assertSame('req-1', $events[0]->event_data['server']['X_REQUEST_ID']);
        self::assertArrayNotHasKey('HTTP_AUTHORIZATION', $events[0]->event_data['server']);
        self::assertArrayNotHasKey('WS_API_KEY', $events[0]->event_data['server']);
        self::assertArrayNotHasKey('WS_BACKENDS_FILE', $events[0]->event_data['server']);
        self::assertStringNotContainsString('apikey', $events[0]->event_data['server']['REQUEST_URI']);
        self::assertStringNotContainsString('ws_token', $events[0]->event_data['server']['REQUEST_URI']);
        self::assertSame('keep=1', $events[0]->event_data['server']['QUERY_STRING']);
        self::assertTrue($events[0]->options[Options::FAIL_FAST_ON_LOCK]);
        self::assertSame([], $cache->get('events', []));
    }

    public function test_raw_queue(): void
    {
        $cache = Container::get(CacheInterface::class);

        $response = (new WebHook($cache))($this->getWebhookRequest('req-1')->withParsedBody(null));

        self::assertSame(200, $response->getStatusCode());

        $events = $this->repo->findAll([EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME]);

        self::assertCount(1, $events);
        self::assertArrayNotHasKey('post', $events[0]->event_data);
        self::assertSame('raw-body', $events[0]->event_data['body']);
    }

    public function test_cache_during_tasks(): void
    {
        $cache = Container::get(CacheInterface::class);
        $cache->set(TasksCommand::CACHE_NAME, true, new \DateInterval('PT6H'));

        $response = (new WebHook($cache))($this->getWebhookRequest('req-1'));

        self::assertSame(200, $response->getStatusCode());

        $events = $cache->get('events', []);

        self::assertCount(1, $events);
        self::assertSame(ProcessWebhookEvent::NAME, $events[0]['event']);
        self::assertSame(['payload' => 'ok'], $events[0]['data']['post']);
        self::assertSame('', $events[0]['data']['body']);
        self::assertArrayNotHasKey('WS_API_KEY', $events[0]['data']['server']);
        self::assertTrue($events[0]['opts']['cached']);
        self::assertTrue($events[0]['opts'][Options::FAIL_FAST_ON_LOCK]);
        self::assertArrayNotHasKey(Options::CACHE_ONLY, $events[0]['opts']);
        self::assertSame([], $this->repo->findAll([EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME]));
    }

    public function test_cache_during_command(): void
    {
        $cache = Container::get(CacheInterface::class);
        $cache->set(SystemCommand::CACHE_NAME, true, new \DateInterval('PT6H'));

        $response = (new WebHook($cache))($this->getWebhookRequest('req-1'));

        self::assertSame(200, $response->getStatusCode());

        $events = $cache->get('events', []);

        self::assertCount(1, $events);
        self::assertSame(ProcessWebhookEvent::NAME, $events[0]['event']);
        self::assertSame(['payload' => 'ok'], $events[0]['data']['post']);
        self::assertTrue($events[0]['opts']['cached']);
        self::assertTrue($events[0]['opts'][Options::FAIL_FAST_ON_LOCK]);
        self::assertArrayNotHasKey(Options::CACHE_ONLY, $events[0]['opts']);
        self::assertSame([], $this->repo->findAll([EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME]));
    }

    private function getWebhookRequest(string $requestId): iRequest
    {
        return $this->getRequest(
            method: Method::POST,
            uri: '/v1/api/webhook?apikey=secret&ws_token=token&keep=1',
            post: ['payload' => 'ok'],
            query: [
                'apikey' => 'secret',
                'ws_token' => 'token',
                'keep' => '1',
            ],
            server: [
                'X_REQUEST_ID' => $requestId,
                'HTTP_AUTHORIZATION' => 'Bearer secret',
                'QUERY_STRING' => 'apikey=secret&ws_token=token&keep=1',
                'WS_API_KEY' => 'secret',
                'WS_BACKENDS_FILE' => '/config/servers.yaml',
            ],
            body: new Psr17Factory()->createStream('raw-body'),
        );
    }
}
