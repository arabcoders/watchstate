<?php

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Emby\Action\InspectRequest;
use App\Backends\Emby\EmbyClient;
use App\Libs\TestCase;
use App\Libs\Uri;
use Nyholm\Psr7\ServerRequest;
use Tests\Support\MediaBrowserContextTestSupport;

class InspectRequestTest extends TestCase
{
    use MediaBrowserContextTestSupport;

    public function test_parses_payload_attrs(): void
    {
        $payload = [
            'Server' => ['Id' => 'server-1', 'Name' => 'Emby', 'Version' => '4.8.1.0'],
            'User' => ['Id' => 'user-1', 'Name' => 'Test User'],
            'Item' => ['Id' => 'item-1', 'Type' => EmbyClient::TYPE_MOVIE],
            'Event' => 'playback.start',
        ];

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'));
        $request = $request->withParsedBody($payload);

        $context = $this->createContext(EmbyClient::CLIENT_NAME);
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

        $context = $this->createContext(EmbyClient::CLIENT_NAME);
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

        $context = $this->createContext(EmbyClient::CLIENT_NAME);
        $action = new InspectRequest();
        $response = $action($context, $request);

        $this->assertFalse($response->isSuccessful());
    }

}
