<?php

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Emby\Action\GetImagesUrl;
use App\Backends\Emby\EmbyClient;
use App\Libs\TestCase;
use App\Libs\Uri;

class GetImagesUrlTest extends TestCase
{
    public function test_builds_emby_image_urls(): void
    {
        $context = $this->createContext();
        $action = new GetImagesUrl();
        $response = $action($context, 'item-1');

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('/emby/Items/item-1/Images/Primary/', (string) $response->response['poster']);
        $this->assertStringContainsString('/emby/Items/item-1/Images/Backdrop/', (string) $response->response['background']);
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
