<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Jellyfin\Action\GetImagesUrl;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\TestCase;
use App\Libs\Uri;

class GetImagesUrlTest extends TestCase
{
    public function test_builds_jellyfin_image_urls(): void
    {
        $context = $this->createContext();
        $action = new GetImagesUrl();
        $response = $action($context, 'item-1');

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('/Items/item-1/Images/Primary/', (string) $response->response['poster']);
        $this->assertStringContainsString('/Items/item-1/Images/Backdrop/', (string) $response->response['background']);
    }

    private function createContext(): \App\Backends\Common\Context
    {
        $cache = new \App\Backends\Common\Cache(new \Monolog\Logger('test'), new \Symfony\Component\Cache\Psr16Cache(new \Symfony\Component\Cache\Adapter\ArrayAdapter()));
        $userContext = $this->createUserContext(JellyfinClient::CLIENT_NAME);

        return new \App\Backends\Common\Context(
            clientName: JellyfinClient::CLIENT_NAME,
            backendName: JellyfinClient::CLIENT_NAME,
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: $cache,
            userContext: $userContext,
        );
    }
}
