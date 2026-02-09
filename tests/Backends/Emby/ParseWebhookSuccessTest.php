<?php

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Emby\Action\GetMetaData;
use App\Backends\Emby\Action\ParseWebhook;
use App\Backends\Emby\EmbyClient;
use App\Backends\Emby\EmbyGuid;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\TestCase;
use App\Libs\Uri;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;

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
            return new GetMetaData($http, $this->createLogger(), new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        });

        $cache = new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter());
        $context = new \App\Backends\Common\Context(
            clientName: EmbyClient::CLIENT_NAME,
            backendName: EmbyClient::CLIENT_NAME,
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: new \App\Backends\Common\Cache($this->createLogger(), $cache),
            userContext: $this->createUserContext(EmbyClient::CLIENT_NAME),
            backendId: 'backend-1',
        );

        $request = (new ServerRequest('POST', new Uri('http://mediabrowser.test')))
            ->withParsedBody($payload['webhook_emby']);

        $action = new ParseWebhook($this->createLogger());
        $guid = (new EmbyGuid($this->createLogger()))->withContext($context);
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
