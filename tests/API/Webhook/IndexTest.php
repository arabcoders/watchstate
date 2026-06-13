<?php

declare(strict_types=1);

namespace Tests\API\Webhook;

use App\API\WebHook;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Enums\Http\Method;
use App\Libs\Events\EventQueue;
use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\EventTransportInterface;
use App\Libs\Events\Queue\FilesystemEventTransport;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Listeners\ProcessWebhookEvent;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Tests\Support\RequestResponseTrait;

final class IndexTest extends TestCase
{
    use RequestResponseTrait;

    private EventsRepository $repo;
    private FilesystemEventTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $db = $this->createDb();
        Config::save('events.queue.driver', 'file');
        Config::save('events.queue.path', self::$tmpPath . '/queue/events');
        $this->transport = new FilesystemEventTransport(self::$tmpPath . '/queue/events');

        Container::add(iDB::class, $db);
        Container::add(EventsRepository::class, $this->repo = new EventsRepository($db->getDBLayer()));
        Container::add(EventTransportInterface::class, $this->transport);
        Container::add(EventQueue::class, new EventQueue($this->transport, $this->repo));
    }

    public function test_ready(): void
    {
        $response = (new WebHook())($this->getRequest(method: Method::GET, uri: '/v1/api/webhook'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_queue(): void
    {
        $response = (new WebHook())($this->getWebhookRequest('req-1'));

        self::assertSame(200, $response->getStatusCode());

        $event = $this->queuedEnvelope();

        self::assertSame(ProcessWebhookEvent::NAME, $event->event);
        self::assertSame(['keep' => '1'], $event->data['get']);
        self::assertSame(['payload' => 'ok'], $event->data['post']);
        self::assertSame('', $event->data['body']);
        self::assertSame('req-1', $event->data['server']['X_REQUEST_ID']);
        self::assertArrayNotHasKey('HTTP_AUTHORIZATION', $event->data['server']);
        self::assertArrayNotHasKey('WS_API_KEY', $event->data['server']);
        self::assertArrayNotHasKey('WS_BACKENDS_FILE', $event->data['server']);
        self::assertStringNotContainsString('apikey', $event->data['server']['REQUEST_URI']);
        self::assertStringNotContainsString('ws_token', $event->data['server']['REQUEST_URI']);
        self::assertSame('keep=1', $event->data['server']['QUERY_STRING']);
        self::assertTrue($event->opts[Options::FAIL_FAST_ON_LOCK]);
        self::assertTrue($event->opts['cached']);
        self::assertArrayNotHasKey(Options::QUEUE_ONLY, $event->opts);
        self::assertSame([], $this->repo->findAll([EventsTable::COLUMN_EVENT => ProcessWebhookEvent::NAME]));
    }

    public function test_raw_queue(): void
    {
        $response = (new WebHook())($this->getWebhookRequest('req-1')->withParsedBody(null));

        self::assertSame(200, $response->getStatusCode());

        $event = $this->queuedEnvelope();

        self::assertArrayNotHasKey('post', $event->data);
        self::assertSame('raw-body', $event->data['body']);
    }

    private function queuedEnvelope(): EventEnvelope
    {
        $events = $this->transport->dequeue(10);

        self::assertCount(1, $events);

        return $events[0];
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
