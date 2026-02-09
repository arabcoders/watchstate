<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Jellyfin\Action\GetMetaData;
use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\TestCase;
use App\Libs\Uri;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class ParseWebhookSuccessTest extends TestCase
{
    public function test_parses_movie_webhook_payload(): void
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/mediabrowser_data.json'),
            true,
        );

        Container::reinitialize();
        Container::add(StateInterface::class, fn() => new StateEntity([]));
        Container::add(LoggerInterface::class, fn() => $this->createLogger());
        Container::add(GetMetaData::class, function () use ($payload) {
            $http = new \App\Libs\Extends\HttpClient(
                new \App\Libs\Extends\MockHttpClient(
                    new \Symfony\Component\HttpClient\Response\MockResponse(
                        json_encode($payload['metadata']),
                        ['http_code' => 200],
                    ),
                ),
            );
            return new GetMetaData($http, $this->createLogger(), new Psr16Cache(new ArrayAdapter()));
        });

        $cache = new Psr16Cache(new ArrayAdapter());
        $context = new \App\Backends\Common\Context(
            clientName: JellyfinClient::CLIENT_NAME,
            backendName: JellyfinClient::CLIENT_NAME,
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: new \App\Backends\Common\Cache($this->createLogger(), $cache),
            userContext: $this->createUserContext(JellyfinClient::CLIENT_NAME),
            backendId: 'backend-1',
        );

        $request = (new ServerRequest('POST', new Uri('http://mediabrowser.test')))
            ->withParsedBody($payload['webhook_jellyfin']);

        $action = new ParseWebhook($cache);
        $guid = (new JellyfinGuid($this->createLogger()))->withContext($context);
        $result = $action($context, $guid, $request);

        $message = $result->error?->format() ?? ($result->extra['message'] ?? '');
        $this->assertTrue($result->isSuccessful(), $message);
        $this->assertSame('Test Movie', $result->response->title);
    }

    private function createLogger(): \Monolog\Logger
    {
        return new \Monolog\Logger('test');
    }
}
