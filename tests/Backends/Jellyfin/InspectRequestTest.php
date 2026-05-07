<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Jellyfin\Action\InspectRequest;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\TestCase;
use App\Libs\Uri;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Tests\Support\MediaBrowserContextTestSupport;

class InspectRequestTest extends TestCase
{
    use MediaBrowserContextTestSupport;

    public function test_parses_json_attrs(): void
    {
        $payload = [
            'ServerId' => 'server-1',
            'ServerName' => 'Jellyfin',
            'ServerVersion' => '10.9.0',
            'UserId' => 'user-1',
            'NotificationUsername' => 'Test User',
            'ItemId' => 'item-1',
            'ItemType' => JellyfinClient::TYPE_MOVIE,
            'NotificationType' => 'PlaybackStop',
        ];

        $request = new ServerRequest(
            'POST',
            new Uri('http://mediabrowser.test'),
            [],
            null,
            '1.1',
            ['HTTP_USER_AGENT' => 'Jellyfin-Server/10.9.0'],
        );
        $request = $request->withBody(Stream::create(json_encode($payload)));

        $context = $this->createContext(JellyfinClient::CLIENT_NAME);
        $action = new InspectRequest();
        $response = $action($context, $request);

        $this->assertTrue($response->isSuccessful());
        $parsed = $response->response;
        $this->assertSame('server-1', $parsed->getAttribute('backend')['id']);
        $this->assertSame('user-1', $parsed->getAttribute('user')['id']);
        $this->assertSame('item-1', $parsed->getAttribute('item')['id']);
    }

    public function test_rejects_non_jellyfin_ua(): void
    {
        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'));
        $request = $request->withHeader('User-Agent', 'OtherClient/1.0');

        $context = $this->createContext(JellyfinClient::CLIENT_NAME);
        $action = new InspectRequest();
        $response = $action($context, $request);

        $this->assertFalse($response->isSuccessful());
    }

}
