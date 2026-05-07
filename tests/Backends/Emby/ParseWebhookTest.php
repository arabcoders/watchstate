<?php

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Emby\Action\ParseWebhook;
use App\Backends\Emby\EmbyClient;
use App\Backends\Emby\EmbyGuid;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Tests\Support\MediaBrowserContextTestSupport;

class ParseWebhookTest extends TestCase
{
    use MediaBrowserContextTestSupport;

    public function test_rejects_unsupported_type(): void
    {
        $payload = [
            'Event' => 'playback.start',
            'Item' => ['Type' => 'Audio', 'Id' => 'item-1'],
        ];

        $request = (new ServerRequest('POST', new Uri('http://mediabrowser.test')))->withParsedBody($payload);
        $context = $this->createContext(EmbyClient::CLIENT_NAME);
        $logger = new Logger('test', [new NullHandler()]);

        $action = new ParseWebhook($logger);
        $response = $action($context, new EmbyGuid($logger), $request);

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(200, $response->extra['http_code']);
    }

    public function test_rejects_unsupported_event(): void
    {
        $payload = [
            'Event' => 'unknown.event',
            'Item' => ['Type' => EmbyClient::TYPE_MOVIE, 'Id' => 'item-1'],
        ];

        $request = (new ServerRequest('POST', new Uri('http://mediabrowser.test')))->withParsedBody($payload);
        $context = $this->createContext(EmbyClient::CLIENT_NAME);
        $logger = new Logger('test', [new NullHandler()]);

        $action = new ParseWebhook($logger);
        $response = $action($context, new EmbyGuid($logger), $request);

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(200, $response->extra['http_code']);
    }

}
