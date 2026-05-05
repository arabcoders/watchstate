<?php

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Emby\Action\GetImagesUrl;
use App\Backends\Emby\EmbyClient;
use App\Libs\TestCase;
use Tests\Support\MediaBrowserContextTestSupport;

class GetImagesUrlTest extends TestCase
{
    use MediaBrowserContextTestSupport;

    public function test_builds_emby_image_urls(): void
    {
        $context = $this->createContext(EmbyClient::CLIENT_NAME);
        $action = new GetImagesUrl();
        $response = $action($context, 'item-1');

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('/emby/Items/item-1/Images/Primary/', (string) $response->response['poster']);
        $this->assertStringContainsString('/emby/Items/item-1/Images/Backdrop/', (string) $response->response['background']);
    }

}
