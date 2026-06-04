<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Jellyfin\Action\GetImagesUrl;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\TestCase;
use Tests\Support\MediaBrowserContextTestSupport;

class GetImagesUrlTest extends TestCase
{
    use MediaBrowserContextTestSupport;

    public function test_builds_jellyfin_image_urls(): void
    {
        $context = $this->createContext(JellyfinClient::CLIENT_NAME);
        $action = new GetImagesUrl();
        $response = $action($context, 'item-1');

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('/Items/item-1/Images/Primary/', (string) $response->response['poster']);
        $this->assertStringContainsString('/Items/item-1/Images/Backdrop/', (string) $response->response['background']);
    }
}
