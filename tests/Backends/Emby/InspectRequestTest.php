<?php

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Emby\Action\InspectRequest;
use App\Backends\Emby\EmbyClient;
use App\Libs\TestCase;
use App\Libs\Uri;
use Nyholm\Psr7\ServerRequest;

class InspectRequestTest extends TestCase
{
    public function test_parses_payload_and_sets_attributes(): void
    {
        $payload = [
            'Server' => ['Id' => 'server-1', 'Name' => 'Emby', 'Version' => '4.8.1.0'],
            'User' => ['Id' => 'user-1', 'Name' => 'Test User'],
            'Item' => ['Id' => 'item-1', 'Type' => EmbyClient::TYPE_MOVIE],
            'Event' => 'playback.start',
        ];

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'));
        $request = $request->withParsedBody($payload);

        $context = $this->createContext();
        $action = new InspectRequest();
        $response = $action($context, $request);

        $this->assertTrue($response->isSuccessful());
        $parsed = $response->response;
        $this->assertSame('server-1', $parsed->getAttribute('backend')['id']);
        $this->assertSame('user-1', $parsed->getAttribute('user')['id']);
    }

    public function test_handles_legacy_payload_wrapper(): void
    {
        $payload = [
            'data' => [
                'Server' => ['Id' => 'server-1', 'Name' => 'Emby', 'Version' => '4.8.1.0'],
                'User' => ['Id' => 'user-1', 'Name' => 'Test User'],
                'Item' => ['Id' => 'item-1', 'Type' => EmbyClient::TYPE_MOVIE],
                'Event' => 'playback.start',
            ],
        ];

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'));
        $request = $request->withParsedBody($payload);

        $context = $this->createContext();
        $action = new InspectRequest();
        $response = $action($context, $request);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('server-1', $response->response->getAttribute('backend')['id']);
    }

    public function test_rejects_invalid_server_version(): void
    {
        $payload = [
            'Server' => ['Id' => 'server-1', 'Name' => 'Emby', 'Version' => '5.0.0.0'],
            'User' => ['Id' => 'user-1', 'Name' => 'Test User'],
            'Item' => ['Id' => 'item-1', 'Type' => EmbyClient::TYPE_MOVIE],
            'Event' => 'playback.start',
        ];

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'));
        $request = $request->withParsedBody($payload);

        $context = $this->createContext();
        $action = new InspectRequest();
        $response = $action($context, $request);

        $this->assertFalse($response->isSuccessful());
    }

    private function createContext(): \App\Backends\Common\Context
    {
        $cache = new \App\Backends\Common\Cache(new \Monolog\Logger('test'), new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $userContext = $this->createUserContext(EmbyClient::CLIENT_NAME);

        return new \App\Backends\Common\Context(
            clientName: EmbyClient::CLIENT_NAME,
            backendName: EmbyClient::CLIENT_NAME,
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: $cache,
            userContext: $userContext,
        );
    }
}
