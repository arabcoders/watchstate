<?php

declare(strict_types=1);

namespace Tests\API\Webhook;

use App\API\Webhook\Index;
use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Enums\Http\Method;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Logger;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Tests\Support\RequestResponseTrait;

final class IndexTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->seedTestServersConfig();

        Container::add(iClient::class, function () {
            $client = $this->createStub(iClient::class);
            $client->method('withContext')->willReturnSelf();
            $client->method('setLogger')->willReturnSelf();
            $client->method('getName')->willReturn('test_plex');
            $client->method('getType')->willReturn('plex');
            $client
                ->method('processRequest')
                ->willReturnCallback(
                    static fn(iRequest $request): iRequest => $request
                        ->withAttribute('backend', [
                            'id' => 's00000000000000000000000000000000000000p',
                            'name' => 'test_plex',
                        ])
                        ->withAttribute('user', [
                            'id' => '11111111',
                            'name' => 'main',
                        ]),
                );
            $client
                ->method('parseWebhook')
                ->willReturn(
                    StateEntity::fromArray(require TESTS_PATH . '/Fixtures/MovieEntity.php'),
                );

            return $client;
        });

        Config::save('supported.plex', iClient::class);
        Config::save('supported.jellyfin', iClient::class);
        Config::save('supported.emby', iClient::class);
    }

    public function test_cache_webhook_during_tasks(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('tasks.running', true, new \DateInterval('PT6H'));

        $handler = $this->makeHandler($cache);

        $response = $handler($this->getWebhookRequest('req-1'));

        self::assertSame(200, $response->getStatusCode());

        $events = $cache->get('events', []);

        self::assertCount(1, $events);
        self::assertSame('process_request', $events[0]['event']);
        self::assertSame('movie://121:untainted@test_plex/main', $events[0]['opts']['reference']);
        self::assertTrue($events[0]['opts']['cached']);
        self::assertTrue($events[0]['opts'][Options::FAIL_FAST_ON_LOCK]);
        self::assertSame('main', $events[0]['opts'][Options::CONTEXT_USER]);
    }

    public function test_cache_webhook_upsert(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('tasks.running', true, new \DateInterval('PT6H'));

        $handler = $this->makeHandler($cache);

        $handler($this->getWebhookRequest('req-1'));
        $handler($this->getWebhookRequest('req-2'));

        $events = $cache->get('events', []);

        self::assertCount(1, $events);
        self::assertSame('req-2', $events[0]['opts']['options'][Options::REQUEST_ID]);
    }

    private function makeHandler(CacheInterface $cache): Index
    {
        Container::add(CacheInterface::class, $cache);

        return new Index(
            $this->createStub(iImport::class),
            new Logger('test'),
            $cache,
            Container::get(\App\Libs\LogSuppressor::class),
        );
    }

    private function getWebhookRequest(string $requestId): iRequest
    {
        return $this->getRequest(
            method: Method::POST,
            uri: '/v1/api/webhook',
            server: [
                'X_REQUEST_ID' => $requestId,
            ],
        );
    }
}
