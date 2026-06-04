<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Tests\Support\MediaBrowserContextTestSupport;

class ParseWebhookTest extends TestCase
{
    use MediaBrowserContextTestSupport;

    public function test_rejects_unsupported_type(): void
    {
        $payload = [
            'NotificationType' => 'PlaybackStop',
            'ItemType' => 'Audio',
            'ItemId' => 'item-1',
        ];

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'))->withParsedBody($payload);
        $context = $this->createContext(JellyfinClient::CLIENT_NAME);
        $logger = new Logger('test', [new NullHandler()]);

        $action = new ParseWebhook(new Psr16Cache(new ArrayAdapter()));
        $response = $action($context, new JellyfinGuid($logger), $request);

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(200, $response->extra['http_code']);
    }

    public function test_rejects_unsupported_event(): void
    {
        $payload = [
            'NotificationType' => 'UnknownEvent',
            'ItemType' => JellyfinClient::TYPE_MOVIE,
            'ItemId' => 'item-1',
        ];

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'))->withParsedBody($payload);
        $context = $this->createContext(JellyfinClient::CLIENT_NAME);
        $logger = new Logger('test', [new NullHandler()]);

        $action = new ParseWebhook(new Psr16Cache(new ArrayAdapter()));
        $response = $action($context, new JellyfinGuid($logger), $request);

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(200, $response->extra['http_code']);
    }
}
